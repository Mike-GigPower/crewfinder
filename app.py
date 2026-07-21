"""
Crew Finder App
===================================
Flask backend serving the web UI and proxying requests to SmartStaff Solutions.

Run via menubar.py or directly:
    python3 app.py
"""

import json
import os
import re
import io
import math
import types
import difflib
import threading
import functools
import time
from datetime import datetime, timedelta
from flask import Flask, jsonify, request, render_template, session, redirect, url_for, Response
from bs4 import BeautifulSoup
import requests as http

app = Flask(__name__)
app.secret_key = "crewfinder-gigpower-2026-internal"


@app.after_request
def _no_store_api(resp):
    """Never let API responses OR the app HTML be cached. /api/whoami decides the
    cohort and the /api/me/* routes return per-user data — a stale cached copy
    renders the wrong cohort's UI. The index/login HTML is included because a
    browser-cached old index.html will ignore a correct whoami response and run
    the wrong cohort's code path."""
    try:
        p = request.path
        if p.startswith("/api/") or p in ("/", "/login"):
            resp.headers["Cache-Control"] = "no-store"
    except Exception:
        pass
    return resp

# ─── PATHS ────────────────────────────────────────────────────────────────────

import sys as _sys
# When running inside a PyInstaller bundle, use the executable's directory
# When running as a script, use the script's directory
if getattr(_sys, 'frozen', False):
    BASE_DIR = os.path.dirname(_sys.executable)
else:
    BASE_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_FILE = os.path.join(BASE_DIR, "config.json")
CACHE_FILE  = os.path.join(BASE_DIR, "crew_cache.json")
VENUE_CACHE_FILE = os.path.join(BASE_DIR, "venue_cache.json")  # venue geo, rebuilt with the crew cache
# FORECAST_CACHE_FILE and UNAVAIL_CACHE_FILE removed in 3.4.5 (live reads).
UNAVAIL_TIMES_FILE         = os.path.join(BASE_DIR, "unavail_times.json")
# FORECAST_CACHE_MAX_AGE_HRS and UNAVAIL_CACHE_MAX_AGE_HRS removed in 3.4.5.
BASE_URL    = "https://smartstaffsolutions.com"
# Allow pointing at a duplicate/staging SmartStaff for testing without editing
# code: set "base_url" in config.json. Falls back to production above.
try:
    _cfg_url = (json.load(open(CONFIG_FILE)).get("base_url", "").strip()
                if os.path.exists(CONFIG_FILE) else "")
    if _cfg_url:
        BASE_URL = _cfg_url.rstrip("/")
except Exception:
    pass
CACHE_MAX_AGE_HRS = 24
IMPORT_LOG_FILE = os.path.join(BASE_DIR, "import_log.json")
TIMESHEET_TEMPLATE_FILE = os.path.join(BASE_DIR, "crew_master_template.xlsx")  # bundled; cloned per call by the generator

VALID_CALL_NAMES = {
    "Load In", "Load Out", "LX", "SX", "VX", "Backline", "Show Call",
    "FOH Spot", "Truss Spot", "Wardrobe", "Steel", "Fork", "Truck", "EWP",
    "Crown Hand", "Crew Boss", "Site", "Utility", "General", "Other"
}

# ─── CONFIG ───────────────────────────────────────────────────────────────────

def load_config():
    if os.path.exists(CONFIG_FILE):
        with open(CONFIG_FILE) as f:
            return json.load(f)
    return {}

def save_config(data):
    with open(CONFIG_FILE, "w") as f:
        json.dump(data, f, indent=2)

# Load Anthropic API key from config if not already in environment
if not os.environ.get("ANTHROPIC_API_KEY"):
    try:
        _cfg = load_config()
        _key = _cfg.get("anthropic_api_key", "").strip()
        if _key:
            os.environ["ANTHROPIC_API_KEY"] = _key
    except Exception:
        pass

# ─── SMARTSTAFF SESSION ───────────────────────────────────────────────────────

APP_VERSION    = "4.10.2"
VERSION_URL    = "https://raw.githubusercontent.com/Mike-GigPower/crewfinder/main/version.json"

# ─── CREW HUB PUSH (offer notifications) ──────────────────────────────────────
GP_PUSH_URL       = "https://crew.gigpower.com/api/push/offer"
GP_PUSH_SECRET = os.environ.get("GP_PUSH_SECRET", "") or load_config().get("gp_push_secret", "")
GP_PUSH_DEDUP_TTL = 900   # seconds; suppress a repeat push for the same crew+call

_gp_offer_notified = {}   # (crew_id, call_id) -> last-sent epoch; in-memory dedup

def gp_notify_offer(crew_id, call):
    """Fire-and-forget push to the Crew Hub when a crew member is offered a call.
    crew_id = SmartStaff internal userID (the portal resolves it to an EIN).
    Deduped per (crew_id, call_id) for GP_PUSH_DEDUP_TTL seconds. Never raises —
    the offer (and any SMS) already happened; a push must not break the loop."""
    try:
        call_id = call.get("call_id")
        key = (str(crew_id), str(call_id))
        now = time.time()
        if now - _gp_offer_notified.get(key, 0) < GP_PUSH_DEDUP_TTL:
            return
        _gp_offer_notified[key] = now
        if len(_gp_offer_notified) > 5000:                 # bound memory
            cutoff = now - GP_PUSH_DEDUP_TTL
            for k in [k for k, v in list(_gp_offer_notified.items()) if v < cutoff]:
                _gp_offer_notified.pop(k, None)
        http.post(
            GP_PUSH_URL,
            json={
                "user_id":      crew_id,
                "call_name":    call.get("call_name", ""),
                "booking_name": call.get("booking_name", ""),  # "" until Phase 2
                "venue":        call.get("venue", ""),          # "" until Phase 2
                "start":        call.get("start_dt", ""),       # "" until Phase 2
                "end":          call.get("end_dt", ""),         # duration source (Phase 2.1)
                "call_id":      call_id,
            },
            headers={"X-Push-Secret": GP_PUSH_SECRET},
            timeout=4,
        )
    except Exception:
        pass   # never let a push break the offer/SMS loop

GP_PROMOTE_URL = "https://crew.gigpower.com/api/push/promote"

_gp_promote_notified = {}   # (crew_id, call_id) -> last-sent epoch; in-memory dedup

def gp_notify_promotion(crew_id, call):
    """Fire-and-forget push to the Crew Hub when a BACKUP is promoted to confirmed
    (call_crew_map status 7 -> 5). crew_id = SmartStaff internal userID (the portal
    resolves it to an EIN). Deduped per (crew_id, call_id) for GP_PUSH_DEDUP_TTL
    seconds. Never raises — the promotion already happened; a push must not break
    the response. Uses the same secret as the offer push, posted to a separate
    /api/push/promote webhook so the portal can word it as 'you're booked'."""
    try:
        call_id = call.get("call_id")
        key = (str(crew_id), str(call_id))
        now = time.time()
        if now - _gp_promote_notified.get(key, 0) < GP_PUSH_DEDUP_TTL:
            return
        _gp_promote_notified[key] = now
        if len(_gp_promote_notified) > 5000:                 # bound memory
            cutoff = now - GP_PUSH_DEDUP_TTL
            for k in [k for k, v in list(_gp_promote_notified.items()) if v < cutoff]:
                _gp_promote_notified.pop(k, None)
        http.post(
            GP_PROMOTE_URL,
            json={
                "user_id":      crew_id,
                "call_name":    call.get("call_name", ""),
                "booking_name": call.get("booking_name", ""),
                "venue":        call.get("venue", ""),
                "call_id":      call_id,
            },
            headers={"X-Push-Secret": GP_PUSH_SECRET},
            timeout=4,
        )
    except Exception:
        pass   # never let a push break the promote response

GP_CHANGE_URL = "https://crew.gigpower.com/api/push/change"

_gp_change_notified = {}   # (crew_id, call_id) -> last-sent epoch; in-memory dedup

def gp_notify_change(crew_id, call, kind):
    """Fire-and-forget push when a CONTACTED crew member's call TIMING changes.
    crew_id = SmartStaff internal userID (the portal resolves it to an EIN).
    kind: 'reconfirm' (confirmed 5 — please re-confirm), 'standby' (backup 7 —
    heads-up), or 'info' (offered 0/1 — the offer card self-updates). Deduped per
    (crew_id, call_id) for GP_PUSH_DEDUP_TTL seconds. Never raises — the edit
    already happened; a push must not break the response. Uses the same secret as
    the offer push, posted to a separate /api/push/change webhook so the portal
    can word it by kind. The push is only a NUDGE — the card renders the full
    delta from my-shifts / my-backups."""
    try:
        call_id = call.get("call_id")
        key = (str(crew_id), str(call_id))
        now = time.time()
        if now - _gp_change_notified.get(key, 0) < GP_PUSH_DEDUP_TTL:
            return
        _gp_change_notified[key] = now
        if len(_gp_change_notified) > 5000:                 # bound memory
            cutoff = now - GP_PUSH_DEDUP_TTL
            for k in [k for k, v in list(_gp_change_notified.items()) if v < cutoff]:
                _gp_change_notified.pop(k, None)
        http.post(
            GP_CHANGE_URL,
            json={
                "user_id":   crew_id,
                "call_id":   call_id,
                "call_name": call.get("call_name", ""),
                "start":     call.get("start_dt", ""),   # NEW start
                "end":       call.get("end_dt", ""),      # NEW end
                "kind":      kind,
            },
            headers={"X-Push-Secret": GP_PUSH_SECRET},
            timeout=4,
        )
    except Exception:
        pass   # never let a push break the call-edit response

# ─── RECRUITMENT (ops applicant review) ───────────────────────────────────────
# Read-only applicant list, served by a deployed Supabase edge function. The URL
# is public (safe in source). The KEY is a secret — loaded from the gitignored
# config.json (or env), exactly like GP_PUSH_SECRET above, and never hardcoded.
RECRUITMENT_CANDIDATES_URL = "https://ihyvwhquycsxhmhulzmu.supabase.co/functions/v1/recruitment-candidates"
# Same base URL, different function: this one UPDATES an applicant's status.
RECRUITMENT_SET_STATUS_URL = "https://ihyvwhquycsxhmhulzmu.supabase.co/functions/v1/recruitment-set-status"
# Same base URL again: this one EMAILS the induction invite (via Resend) and, on
# a successful send, marks the applicant invited. set-status only relabels — it
# never emails — so the "Invite" button must use THIS route, not set-status.
RECRUITMENT_INVITE_URL = "https://ihyvwhquycsxhmhulzmu.supabase.co/functions/v1/recruitment-invite"
# Same base URL: REVIEWABLE detail for ONE candidate (the expanded panel in the
# Recruitment tab). Returns an allowlisted set of fields — deliberately NO health
# data — plus short-lived signed URLs for the headshot/licence files, so the
# browser must re-fetch on each expand to get URLs that are still valid.
RECRUITMENT_CANDIDATE_DETAIL_URL = "https://ihyvwhquycsxhmhulzmu.supabase.co/functions/v1/recruitment-candidate-detail"
# Same base URL: the SEALED health answers for ONE candidate — the single most
# sensitive thing we hold. Its proxy route is gated to the ADMIN cohort ONLY
# (never operations); the edge function returns just { reference, name, health }.
RECRUITMENT_CANDIDATE_HEALTH_URL = "https://ihyvwhquycsxhmhulzmu.supabase.co/functions/v1/recruitment-candidate-health"
# Same base URL: ADMIN-ONLY immigration detail for ONE candidate — passport/visa
# data + a signed URL for the visa PDF + the AI-suggested visa facts. Same posture
# as health: its proxy route is gated to the ADMIN cohort ONLY. The shared detail
# feed exposes only work_eligibility.status; the sensitive fields come from here.
RECRUITMENT_CANDIDATE_WORK_ELIGIBILITY_URL = "https://ihyvwhquycsxhmhulzmu.supabase.co/functions/v1/recruitment-candidate-work-eligibility"
# Same base URL: records (or clears) the "VEVO verified" compliance flag on ONE
# candidate. ADMIN-ONLY proxy route (verifying work rights requires the visa data
# that only admins can see). Writes the separate vevo_check column.
RECRUITMENT_VEVO_VERIFY_URL = "https://ihyvwhquycsxhmhulzmu.supabase.co/functions/v1/recruitment-vevo-verify"
# Same base URL: the KeyPay "complete setup" edge function. Invoked in two modes —
# "preview" (read-only: GET the EH employee, run the identity guard, return the
# before-state + computed after-state) and "commit" (re-GET, re-verify, rebuild
# the payload SERVER-SIDE, POST to KeyPay, re-GET). The Flask proxy below forwards
# ONLY {candidate_id, commencement_date, before_hash} + acting_user_id — never a
# KeyPay payload (see BRIEF-keypay-complete-setup.md §7.2). The write itself is
# gated inside the edge function by KEYPAY_WRITE_ENABLED === "true" (a Supabase
# secret), NOT here — Flask/UI gating is presentation only and is not the control.
KEYPAY_COMPLETE_SETUP_URL = "https://ihyvwhquycsxhmhulzmu.supabase.co/functions/v1/keypay-complete-setup"
# The only statuses this doorway may set — must match the edge function exactly.
RECRUITMENT_VALID_STATUSES = {"applied", "invited_to_induction", "booked", "attended", "details_submitted", "sent_to_eh", "all_docs_received", "on_hold", "not_suitable"}
GOAT_RECRUITMENT_KEY = os.environ.get("GOAT_RECRUITMENT_KEY", "") or load_config().get("goat_recruitment_key", "")

# ─── BULK ENDPOINTS (SmartStaff /ajax/crew/*) ─────────────────────────────────
# When True, the app will try the new bulk SmartStaff endpoints first and fall
# back to HTML scraping on any failure. Safe to leave True even before the
# endpoints are deployed — the fallback handles 404s transparently.
# Set to False in config.json (key: "use_bulk_endpoints": false) to force the
# legacy scraper path for A/B comparison.
USE_BULK_ENDPOINTS = True
USE_BULK_UNAVAILS_ENDPOINT = True
USE_BULK_BOOKED_CREW_ENDPOINT = True
USE_BULK_CALLS_ENDPOINT = True
USE_BULK_IMPORT_LOOKUPS = True
USE_BULK_VENUES_ENDPOINT = True
USE_CREATE_BOOKING_ENDPOINT = True
USE_BULK_BOOKING_ENDPOINT = True
try:
    if os.path.exists(CONFIG_FILE):
        _cfg = json.load(open(CONFIG_FILE))
        if "use_bulk_endpoints" in _cfg:
            USE_BULK_ENDPOINTS = bool(_cfg["use_bulk_endpoints"])
        if "use_bulk_unavails_endpoint" in _cfg:
            USE_BULK_UNAVAILS_ENDPOINT = bool(_cfg["use_bulk_unavails_endpoint"])
        if "use_bulk_booked_crew_endpoint" in _cfg:
            USE_BULK_BOOKED_CREW_ENDPOINT = bool(_cfg["use_bulk_booked_crew_endpoint"])
        if "use_bulk_calls_endpoint" in _cfg:
            USE_BULK_CALLS_ENDPOINT = bool(_cfg["use_bulk_calls_endpoint"])
        if "use_bulk_import_lookups" in _cfg:
            USE_BULK_IMPORT_LOOKUPS = bool(_cfg["use_bulk_import_lookups"])
        if "use_bulk_venues_endpoint" in _cfg:
            USE_BULK_VENUES_ENDPOINT = bool(_cfg["use_bulk_venues_endpoint"])
        if "use_create_booking_endpoint" in _cfg:
            USE_CREATE_BOOKING_ENDPOINT = bool(_cfg["use_create_booking_endpoint"])
        if "use_bulk_booking_endpoint" in _cfg:
            USE_BULK_BOOKING_ENDPOINT = bool(_cfg["use_bulk_booking_endpoint"])
except Exception:
    pass


_ss_sessions     = {}  # per-user SmartStaff sessions keyed by app session id
_ss_identity     = {}  # sid -> {user_id, ein, name, usergroupID, cohort}
_ss_creds        = {}  # sid -> {username, password} for per-session reauth
_pre_elevation   = {}  # sid -> {ss, ident, creds} stashed during admin step-up
_keepalive_thread    = None
# Auto-refresh thread removed in 3.4.5 — forecast and unavail caches no longer
# exist. The crew cache is the only persistent cache and is refreshed
# explicitly via the in-UI refresh button.

def is_ss_session_valid(ss):
    """Check if a SmartStaff session is still active by hitting a lightweight endpoint."""
    try:
        resp = ss.get(f"{BASE_URL}/dash", allow_redirects=False)
        # If redirected to login, session has expired
        if resp.status_code in (301, 302):
            location = resp.headers.get("Location", "")
            if "login" in location.lower():
                return False
        return resp.status_code == 200
    except Exception:
        return False

def reauth_ss_session(sid):
    """Re-authenticate a SmartStaff session.

    Uses the per-session credentials captured at login so an expired session
    re-auths as the SAME user. Falls back to config only if no per-session
    creds exist (legacy). This prevents a crew session from silently re-authing
    as the saved admin account."""
    creds = _ss_creds.get(sid)
    if creds and creds.get("username") and creds.get("password"):
        username = creds["username"]
        password = creds["password"]
    else:
        cfg = load_config()
        username = cfg.get("username", "")
        password = cfg.get("password", "")
    if not username or not password:
        return False
    ss, err = create_ss_session(username, password)
    if err or not ss:
        return False
    _ss_sessions[sid] = ss
    # Re-confirm identity/cohort on reauth in case it changed server-side.
    ident = fetch_whoami(ss)
    if ident:
        _ss_identity[sid] = ident
    app.logger.info(f"SmartStaff session re-authenticated for sid {sid}")
    return True

def keepalive_worker():
    """Background thread — pings SmartStaff every 8 minutes, re-auths if expired."""
    import time
    while True:
        time.sleep(480)  # 8 minutes
        for sid, ss in list(_ss_sessions.items()):
            try:
                if not is_ss_session_valid(ss):
                    app.logger.info(f"Session {sid} expired — re-authenticating...")
                    reauth_ss_session(sid)
                else:
                    app.logger.debug(f"Session {sid} keepalive OK")
            except Exception as e:
                app.logger.warning(f"Keepalive error for {sid}: {e}")

def start_keepalive():
    """Start the keepalive background thread once."""
    global _keepalive_thread
    if _keepalive_thread is None or not _keepalive_thread.is_alive():
        _keepalive_thread = threading.Thread(target=keepalive_worker, daemon=True)
        _keepalive_thread.start()
        app.logger.info("SmartStaff session keepalive started")

_calls_cache      = {}  # in-memory cache for calls list, keyed by session id
_refresh_progress          = {}
# Preload progress dicts removed in 3.4.5 — no preloads to track.

# ── Crew-cache refresh / auto-refresh ───────────────────────────────────────
# The cache rebuilds from the SmartStaff bulk endpoint (effectively one HTTP
# call), so it's cheap to refresh often. We rebuild once right after login and
# then on a fixed interval in the background, mirroring the keepalive pattern.
CACHE_AUTOREFRESH_SECONDS = 900  # 15 minutes
_cache_autorefresh_thread = None

def _do_cache_refresh(ss):
    """Full parallel crew-cache rebuild. Safe to call from any thread; no-ops
    if a refresh is already in progress (shared guard with the manual route)."""
    from concurrent.futures import ThreadPoolExecutor, as_completed
    import time

    if _refresh_progress.get("running"):
        return
    _refresh_progress["running"]   = True
    _refresh_progress["done"]      = 0
    _refresh_progress["total"]     = 0
    _refresh_progress["errors"]    = 0
    _refresh_progress["started"]   = time.time()
    _refresh_progress.pop("error", None)

    try:
        all_crew = _get_all_crew(ss)
        total = len(all_crew)
        _refresh_progress["total"] = total

        # Load existing cache so we can merge
        cache, _ = load_cache()
        new_cache = dict(cache)
        lock = threading.Lock()

        # If the bulk endpoint succeeded, every crew row already carries
        # groups, rating, and inductions — no per-crew HTTP needed.
        _bulk_lookup = {str(c["id"]): c for c in all_crew} if USE_BULK_ENDPOINTS else None

        def fetch_one(crew):
            try:
                groups, rating, inductions = _get_crew_profile(
                    ss, crew["id"], bulk_lookup=_bulk_lookup
                )
                return crew["manage_id"], {
                    "name":      crew["name"],
                    "phone":     crew.get("phone", ""),
                    "user_id":   crew.get("id", crew["manage_id"]),  # userID — operations only
                    "ein":       crew.get("ein", crew.get("id", crew["manage_id"])),  # EIN — display
                    "groups":    groups,
                    "rating":    rating,
                    "inductions": inductions,
                    "postcode":  crew.get("postcode", ""),
                }, None
            except Exception as e:
                return crew["id"], None, str(e)

        # 10 parallel workers — enough to be fast, not enough to get rate-limited.
        # When _bulk_lookup is populated, fetch_one is in-memory only and finishes
        # near-instantly; the worker pool is then just iterating a list.
        with ThreadPoolExecutor(max_workers=10) as executor:
            futures = {executor.submit(fetch_one, c): c for c in all_crew}
            for future in as_completed(futures):
                cid, data, err = future.result()
                with lock:
                    if data:
                        new_cache[cid] = data
                    else:
                        _refresh_progress["errors"] += 1
                    _refresh_progress["done"] += 1
                    # Save incrementally every 50 crew
                    if _refresh_progress["done"] % 50 == 0:
                        save_cache(new_cache)

        save_cache(new_cache)

        # Refresh the venue geo cache on the same cadence (cheap single call;
        # never fails the crew rebuild).
        try:
            build_venue_cache(ss)
        except Exception as e:
            app.logger.warning(f"[venue-cache] build error: {e}")

        _refresh_progress["elapsed"] = round(time.time() - _refresh_progress["started"], 1)

    except Exception as e:
        _refresh_progress["error"] = str(e)
    finally:
        _refresh_progress["running"] = False

def trigger_cache_refresh(ss):
    """Spawn a background cache refresh. Returns False if one is already running."""
    if _refresh_progress.get("running"):
        return False
    threading.Thread(target=_do_cache_refresh, args=(ss,), daemon=True).start()
    return True

def cache_autorefresh_worker():
    """Background thread — rebuilds the crew cache every CACHE_AUTOREFRESH_SECONDS
    using any live SmartStaff session. Skips cycles when nobody is logged in."""
    import time
    while True:
        time.sleep(CACHE_AUTOREFRESH_SECONDS)
        ss = next(iter(_ss_sessions.values()), None)
        if not ss:
            continue  # nobody logged in — nothing to refresh against
        try:
            if trigger_cache_refresh(ss):
                app.logger.info("Crew cache auto-refresh started")
        except Exception as e:
            app.logger.warning(f"Cache auto-refresh error: {e}")

def start_cache_autorefresh():
    """Start the cache auto-refresh thread once."""
    global _cache_autorefresh_thread
    if _cache_autorefresh_thread is None or not _cache_autorefresh_thread.is_alive():
        _cache_autorefresh_thread = threading.Thread(target=cache_autorefresh_worker, daemon=True)
        _cache_autorefresh_thread.start()
        app.logger.info(f"Crew cache auto-refresh started (every {CACHE_AUTOREFRESH_SECONDS}s)")

def get_ss_session():
    """Get SmartStaff session for current user. Auto re-auths if session expired."""
    sid = session.get("sid")
    if not sid:
        return None
    ss = _ss_sessions.get(sid)
    if not ss:
        return None
    # Quick validity check — if session has expired, try to re-auth silently
    if not is_ss_session_valid(ss):
        app.logger.info(f"Session {sid} detected as expired in get_ss_session — re-authing")
        if not reauth_ss_session(sid):
            return None
        ss = _ss_sessions.get(sid)
    return ss

# ─── IDENTITY & ROLE (cohort) ─────────────────────────────────────────────────
# Identity + cohort are captured at login from SmartStaff's whoami.php and held
# server-side keyed by session id. The cohort is the single source of truth for
# all role gating — it is NEVER read from anything the client sends.
#   admin                 -> usergroupID==1 login: full access
#   leadership/operations -> read-only across all-crew views; no Crew Finder /
#                            Import / writes. Identical access IN THE GOAT; the
#                            Gig Power website maps them to different privileges.
#   crew                  -> own self views only
#
# Cohort classes — the single place that encodes "operations is leadership-
# equivalent in THE GOAT". Add a future leadership-class cohort here once and
# every gate and branch below picks it up.
LEADERSHIP_COHORTS = ("leadership", "operations")     # leadership-equivalent here
READ_ALL_COHORTS   = ("admin",) + LEADERSHIP_COHORTS  # may read all-crew data
KNOWN_COHORTS      = READ_ALL_COHORTS + ("crew",)     # every recognised value

def fetch_whoami(ss, retries=2):
    """Ask SmartStaff who the logged-in user is. Returns an identity dict with a
    valid cohort, or None on failure.

    A response is accepted ONLY if it is HTTP 200 AND carries a recognised
    cohort. A 401/error body or a missing cohort is a FAILURE — not a silent
    'crew'. (The old code called resp.json() without checking status, so a
    login-time 401 'Not logged in' — valid JSON, no cohort — was coerced to
    'crew', silently dropping Leadership/Admin users to the crew view.)

    Retries a few times because at login the whoami call can fire a beat before
    SmartStaff fully recognises the freshly-authenticated session."""
    import time as _time
    last = "no attempt"
    for attempt in range(retries + 1):
        try:
            resp = ss.get(f"{BASE_URL}/ajax/crew/whoami.php",
                          allow_redirects=True, timeout=10)
            if resp.status_code != 200:
                last = f"HTTP {resp.status_code}: {(resp.text or '')[:120]!r}"
            else:
                try:
                    data = resp.json()
                except Exception as je:
                    data, last = None, f"non-JSON body: {(resp.text or '')[:120]!r}"
                if isinstance(data, dict):
                    c = str(data.get("cohort", "")).strip().lower()
                    if c in KNOWN_COHORTS:
                        data["cohort"] = c
                        return data
                    last = f"no valid cohort in 200 body: {data!r}"
        except Exception as e:
            last = f"request error: {e}"
        if attempt < retries:
            _time.sleep(0.4)
    app.logger.warning(f"whoami lookup failed after {retries + 1} tries: {last}")
    return None

def current_identity():
    """Identity dict for the current request's session, or None."""
    sid = session.get("sid")
    return _ss_identity.get(sid) if sid else None

def current_cohort():
    """Cohort string for the current session. Defaults to 'crew' (least
    privilege) if identity wasn't captured for any reason."""
    ident = current_identity()
    return (ident or {}).get("cohort", "crew")

def require_cohort(*allowed):
    """Route guard: 401 if not logged in, 403 if the session's cohort isn't in
    `allowed`. Server-side only — does not trust any client-supplied role."""
    def deco(fn):
        @functools.wraps(fn)
        def wrapper(*args, **kwargs):
            if not session.get("sid") or not get_ss_session():
                return jsonify({"error": "Not logged in"}), 401
            if current_cohort() not in allowed:
                return jsonify({"error": "Forbidden for your access level"}), 403
            return fn(*args, **kwargs)
        return wrapper
    return deco


def create_ss_session(username, password):
    """Login to SmartStaff and return a session object."""
    ss = http.Session()
    ss.headers.update({"User-Agent": "Mozilla/5.0"})
    # Set a 30s timeout on all requests via a custom adapter
    from requests.adapters import HTTPAdapter
    adapter = HTTPAdapter()
    ss.mount("https://", adapter)
    ss.mount("http://", adapter)
    # Store timeout as session attribute for use in requests
    ss._default_timeout = 30
    # Monkey-patch get/post to always use timeout
    _orig_get  = ss.get
    _orig_post = ss.post
    ss.get  = lambda url, **kw: _orig_get(url,  timeout=kw.pop("timeout", 10), **kw)
    ss.post = lambda url, **kw: _orig_post(url, timeout=kw.pop("timeout", 10), **kw)
    resp = ss.post(f"{BASE_URL}/login", data={
        "action": "login",
        "username": username,
        "password": password,
        "x": "54",
        "y": "22"
    }, allow_redirects=True)
    # Check we landed on the dashboard not back on login
    if resp.url.rstrip("/").endswith("login"):
        return None, "Invalid credentials"
    return ss, None

# ─── UNAVAILABILITY WRITE/READ (impersonated) ──────────────────────────────────
# SmartStaff stores unavailabilities in the `calendars` table (type=1), keyed to
# the *logged-in* user. To act on a specific crew member, an admin session must
# "acquire" that crew member's identity (/aquire-id/<userID>), perform the
# calendar action, then release. We do this on a DEDICATED throwaway admin
# session so the operator's interactive session is never re-identified.
#
# Endpoints (all keyed on $_SESSION userID, i.e. the acquired crew member):
#   GET /aquire-id/<uid>                         -> become crew member (admin only)
#   GET /release-id                              -> revert to admin
#   GET /ajax/calendar/add-event.php?...&type=1  -> create unavailability
#   GET /ajax/calendar/delete-event.php?id=<id>  -> delete by calendars.id
#   GET /ajax/calendar/get-unavailabilities.php  -> list {id,title,start,end} (new endpoint)

_unavail_write_lock = threading.Lock()

def _make_admin_ss():
    """Create a fresh throwaway SmartStaff session logged in with the saved
    admin credentials. Returns (ss, error)."""
    cfg = load_config()
    username = cfg.get("username", "")
    password = cfg.get("password", "")
    if not username or not password:
        return None, "No saved SmartStaff credentials"
    return create_ss_session(username, password)

def _impersonate(ss, crew_id):
    """Acquire a crew member's identity on the given session. Returns error str
    or None on success."""
    try:
        resp = ss.get(f"{BASE_URL}/aquire-id/{int(crew_id)}", allow_redirects=True)
    except Exception as e:
        return f"Acquire failed: {e}"
    body = (resp.text or "")[:200].lower()
    # login.php rejects non-admins with "Nice try..." and bad uids with "Error:"
    if "nice try" in body:
        return "Saved SmartStaff login is not an admin account"
    if body.startswith("error"):
        return f"Acquire rejected: {resp.text[:120]}"
    return None

def _release(ss):
    """Revert impersonation. Best-effort; the session is discarded anyway."""
    try:
        ss.get(f"{BASE_URL}/release-id", allow_redirects=True)
    except Exception:
        pass

def _in_impersonated_session(crew_id):
    """Context-manager-like helper: returns (ss, error). Caller MUST call
    _release(ss) when done. Use via the with_impersonation() wrapper below."""
    ss, err = _make_admin_ss()
    if err:
        return None, err
    err = _impersonate(ss, crew_id)
    if err:
        _release(ss)
        return None, err
    return ss, None

def add_unavailability(crew_id, start_date, start_hour, start_min,
                       end_date, end_hour, end_min, reason):
    """Create an unavailability for a crew member in SmartStaff.
    Dates are 'YYYY-MM-DD'; hours/mins are ints. Returns (ok, error)."""
    from urllib.parse import quote
    with _unavail_write_lock:
        ss, err = _in_impersonated_session(crew_id)
        if err:
            return False, err
        try:
            url = (f"{BASE_URL}/ajax/calendar/add-event.php"
                   f"?start_date={start_date}&start_hour={int(start_hour)}"
                   f"&start_min={int(start_min)}&end_date={end_date}"
                   f"&end_hour={int(end_hour)}&end_min={int(end_min)}"
                   f"&title={quote(reason)}&type=1")
            resp = ss.get(url, allow_redirects=True)
            body = (resp.text or "").strip()
            # add-event.php returns empty on success, "ERROR: ..." on failure
            if body.upper().startswith("ERROR"):
                return False, body
            return True, None
        except Exception as e:
            return False, str(e)
        finally:
            _release(ss)

def delete_unavailability(crew_id, event_id):
    """Delete an unavailability by its calendars.id. delete-event.php scopes the
    DELETE to the acquired user, so impersonation also enforces ownership.
    Returns (ok, error)."""
    with _unavail_write_lock:
        ss, err = _in_impersonated_session(crew_id)
        if err:
            return False, err
        try:
            url = f"{BASE_URL}/ajax/calendar/delete-event.php?id={int(event_id)}"
            resp = ss.get(url, allow_redirects=True)
            body = (resp.text or "").strip()
            if body.upper().startswith("ERROR"):
                return False, body
            return True, None
        except Exception as e:
            return False, str(e)
        finally:
            _release(ss)

def fetch_unavailabilities(crew_id):
    """Read a crew member's unavailability periods (with ids + real times) from
    the new get-unavailabilities.php endpoint, via brief impersonation.
    Returns (list_of_{id,start,end,reason}, error)."""
    with _unavail_write_lock:
        ss, err = _in_impersonated_session(crew_id)
        if err:
            return None, err
        try:
            resp = ss.get(f"{BASE_URL}/ajax/calendar/get-unavailabilities.php",
                          allow_redirects=True)
            data = json.loads(resp.text or "[]")
            out = []
            for ev in data:
                out.append({
                    "id":     ev.get("id"),
                    "start":  ev.get("start"),
                    "end":    ev.get("end"),
                    "reason": ev.get("title", "") or "Unavailable",
                })
            return out, None
        except Exception as e:
            return None, str(e)
        finally:
            _release(ss)


def fetch_crew_inductions(crew_id):
    """Read a crew member's full induction venue list + status on the operator's
    behalf, via brief impersonation of that crew member's SmartStaff session.
    my-induction-venues.php self-scopes to the acquired session user, so the
    impersonated GET returns exactly that crew member's inductions.
    Returns (venues_list, error)."""
    with _unavail_write_lock:                      # serialise impersonation ops
        ss, err = _in_impersonated_session(crew_id)
        if err:
            return None, err
        try:
            resp = ss.get(f"{BASE_URL}/ajax/crew/my-induction-venues.php",
                          allow_redirects=True)
            data = json.loads(resp.text or "{}")
            if isinstance(data, dict) and data.get("error"):
                return None, data["error"]
            return data.get("venues", []), None
        except Exception as e:
            return None, str(e)
        finally:
            _release(ss)


def add_crew_induction(crew_id, venue_ids, complete_date, cert):
    """Upload an induction certificate on a crew member's behalf, fanning the one
    PDF across one or more venues, via impersonation. `cert` is the uploaded
    FileStorage; `venue_ids` is a comma-separated string. add-my-induction.php
    self-scopes to the acquired session user. Returns (result_dict, error)."""
    with _unavail_write_lock:                      # serialise impersonated writes
        ss, err = _in_impersonated_session(crew_id)
        if err:
            return None, err
        try:
            pdf_bytes = cert.read()
            files = {"certificate": (cert.filename or "certificate.pdf",
                                     pdf_bytes, "application/pdf")}
            data = {"confirmation": "1",
                    "complete_date": complete_date,
                    "venue_ids": venue_ids}
            resp = ss.post(f"{BASE_URL}/ajax/crew/add-my-induction.php",
                           data=data, files=files, allow_redirects=True)
            try:
                out = json.loads(resp.text or "{}")
            except Exception:
                return None, f"HTTP {resp.status_code}: {(resp.text or '')[:200]}"
            if isinstance(out, dict) and out.get("error"):
                return None, out["error"]
            return out, None
        except Exception as e:
            return None, str(e)
        finally:
            _release(ss)

# ─── CACHE ────────────────────────────────────────────────────────────────────

# ─── SELF-SCOPED HELPERS (crew acting on their own data) ──────────────────────
# These run on the caller's OWN SmartStaff session — no impersonation. SmartStaff
# keys add-event/delete-event/get-unavailabilities on $_SESSION userID, so a crew
# member can only ever touch their own calendar, and delete-event scopes the
# DELETE to the logged-in user (ownership enforced server-side).

def fetch_own_unavailabilities(ss):
    """Read the logged-in user's own unavailability periods."""
    try:
        resp = ss.get(f"{BASE_URL}/ajax/calendar/get-unavailabilities.php",
                      allow_redirects=True)
        data = json.loads(resp.text or "[]")
        out = []
        for ev in data:
            out.append({
                "id":     ev.get("id"),
                "start":  ev.get("start"),
                "end":    ev.get("end"),
                "reason": ev.get("title", "") or "Unavailable",
            })
        return out, None
    except Exception as e:
        return None, str(e)

def add_own_unavailability(ss, start_date, start_hour, start_min,
                           end_date, end_hour, end_min, reason):
    """Create an unavailability on the logged-in user's own calendar."""
    from urllib.parse import quote
    try:
        url = (f"{BASE_URL}/ajax/calendar/add-event.php"
               f"?start_date={start_date}&start_hour={int(start_hour)}"
               f"&start_min={int(start_min)}&end_date={end_date}"
               f"&end_hour={int(end_hour)}&end_min={int(end_min)}"
               f"&title={quote(reason)}&type=1")
        resp = ss.get(url, allow_redirects=True)
        body = (resp.text or "").strip()
        if body.upper().startswith("ERROR"):
            return False, body
        return True, None
    except Exception as e:
        return False, str(e)

def delete_own_unavailability(ss, event_id):
    """Delete one of the logged-in user's own unavailabilities by calendars.id.
    delete-event.php scopes the DELETE to the logged-in user, so a crew member
    cannot delete anyone else's event even by guessing an id."""
    try:
        url = f"{BASE_URL}/ajax/calendar/delete-event.php?id={int(event_id)}"
        resp = ss.get(url, allow_redirects=True)
        body = (resp.text or "").strip()
        if body.upper().startswith("ERROR"):
            return False, body
        return True, None
    except Exception as e:
        return False, str(e)

def _compute_induction_status(inductions):
    """Given a {venue: {status, completed}} dict, return {venue: {status,
    completed[, expiry]}} with Expired / Expiring Soon / Complete computed from
    the completion date and the venue's expiry policy. Shared by the admin
    Induction Checker and the crew self-view so they can never diverge."""
    venue_status = {}
    for venue_name, ind_data in (inductions or {}).items():
        if venue_name.strip().lower() in INDUCTION_EXCLUDE:
            continue
        if isinstance(ind_data, str):
            status, completed = ind_data, ""
        else:
            status    = ind_data.get("status", "")
            completed = ind_data.get("completed", "")
        if status == "Incomplete" or not completed:
            venue_status[venue_name] = {"status": "Incomplete", "completed": ""}
        else:
            try:
                completed_dt = datetime.strptime(completed, "%d %b %Y")
                days   = 730 if venue_name.lower() in INDUCTION_24_MONTH else 365
                expiry = completed_dt + timedelta(days=days)
                now    = datetime.now()
                if now > expiry:
                    st = "Expired"
                elif (expiry - now).days <= 14:
                    st = "Expiring Soon"
                else:
                    st = "Complete"
                venue_status[venue_name] = {"status": st, "completed": completed,
                                            "expiry": expiry.strftime("%d %b %Y")}
            except Exception:
                venue_status[venue_name] = {"status": status, "completed": completed}
    return venue_status

def load_cache():
    if not os.path.exists(CACHE_FILE):
        return {}, False
    try:
        with open(CACHE_FILE) as f:
            data = json.load(f)
        saved_at  = datetime.fromisoformat(data.get("saved_at", "2000-01-01"))
        age_hours = (datetime.now() - saved_at).total_seconds() / 3600
        return data.get("crew", {}), age_hours < CACHE_MAX_AGE_HRS
    except Exception:
        return {}, False

def save_cache(crew_profiles):
    with open(CACHE_FILE, "w") as f:
        json.dump({"saved_at": datetime.now().isoformat(), "crew": crew_profiles}, f)

# Forecast and unavail disk caches removed in 3.4.5. Both are now sub-second
# live reads via _get_shifts_for_window and _get_unavails_for_window.

# ── Option X: time sidecar ───────────────────────────────────────────────────
# The 4-hourly bulk rebuild scrapes the admin Unavailabilities tab, which is
# DATE-ONLY (it flattens every period to 00:00–23:59). That would wipe the
# real hours for partial-day entries (e.g. a uni student free in the afternoon).
# To preserve hour granularity, we keep a sidecar of time-rich periods sourced
# from get-unavailabilities.php (which returns real start/end + id), and
# re-overlay those times onto the date-only cache after each bulk rebuild.
# Match key: crew_id + calendar date + reason.

def load_unavail_times():
    if not os.path.exists(UNAVAIL_TIMES_FILE): return {}
    try:
        with open(UNAVAIL_TIMES_FILE) as f: return json.load(f).get("times", {})
    except: return {}

def save_unavail_times(times):
    try:
        with open(UNAVAIL_TIMES_FILE,"w") as f:
            json.dump({"saved_at": datetime.now().isoformat(), "times": times}, f)
    except: pass

def _unavail_match_key(start_iso, reason):
    """Date (YYYY-MM-DD) + reason — used to match a date-only scraped entry to a
    time-rich sidecar entry for the same period."""
    try:
        d = datetime.fromisoformat(start_iso).date().isoformat()
    except Exception:
        d = str(start_iso)[:10]
    return f"{d}|{(reason or '').strip().lower()}"

def overlay_unavail_times(cache):
    """Given a date-only unavail cache {crew_id: [{start,end,reason}]}, overlay
    real times + ids from the sidecar wherever a period matches by date+reason.
    Returns the same dict, mutated. Sidecar entries whose date is still in range
    but missing from the scrape are also kept (write may not have hit the admin
    tab yet)."""
    sidecar = load_unavail_times()
    today = datetime.now().replace(hour=0, minute=0, second=0, microsecond=0)
    for cid, periods in list(cache.items()):
        sc = sidecar.get(str(cid))
        if not sc:
            continue
        sc_by_key = {_unavail_match_key(s["start"], s.get("reason","")): s for s in sc}
        seen = set()
        for p in periods:
            k = _unavail_match_key(p.get("start",""), p.get("reason",""))
            if k in sc_by_key:
                p["start"] = sc_by_key[k]["start"]
                p["end"]   = sc_by_key[k]["end"]
                if sc_by_key[k].get("id") is not None:
                    p["id"] = sc_by_key[k]["id"]
                seen.add(k)
        # Re-add any future sidecar periods the scrape didn't include yet
        for k, s in sc_by_key.items():
            if k in seen:
                continue
            try:
                if datetime.fromisoformat(s["end"]).replace(tzinfo=None) < today:
                    continue
            except Exception:
                pass
            cache[cid].append(dict(s))
    return cache

def _resolve_crew_keys(crew_id):
    """Given a userID (what the unavailability endpoints use), return the set of
    cache keys this crew is stored under. The crew cache is keyed by manage_id,
    but different readers look up by user_id vs manage_id, and the two are
    usually-but-not-always equal. We key under every variant we can find so the
    single-crew refresh is visible to forecast AND availability regardless."""
    keys = {str(crew_id)}
    try:
        cache, _ = load_cache()
        for mgr_id, info in cache.items():
            if str(info.get("user_id", "")) == str(crew_id) or str(mgr_id) == str(crew_id):
                keys.add(str(mgr_id))
                if info.get("user_id"):
                    keys.add(str(info["user_id"]))
    except Exception:
        pass
    return keys

def refresh_crew_unavail_cache(crew_id):
    """After a modal write, refresh the time-sidecar for this crew so the next
    live read can overlay correct hours. In 3.4.5 the unavail cache itself was
    removed (live reads are sub-second via the bulk endpoint), so this function
    used to also write to unavail_cache.json — that's no longer needed. The
    sidecar still earns its place: between a write and the next live read,
    `overlay_unavail_times` uses it to ensure any in-flight forecast queries
    see the new entry's correct hours."""
    periods, err = fetch_unavailabilities(crew_id)
    if err:
        return False, err
    today = datetime.now().replace(hour=0, minute=0, second=0, microsecond=0)
    entries = []
    for p in periods:
        try:
            end_dt = datetime.fromisoformat(p["end"])
        except Exception:
            continue
        end_naive = end_dt.replace(tzinfo=None)
        if end_naive < today:
            continue
        entries.append({
            "id":     p.get("id"),
            "start":  datetime.fromisoformat(p["start"]).replace(tzinfo=None).isoformat(),
            "end":    end_naive.isoformat(),
            "reason": p.get("reason", "Unavailable"),
        })
    # Update the time sidecar under every key this crew is known by
    keys = _resolve_crew_keys(crew_id)
    sidecar = load_unavail_times()
    for k in keys:
        sidecar[k] = [dict(e) for e in entries]
    save_unavail_times(sidecar)
    return True, None

# ─── SMARTSTAFF SCRAPERS ──────────────────────────────────────────────────────

VENUE_MAP = {
    "Marvel":         "Marvel",
    "Rod Laver":      "RLA",
    "Margaret Court": "MCA",
    "Festival Hall":  "Festival Hall",
    "John Cain":      "JCA",
    "Hamer Hall":     "Hamer Hall",
    "Palais":         "Palais",
    "Melbourne Exh":  "MCEC",
    "MCEC":           "MCEC",
    "Melbourne Con":  "MCEC",
    "AAMI":           "AAMI",
    "Sidney Myer":    "Sidney Myer",
    "Federation":     "Federation Square",
    "Crown":          "Crown",
    "Docklands":      "Docklands",
    "Hanging Rock":   "Hanging Rock",
    "GMHBA":          "GMHBA",
    "Mt Duneed":      "Mt Duneed",
    "Centrepiece":    "Centrepiece",
    "MOPT":           "MOPT",
    "Royal Botanic":  "Royal Botanic",
    "Forum":          "Forum",
    "MCG":            "MCG",
}

# Maps venue code → keywords that appear in induction venue names on SmartStaff
# Used to match call venue code to an induction row
INDUCTION_VENUE_MAP = {
    "RLA":               ["rod laver arena"],
    "MCA":               ["margaret court arena"],
    "Marvel":            ["marvel stadium"],
    "Festival Hall":     ["festival hall"],
    "JCA":               ["john cain arena"],
    "Hamer Hall":        ["hamer hall"],
    "Palais":            ["palais theatre"],
    "MCEC":              ["melbourne convention & exhibition centre", "melbourne exhibtion centre", "jeffs shed"],
    "AAMI":              ["aami park"],
    "Sidney Myer":       ["sidney myer music bowl"],
    "Federation Square": ["federation square"],
    "Crown":             ["crown melbourne - palms", "crown melbourne"],
    "Docklands":         ["docklands studios"],
    "Hanging Rock":      ["hanging rock reserve"],
    "GMHBA":             ["gmhba stadium"],
    "Mt Duneed":         ["mt duneed estate"],
    "Centrepiece":       ["centrepiece"],
    "MOPT":              ["mopt catwalk"],
    "Royal Botanic":     ["royal botanic gardens"],
    "Forum":             ["forum melbourne"],
    "MCG":               ["mcg - gate 7", "mcg"],
}

# Venues with 24-month induction validity (all others are 12 months)
# Matches against lowercase venue name from SmartStaff
INDUCTION_24_MONTH = {"crown melbourne - palms", "crown melbourne"}

# Induction labels THE GOAT no longer monitors. SmartStaff still stores them
# per-crew; we strip them at ingestion so they never reach the cache, the
# Induction Checker, the Crew Finder, or the GOAT assistant, and also guard the
# status computation for the one path that reads live (uncached) data. This is
# "stop monitoring", not "delete" — the SmartStaff records are untouched.
# Compared case-insensitively after trimming.
INDUCTION_EXCLUDE = {"vaccination status"}

def _filter_inductions(inductions):
    """Drop INDUCTION_EXCLUDE labels from a {venue: {...}} inductions dict."""
    return {k: v for k, v in (inductions or {}).items()
            if k.strip().lower() not in INDUCTION_EXCLUDE}

def _induction_code_for_venue_name(venue_name):
    """Reverse INDUCTION_VENUE_MAP: a venue NAME ('Rod Laver Arena') -> its code
    ('RLA'). None if the venue isn't in the induction map."""
    vl = (venue_name or "").lower()
    for code, kws in INDUCTION_VENUE_MAP.items():
        if any(k in vl for k in kws):
            return code
    return None

def _resolve_induction_venue_query(q):
    """A venue filter ('RLA', 'rod laver', 'Rod Laver Arena', 'Marvel') ->
    (code, keywords). (None, []) if unrecognised."""
    ql = (q or "").strip().lower()
    if not ql:
        return None, []
    for code, kws in INDUCTION_VENUE_MAP.items():
        if code.lower() == ql:
            return code, kws
    for code, kws in INDUCTION_VENUE_MAP.items():
        if ql in code.lower() or any(ql in k or k in ql for k in kws):
            return code, kws
    return None, []

# ─── GEO / POSTCODE RADIUS SEARCH ─────────────────────────────────────────────
# Crew home postcodes (from the users table, via list-crew-bulk.php) and venue
# locations are resolved to lat/lon via a bundled AU postcode-centroid table
# (au_postcodes.json). Distances are straight-line (Haversine). Postcode-level
# accuracy by design — see BACKLOG-geo-search.md.

POSTCODE_FILE = os.path.join(BASE_DIR, "au_postcodes.json")
_postcodes = None

def _load_postcodes():
    global _postcodes
    if _postcodes is None:
        try:
            with open(POSTCODE_FILE) as f:
                _postcodes = json.load(f)
        except Exception:
            _postcodes = {}
    return _postcodes

def postcode_to_coords(pc):
    """'3000' -> {'lat','lon','suburb','state'} or None."""
    if not pc:
        return None
    pc = str(pc).strip().zfill(4)
    return _load_postcodes().get(pc)

def haversine_km(lat1, lon1, lat2, lon2):
    R = 6371.0
    p1, p2 = math.radians(lat1), math.radians(lat2)
    dp = math.radians(lat2 - lat1)
    dl = math.radians(lon2 - lon1)
    a = math.sin(dp/2)**2 + math.cos(p1)*math.cos(p2)*math.sin(dl/2)**2
    return R * 2 * math.atan2(math.sqrt(a), math.sqrt(1-a))

# Venue code -> postcode, resolved through the same centroid table as crew.
# VERIFY the flagged rows before relying on them in production.
VENUE_POSTCODES = {
    "RLA":               "3000",   # Melbourne Park precinct — VERIFY
    "MCA":               "3000",   # Melbourne Park precinct — VERIFY
    "JCA":               "3000",   # Melbourne Park precinct — VERIFY
    "AAMI":              "3000",   # AAMI Park — VERIFY
    "Centrepiece":       "3000",   # Melbourne Park precinct — VERIFY
    "MOPT":              "3000",   # Melbourne Park precinct — VERIFY
    "Marvel":            "3008",   # Docklands
    "Docklands":         "3008",   # Docklands Studios
    "MCEC":              "3006",   # South Wharf
    "Hamer Hall":        "3006",   # Southbank
    "Crown":             "3006",   # Southbank
    "Palais":            "3182",   # St Kilda
    "Festival Hall":     "3003",   # West Melbourne — VERIFY
    "Federation Square": "3000",   # Melbourne CBD
    "Forum":             "3000",   # Melbourne CBD
    "Sidney Myer":       "3004",   # Kings Domain — VERIFY
    "Royal Botanic":     "3004",   # RBG — VERIFY
    "MCG":               "3002",   # Yarra Park — VERIFY
    "Hanging Rock":      "3442",   # Newham — VERIFY
    "GMHBA":             "3220",   # Geelong — VERIFY
    "Mt Duneed":         "3217",   # Mount Duneed — VERIFY
}

def fetch_venues_bulk(ss):
    """Bulk fetch every active venue with its geo fields via
    list-venues-bulk.php. Returns (venues, error):
        venues : [{id(str), name, postcode, suburb, state, has_induction}]
        error  : str or None
    """
    url = f"{BASE_URL}/ajax/crew/list-venues-bulk.php"
    try:
        resp = ss.get(url, allow_redirects=True, timeout=30)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        return None, f"HTTP {resp.status_code}"
    try:
        data = json.loads(resp.text or "{}")
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return None, data["error"]
    out = []
    for v in data.get("venues", []):
        out.append({
            "id":            str(v.get("id")),
            "name":          v.get("name", "") or "",
            "postcode":      str(v.get("postcode") or "").strip(),
            "suburb":        (v.get("suburb") or "").strip(),
            "state":         (v.get("state") or "").strip(),
            "has_induction": int(v.get("has_induction") or 0),
        })
    return out, None


# ── Venue geo cache ──────────────────────────────────────────────────────────
# Real venue postcode/suburb/state from SmartStaff (list-venues-bulk.php),
# name-keyed, rebuilt alongside the crew cache. Replaces the hand-maintained
# VENUE_POSTCODES table (kept below only as a fallback during transition).

_venue_cache = None  # in-memory {normalised_name: venue_record}

def _norm_venue_key(s):
    """Normalise a venue name for exact-key lookup. The call's venue string from
    get-calls-bulk is the literal venues.venue value, so normalised exact match
    is deterministic — no fuzzy matching needed."""
    return re.sub(r"\s+", " ", (s or "").strip().lower())

def _load_venue_cache():
    """{normalised_name: {name, postcode, suburb, state, has_induction, id}}."""
    global _venue_cache
    if _venue_cache is None:
        try:
            with open(VENUE_CACHE_FILE) as f:
                _venue_cache = json.load(f).get("venues", {})
        except Exception:
            _venue_cache = {}
    return _venue_cache

def _save_venue_cache(venues_by_name):
    global _venue_cache
    _venue_cache = venues_by_name
    try:
        with open(VENUE_CACHE_FILE, "w") as f:
            json.dump({"saved_at": datetime.now().isoformat(), "venues": venues_by_name}, f)
    except Exception as e:
        app.logger.warning(f"[venue-cache] save failed: {e}")

def build_venue_cache(ss):
    """Fetch all venues via list-venues-bulk.php and persist a name-keyed geo
    cache (venue_cache.json). Called on the crew-cache rebuild. On endpoint
    failure it keeps the existing cache rather than wiping it."""
    if not (USE_BULK_ENDPOINTS and USE_BULK_VENUES_ENDPOINT):
        return
    venues, err = fetch_venues_bulk(ss)
    if err is not None or venues is None:
        app.logger.warning(f"[venue-cache] endpoint failed ({err}); keeping existing cache")
        return
    by_name = {}
    for v in venues:
        key = _norm_venue_key(v.get("name"))
        if key:
            by_name[key] = v   # last-write-wins on duplicate names (ad-hoc venues)
    _save_venue_cache(by_name)
    app.logger.info(f"[venue-cache] built {len(by_name)} venues")

# ── Suburb -> centroid reverse index (phase 2) ───────────────────────────────
# Built from the bundled au_postcodes.json (postcode -> {lat,lon,suburb,state}):
# group by (suburb, state) and average the postcode centroids. Lets venues with
# a blank postcode but a real suburb resolve too.

_suburb_index = None

_STATE_ALIASES = {
    "vic": "VIC", "victoria": "VIC",
    "nsw": "NSW", "new south wales": "NSW",
    "qld": "QLD", "queensland": "QLD",
    "sa":  "SA",  "south australia": "SA",
    "wa":  "WA",  "western australia": "WA",
    "tas": "TAS", "tasmania": "TAS",
    "nt":  "NT",  "northern territory": "NT",
    "act": "ACT", "australian capital territory": "ACT",
}

def _norm_state(s):
    s = (s or "").strip().lower()
    return _STATE_ALIASES.get(s, s.upper())

def _norm_suburb(s):
    return re.sub(r"\s+", " ", (s or "").strip().lower())

def _build_suburb_index():
    """{(suburb, state): {'lat','lon'}} — centroid per suburb, averaging the
    centroids of every postcode that lists that suburb+state."""
    global _suburb_index
    if _suburb_index is not None:
        return _suburb_index
    acc = {}  # key -> [sum_lat, sum_lon, count]
    for rec in _load_postcodes().values():
        try:
            lat = float(rec.get("lat")); lon = float(rec.get("lon"))
        except (TypeError, ValueError):
            continue
        sub = _norm_suburb(rec.get("suburb"))
        if not sub:
            continue
        key = (sub, _norm_state(rec.get("state")))
        a = acc.setdefault(key, [0.0, 0.0, 0])
        a[0] += lat; a[1] += lon; a[2] += 1
    _suburb_index = {k: {"lat": v[0] / v[2], "lon": v[1] / v[2]} for k, v in acc.items()}
    return _suburb_index

def suburb_to_coords(suburb, state):
    """(suburb, state) -> {'lat','lon'} centroid, or None."""
    if not suburb:
        return None
    return _build_suburb_index().get((_norm_suburb(suburb), _norm_state(state)))

def _venue_record_to_coords(v):
    """Venue cache record -> (lat, lon, label). Prefers the real postcode, then
    falls back to a suburb+state centroid when the postcode is blank."""
    pc = (v.get("postcode") or "").strip()
    if pc:
        c = postcode_to_coords(pc)
        if c:
            return c["lat"], c["lon"], f"{v.get('name','venue')} ({pc})"
    sub = (v.get("suburb") or "").strip()
    if sub:
        c = suburb_to_coords(sub, v.get("state"))
        if c:
            return c["lat"], c["lon"], f"{v.get('name','venue')} ({sub})"
    return None

def _cache_venue_for_code(venue_str):
    """Resolve a short code / keyword ('Forum', 'JCA', 'rod laver arena') to a
    cached venue record via INDUCTION_VENUE_MAP, so typed 'near' values and
    scrape-fallback detect_venue codes resolve from real venue data too."""
    vl = (venue_str or "").strip().lower()
    if not vl:
        return None
    vc = _load_venue_cache()
    for code, keywords in INDUCTION_VENUE_MAP.items():
        cl = code.lower()
        if vl == cl or cl in vl or any(k in vl for k in keywords):
            for nk, rec in vc.items():
                if any(k in nk for k in keywords):
                    return rec
    return None

def venue_to_coords(venue_str):
    """Free-text SmartStaff venue tag -> (lat, lon, label).

    Resolution order, all from real SmartStaff venue data:
      1. Exact (normalised) venue-name match in the cache -> postcode, then
         suburb+state centroid. This is the call's venue from get-calls-bulk.
      2. Short code / keyword (typed 'near', scrape-fallback detect_venue code)
         -> the matching cached venue via INDUCTION_VENUE_MAP -> its real geo.
      3. Legacy VENUE_POSTCODES — last resort only, used before the venue cache
         has built. No longer the source of truth; retirable once you're happy
         the cache path covers everything in practice.

    Returns (lat, lon, label) or None."""
    vl = (venue_str or "").strip().lower()
    if not vl:
        return None

    # 1. Exact venue-name match (real postcode, then real suburb).
    v = _load_venue_cache().get(_norm_venue_key(venue_str))
    if v:
        c = _venue_record_to_coords(v)
        if c:
            return c

    # 2. Code / keyword -> cached venue -> real geo.
    v = _cache_venue_for_code(venue_str)
    if v:
        c = _venue_record_to_coords(v)
        if c:
            return c

    # 3. Legacy hard-coded table — last resort (cache not yet built).
    for code, keywords in INDUCTION_VENUE_MAP.items():
        cl = code.lower()
        if vl == cl or cl in vl or any(k in vl for k in keywords):
            c = postcode_to_coords(VENUE_POSTCODES.get(code))
            if c:
                return c["lat"], c["lon"], f"{code} ({VENUE_POSTCODES.get(code)})"
    return None


def resolve_origin(origin, targets):
    """origin = {"mode":"venue"} or {"mode":"postcode","postcode":"3000"}.
    Returns (lat, lon, label) or None."""
    if not origin:
        return None
    if origin.get("mode") == "postcode":
        c = postcode_to_coords(origin.get("postcode", ""))
        if c:
            return c["lat"], c["lon"], f"postcode {origin.get('postcode')}"
    elif origin.get("mode") == "venue":
        vstr = targets[0].get("venue", "") if targets else ""
        return venue_to_coords(vstr)
    return None


def induction_status_for_venue(inductions, venue_code):
    """Given inductions dict and venue code, return:
      'Complete'   — valid induction
      'Expired'    — completed but past validity period
      'Incomplete' — never completed
      None         — venue not in induction list (no check possible)
    """
    keywords = INDUCTION_VENUE_MAP.get(venue_code, [])
    if not keywords:
        return None, ""

    for venue_name, data in inductions.items():
        vl = venue_name.lower()
        if not any(k in vl for k in keywords):
            continue

        # Handle both old cache format (string) and new (dict)
        if isinstance(data, str):
            status    = data
            completed = ""
        else:
            status    = data.get("status", "")
            completed = data.get("completed", "")

        if status == "Incomplete":
            return "Incomplete", venue_name

        # For Complete or Expired from SmartStaff, calculate expiry ourselves
        if not completed:
            return "Incomplete", venue_name

        # Parse completion date and check expiry
        try:
            from dateutil import parser as dateparser
            completed_dt = dateparser.parse(completed)
        except Exception:
            # Try manual parse: "19 Nov 2025"
            try:
                completed_dt = datetime.strptime(completed, "%d %b %Y")
            except Exception:
                return status, venue_name  # can't parse, assume valid

        # Determine validity period in days (approx)
        days = 730 if vl in INDUCTION_24_MONTH else 365
        expiry = completed_dt + timedelta(days=days)
        now = datetime.now()
        if now > expiry:
            return "Expired", venue_name
        elif (expiry - now).days <= 14:
            return "Expiring Soon", venue_name

        return "Complete", venue_name

    return None, ""

def detect_venue(text):
    for substring, code in VENUE_MAP.items():
        if substring.lower() in text.lower():
            return code
    return None

def parse_shift(date_time_str, length_str):
    try:
        m = re.match(r"([A-Z][a-z]+ \d{1,2},\s*\d{4})\s*-\s*(\d{1,2}:\d{2})\s*Hrs", date_time_str.strip())
        if not m:
            return None, None
        date_clean = re.sub(r"\s+", " ", m.group(1).strip())
        start_dt = None
        for fmt in ("%B %d, %Y %H:%M", "%b %d, %Y %H:%M"):
            try:    start_dt = datetime.strptime(f"{date_clean} {m.group(2)}", fmt); break
            except: continue
        if not start_dt:
            return None, None
        lm = re.search(r"Length:\s*([\d.]+)\s*Hours?", length_str)
        if not lm:
            return None, None
        end_dt = start_dt + timedelta(hours=float(lm.group(1)))
        return start_dt, end_dt
    except Exception:
        return None, None

def scrape_calls(ss, url):
    """Scrape calls from a bookings/dashboard page, grouped by parent booking.

    SmartStaff dashboard structure (confirmed from live HTML):
      <div class="ydisplayarea">
        <h2>Booking Name</h2>
        <div class="bookinginfobox">... venue ...</div>
      </div>
      <table id="booking_XXXXX" class="styledtable">
        <tr>  ← header row (th cells)
        <tr>  ← call row: checkbox | #num | day | dd/mm/yy | HH:MM | name | N hrs |
                          <b class="neutral">B/R</b> | awaiting | NOTES_TEXT | view/edit
        <tr>  ← next call row
        <tr class="altrow">  ← shared notes/contact row at bottom of booking
    Notes live in the 10th <td> of the call row (index 9, 0-based).
    Booking name is in the <h2> of the .ydisplayarea div that immediately
    precedes the table (sibling, not parent).
    """
    resp = ss.get(url)
    soup = BeautifulSoup(resp.text, "html.parser")
    calls = []
    seen  = set()

    for link in soup.find_all("a", href=re.compile(r"bookings/(\d+)/callsheet/(\d+)")):
        href = link.get("href", "")
        m    = re.search(r"bookings/(\d+)/callsheet/(\d+)", href)
        if not m:
            continue
        booking_id = m.group(1)
        call_id    = m.group(2)
        if call_id in seen:
            continue
        seen.add(call_id)

        row = link.find_parent("tr")
        if not row:
            continue
        row_text = row.get_text(" ", strip=True)

        # ── call number ──────────────────────────────────────────────────────
        call_num_m = re.search(r"#(\d+)", row_text)

        # ── date (dd/mm/yy) ──────────────────────────────────────────────────
        date_m = re.search(r"(\d{2}/\d{2}/\d{2})", row_text)

        # ── time (HH:MM) ─────────────────────────────────────────────────────
        time_m = re.search(r"(\d{2}:\d{2})", row_text)

        # ── length ───────────────────────────────────────────────────────────
        length_m = re.search(r"(\d+(?:\.\d+)?)\s*hrs?", row_text, re.I)

        # ── call name — read directly from td[5] ─────────────────────────────
        tds = row.find_all("td")
        call_name = tds[5].get_text(strip=True) if len(tds) >= 6 else ""

        # ── booked / required ─────────────────────────────────────────────────
        # <b class="neutral">1 / 10</b> inside a <td align="center">
        booked = 0
        required = 0
        for b_tag in row.find_all("b"):
            bm = re.fullmatch(r"\s*(\d{1,3})\s*/\s*(\d{1,3})\s*", b_tag.get_text())
            if bm:
                booked   = int(bm.group(1))
                required = int(bm.group(2))
                break

        # ── notes — td[9] in the call row ────────────────────────────────────
        notes = ""
        if len(tds) >= 10:
            notes_text = tds[9].get_text(strip=True)
            if notes_text:
                notes = notes_text

        # ── booking name + venue ──────────────────────────────────────────────
        # The <table id="booking_XXXXX"> is a sibling that comes AFTER the
        # <div class="ydisplayarea"> — so we find the table, then look for the
        # preceding sibling div.ydisplayarea in the parent container.
        booking_name = ""
        venue = ""
        table = row.find_parent("table", id=re.compile(r"^booking_"))
        if table:
            # Walk previous siblings of the table to find .ydisplayarea
            for sibling in table.previous_siblings:
                if getattr(sibling, "name", None) == "div" and "ydisplayarea" in sibling.get("class", []):
                    h2 = sibling.find("h2")
                    if h2:
                        booking_name = h2.get_text(strip=True)
                    info_box = sibling.find("div", class_="bookinginfobox")
                    if info_box:
                        venue = detect_venue(info_box.get_text()) or ""
                    break

        calls.append({
            "booking_id":   booking_id,
            "call_id":      call_id,
            "call_num":     call_num_m.group(1) if call_num_m else call_id,
            "date":         date_m.group(1) if date_m else "",
            "time":         time_m.group(1) if time_m else "",
            "length":       float(length_m.group(1)) if length_m else 0,
            "call_name":    call_name,
            "booked":       booked,
            "required":     required,
            "unfilled":     booked < required,
            "venue":        venue,
            "notes":        notes,
            "booking_name": booking_name,
        })

    return calls

def scrape_call_details(ss, booking_id, call_id):
    """Get call date/time/duration/venue from callsheet."""
    resp = ss.get(f"{BASE_URL}/bookings/{booking_id}/callsheet/{call_id}")
    soup = BeautifulSoup(resp.text, "html.parser")

    date_el   = soup.find("input", {"name": "start_date"})
    time_el   = soup.find("input", {"name": "start_time"})
    length_el = soup.find("input", {"name": "length"})
    name_el   = soup.find("input", {"name": "name"})
    crew_el   = soup.find("input", {"name": "crew_required"})

    date_val   = date_el["value"].strip()   if date_el   else ""
    time_val   = time_el["value"].strip()   if time_el   else ""
    length_val = length_el["value"].strip() if length_el else "0"
    name_val   = name_el["value"].strip()   if name_el   else ""
    crew_req   = int(crew_el["value"])      if crew_el   else 0

    try:
        time_clean  = time_val[:5]
        date_clean  = re.sub(r"\s+", " ", date_val.strip())
        start_dt    = None
        for fmt in ("%B %d, %Y %H:%M", "%b %d, %Y %H:%M"):
            try:    start_dt = datetime.strptime(f"{date_clean} {time_clean}", fmt); break
            except: continue
        if not start_dt:
            return None, f"Could not parse date: {date_clean!r}"
        end_dt = start_dt + timedelta(hours=float(length_val))
    except Exception as e:
        return None, str(e)

    venue = detect_venue(resp.text) or ""

    # Crew required from banner
    banner_m = re.search(r"THIS CALL REQUIRES (\d+) CREW", resp.text)
    crew_required = int(banner_m.group(1)) if banner_m else crew_req

    return {
        "call_name":     name_val,
        "start_dt":      start_dt.isoformat(),
        "end_dt":        end_dt.isoformat(),
        "length_hrs":    float(length_val),
        "venue":         venue,
        "crew_required": crew_required,
        "date_str":      start_dt.strftime("%A %d %B %Y"),
        "start_str":     start_dt.strftime("%H:%M"),
        "end_str":       end_dt.strftime("%H:%M"),
    }, None

def scrape_booking_details(ss, booking_id):
    """Booking detail for the in-GOAT view dialog (read-only).

    Scrapes the edit-booking form with a GET (no side effects — the edit page
    only writes on POST with action=add). Field names mirror add-booking.php's
    POST handler: name, creation_date, status, customer, contact, onsiteUserID,
    venue, reference, notes.
    """
    resp = ss.get(f"{BASE_URL}/bookings/edit/{booking_id}")
    soup = BeautifulSoup(resp.text, "html.parser")

    name_el = soup.find("input", {"name": "name"})
    if name_el is None:
        return None, "Could not load booking (not found or session expired)"

    def inp(n):
        el = soup.find("input", {"name": n})
        return (el.get("value") or "").strip() if el else ""

    def ta(n):
        el = soup.find("textarea", {"name": n})
        return el.get_text().strip() if el else ""

    def sel(n):
        s = soup.find("select", {"name": n})
        if not s:
            return {"id": "", "name": ""}
        chosen = None
        for o in s.find_all("option"):
            if o.has_attr("selected"):
                chosen = o
                break
        if chosen is None:
            return {"id": "", "name": ""}
        return {"id": (chosen.get("value") or "").strip(),
                "name": chosen.get_text().strip()}

    return {
        "booking_id": str(booking_id),
        "name":      (name_el.get("value") or "").strip(),
        "date_str":  inp("creation_date"),
        "status":    sel("status").get("name", ""),
        "reference": inp("reference"),
        "notes":     ta("notes"),
        "customer":  sel("customer"),
        "contact":   sel("contact"),
        "onsite":    sel("onsiteUserID"),
        "venue":     sel("venue"),
    }, None

def fetch_booking_bulk(ss, booking_id):
    """Bulk booking detail via get-booking.php (read-all). Returns (data, error).

    Richer than scrape_booking_details: includes contact + on-site phone/mobile,
    venue address, and a per-call crew roster (name, mobile, status) so crew
    bosses can reach people when crew run late.
    """
    url = f"{BASE_URL}/ajax/crew/get-booking.php"
    try:
        resp = ss.get(url, params={"id": booking_id}, allow_redirects=True, timeout=30)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        return None, f"HTTP {resp.status_code}"
    try:
        data = json.loads(resp.text or "{}")
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return None, data["error"]
    return data, None


def expand_linked_calls(ss, calls):
    """Widen an outgoing offer to include any linked siblings, so the same crew
    are offered the whole linked group (linked calls are answered as a unit —
    respond-to-call.php cascades the response). Group membership is resolved
    server-side from get-booking.php, so it is correct regardless of what the
    Crew Finder currently has in view. Deduped by call_id. On any lookup failure
    the original calls are returned unchanged — an offer is never blocked."""
    if not calls:
        return calls

    booking_ids = set()
    for c in calls:
        bid = c.get("booking_id")
        if bid:
            booking_ids.add(str(bid))

    group_of   = {}   # call_id -> link_group
    members_of = {}   # link_group -> [ {call_id, call_name, booking_id} ]
    for bid in booking_ids:
        data, err = fetch_booking_bulk(ss, bid)
        if err or not isinstance(data, dict):
            continue
        for bc in (data.get("calls") or []):
            g = bc.get("link_group")
            if g is None:
                continue
            try:
                g = int(g)
            except Exception:
                continue
            if g <= 0:
                continue
            cid = str(bc.get("call_id"))
            group_of[cid] = g
            members_of.setdefault(g, []).append({
                "call_id":    cid,
                "call_name":  bc.get("call_name", cid),
                "booking_id": bid,
            })

    expanded = list(calls)
    seen = set(str(c.get("call_id")) for c in calls)
    for c in calls:
        g = group_of.get(str(c.get("call_id")))
        if not g:
            continue
        for m in members_of.get(g, []):
            if m["call_id"] not in seen:
                expanded.append(m)
                seen.add(m["call_id"])
    return expanded


def _get_all_crew(ss):
    """Unified crew-list fetcher: tries the bulk endpoint when enabled, falls
    back to scrape_all_crew on failure. Same return shape either way:
    a list of dicts with id, manage_id, name, phone, etc."""
    if USE_BULK_ENDPOINTS:
        crew, err = fetch_crew_bulk(ss)
        if err is None and crew is not None:
            return crew
        print(f"[bulk-crew] endpoint failed ({err}); falling back to scraper")
    return scrape_all_crew(ss)


def _get_crew_profile(ss, cid, bulk_lookup=None):
    """Returns (groups, rating, inductions) for one crew member.

    If bulk_lookup (a dict {id_str: crew_dict from fetch_crew_bulk}) is
    provided and has data for cid, uses that. Otherwise falls back to the
    per-crew scrapers. Pass a bulk_lookup to avoid N HTTP calls in loops."""
    if bulk_lookup is not None:
        entry = bulk_lookup.get(str(cid))
        if entry is not None:
            return entry.get("groups", []), entry.get("rating", 0), entry.get("inductions", {})
    groups, rating = scrape_crew_profile(ss, cid)
    inductions     = scrape_crew_inductions(ss, cid)
    return groups, rating, inductions


def scrape_all_crew(ss):
    """Get all crew from all pages."""
    all_crew = []
    page_num = 0
    while True:
        resp = ss.get(f"{BASE_URL}/crew?p={page_num}&")
        soup = BeautifulSoup(resp.text, "html.parser")
        rows = soup.select("#crewcontent tr")
        page_crew = []
        for row in rows:
            name_link   = row.find("a", href=re.compile(r"add-call\.php.*userID="))
            manage_link = row.find("a", href=re.compile(r"crew/manage/"))
            phone_link  = row.find("a", href=re.compile(r"^tel:"))
            if not name_link or not manage_link:
                continue
            name  = name_link.get_text(strip=True)
            # userID = used for adding to calls/SMS (internal SmartStaff DB key)
            uid_m = re.search(r"userID=(\d+)", name_link.get("href", ""))
            # manage_id = used for profile/shift URLs
            mgr_m = re.search(r"crew/manage/(\d+)", manage_link.get("href", ""))
            phone = phone_link.get_text(strip=True) if phone_link else ""
            # EIN = the human-facing employee number shown in the EIN column.
            # It lives as plain cell text (not in a href), e.g. "6070". The little
            # locate-pin next to it is a separate anchor, so we read text-only cells.
            ein = ""
            for td in row.find_all("td"):
                # Skip cells that contain the name/manage/phone links
                if td.find("a", href=re.compile(r"add-call\.php|crew/manage/|^tel:")):
                    continue
                cell_txt = td.get_text(strip=True)
                # EIN is a short pure-digit token (typically 4 digits), possibly
                # followed by icon/whitespace. Match a standalone leading digit run.
                m = re.match(r"^(\d{3,6})(?:\D.*)?$", cell_txt)
                if m and not cell_txt.startswith("0"):  # avoid phone fragments like 0423...
                    ein = m.group(1)
                    break
            if uid_m and mgr_m and name:
                page_crew.append({
                    "name":      name,
                    "id":        uid_m.group(1),   # userID — operations only (add-to-call/SMS)
                    "manage_id": mgr_m.group(1),   # manage ID — profile/shift URLs
                    "ein":       ein or uid_m.group(1),  # EIN — display; fall back to userID
                    "phone":     phone,
                })

        if not page_crew:
            break
        all_crew.extend(page_crew)

        # Check for NEXT
        has_next = any(
            a.get_text(strip=True).upper() == "NEXT"
            for a in soup.find_all("a", href=re.compile(r"crew\?p="))
        )
        if not has_next:
            break
        page_num += 1

    return all_crew

def scrape_crew_profile(ss, crew_id):
    """Get groups and rating for a crew member."""
    resp  = ss.get(f"{BASE_URL}/crew/manage/{crew_id}?page=1")
    soup  = BeautifulSoup(resp.text, "html.parser")
    groups = [el.get_text(strip=True) for el in soup.select("div.crewgroup") if el.get_text(strip=True)]
    # Rating is set via JS: "var rating = N" — stars are all off in raw HTML
    rating_m = re.search(r"var rating\s*=\s*(\d+)", resp.text)
    rating   = int(rating_m.group(1)) if rating_m else 0
    return groups, rating

def scrape_crew_inductions(ss, crew_id):
    """Get induction status for each venue for a crew member.
    Returns dict: {venue_name: {'status': 'Complete'|'Incomplete', 'completed': 'DD Mon YYYY'|''}}
    """
    resp = ss.get(f"{BASE_URL}/crew/manage/{crew_id}?page=inductions")
    soup = BeautifulSoup(resp.text, "html.parser")
    inductions = {}

    for row in soup.find_all("tr"):
        cells = row.find_all("td")
        if len(cells) >= 2:
            venue_name  = cells[0].get_text(strip=True)
            status_text = cells[1].get_text(strip=True)
            completed   = cells[2].get_text(strip=True) if len(cells) >= 3 else ""
            if venue_name and status_text in ("Complete", "Incomplete", "Expired", "Expiring Soon"):
                inductions[venue_name] = {"status": status_text, "completed": completed}

    return _filter_inductions(inductions)

def get_crew_shifts(ss, crew_id, today):
    """DEPRECATED (Crew Finder migration): superseded by fetch_shifts_bulk /
    _get_shifts_for_window. No longer called by availability search or the GOAT
    tools; retained as reference and pending removal once the bulk path has
    proven stable in production.

    Get confirmed future shifts for a crew member, paginating through all pages.

    The plain HTTP response wraps data in <b> tags:
      <b>May 24, 2026 - 22:00 Hrs</b><br /><b>Length:</b> 4 Hours ... Confirmed
    """
    pattern = r"<b>([A-Z][a-z]+ \d{1,2},\s*\d{4}\s*-\s*\d{1,2}:\d{2}\s*Hrs)</b>.*?<b>Length:</b>\s*([\d.]+)\s*Hours?.*?(Confirmed|Declined|Pending)"
    shifts  = []
    seen    = set()

    for page in range(1, 10):  # up to 9 pages — bail early when no new shifts found
        resp    = ss.get(f"{BASE_URL}/crew/manage/{crew_id}?page={page}")
        content = resp.text
        matches = list(re.finditer(pattern, content, re.DOTALL))
        if not matches:
            break

        found_new = False
        for match in matches:
            date_time_str = match.group(1)
            length_str    = f"Length: {match.group(2)} Hours"
            status        = match.group(3)

            start_dt, end_dt = parse_shift(date_time_str, length_str)
            if not start_dt or not end_dt:
                continue
            if end_dt < today:
                continue
            if status.lower() != "confirmed":
                continue
            key = (start_dt, end_dt)
            if key in seen:
                continue
            seen.add(key)
            found_new = True
            preceding = content[max(0, match.start()-300):match.start()]
            venue     = detect_venue(preceding)
            shifts.append({
                "start": start_dt.isoformat(),
                "end":   end_dt.isoformat(),
                "venue": venue or "",
            })

        if not found_new:
            break  # all matches on this page were past or already seen

    return shifts


def get_crew_unavailabilities(ss, crew_id, today):
    """Scrape genuine crew-entered unavailability periods.
    Filters out call booking entries identified by:
      - @ symbol (e.g. "Event @ Venue")
      - en-dash or em-dash separators
      - space-hyphen-space " - " (e.g. "IDLE - RLA - Load Out")
    Genuine entries are plain text: "On leave", "Holiday", etc.
    """
    resp = ss.get(f"{BASE_URL}/crew/manage/{crew_id}?page=unavailabilities")
    soup = BeautifulSoup(resp.text, "html.parser")
    unavails = []
    for row in soup.find_all("tr"):
        cells = row.find_all("td")
        if len(cells) < 2: continue
        from_text = cells[0].get_text(strip=True)
        to_text   = cells[1].get_text(strip=True)
        reason    = cells[2].get_text(strip=True) if len(cells) >= 3 else ""
        if "@" in reason or "–" in reason or "—" in reason or " - " in reason:
            continue
        start_dt = end_dt = None
        for fmt in ("%d %b %Y", "%d/%m/%Y", "%Y-%m-%d"):
            try:    start_dt = datetime.strptime(from_text, fmt); break
            except: continue
        for fmt in ("%d %b %Y", "%d/%m/%Y", "%Y-%m-%d"):
            try:    end_dt = datetime.strptime(to_text, fmt).replace(hour=23, minute=59, second=59); break
            except: continue
        if not start_dt or not end_dt or end_dt < today: continue
        unavails.append({"start": start_dt.isoformat(), "end": end_dt.isoformat(), "reason": reason or "Unavailable"})
    return unavails


def _coerce_status(raw):
    """Normalize a shift status to int (or None).

    SmartStaff's get-shifts-bulk.php can emit the status as a JSON *string*
    ("5") rather than an int (5). Every conflict/utilization check compares
    `status == 5` and the timeline JS compares `status === 5` — both fail
    against a string, so confirmed shifts silently stop triggering conflicts
    and render as blue 'info' bars instead of red. Coercing to int at this one
    parse boundary fixes all of them regardless of the wire type. Idempotent:
    an int passes straight through; None / "" / junk -> None (non-confirmed)."""
    if raw is None or raw == "":
        return None
    try:
        return int(raw)
    except (TypeError, ValueError):
        return None


def fetch_shifts_bulk(ss, start_dt, end_dt):
    """Bulk fetch of every confirmed shift + every unavailability in the window,
    in one HTTP call, via the SmartStaff /ajax/crew/get-shifts-bulk.php endpoint.

    Returns (shifts_by_name, unavails_by_user_id, error):
        shifts_by_name      : {name: [{start, end, venue, call_id, ...}]}
        unavails_by_user_id : {user_id_str: [{id, start, end, reason}]}
        error               : str or None

    On any failure returns ({}, {}, err) so the caller can fall back to the
    HTML scraper.
    """
    start_s = start_dt.strftime("%Y-%m-%d")
    end_s   = end_dt.strftime("%Y-%m-%d")
    url = f"{BASE_URL}/ajax/crew/get-shifts-bulk.php?start={start_s}&end={end_s}"
    try:
        resp = ss.get(url, allow_redirects=True, timeout=30)
    except Exception as e:
        return {}, {}, f"request failed: {e}"
    if resp.status_code != 200:
        return {}, {}, f"HTTP {resp.status_code}"
    try:
        data = json.loads(resp.text or "{}")
    except Exception as e:
        return {}, {}, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return {}, {}, data["error"]

    shifts_by_name = {}
    for s in data.get("shifts", []):
        name = s.get("user", "")
        if not name:
            continue
        shifts_by_name.setdefault(name, []).append({
            "start":        s["start"],
            "end":          s["end"],
            "venue":        s.get("venue", "") or "",
            "call_id":      s.get("call_id"),
            "booking_id":   s.get("booking_id"),
            "call_name":    s.get("call_name", ""),
            "booking_name": s.get("booking_name", ""),
            "status":       _coerce_status(s.get("status")),   # 5=confirmed, 1=pending, 6=declined, 8=noshow, 0=unset, None=orphan (coerced "5"->5)
        })

    unavails_by_user_id = {}
    for u in data.get("unavails", []):
        uid = str(u.get("user_id", ""))
        if not uid:
            continue
        unavails_by_user_id.setdefault(uid, []).append({
            "id":     u.get("event_id"),
            "start":  u["start"],
            "end":    u["end"],
            "reason": u.get("reason", "") or "Unavailable",
        })

    return shifts_by_name, unavails_by_user_id, None


def fetch_unavails_bulk(ss, start_dt, end_dt):
    """Bulk fetch of every unavailability (type=1) in the window via the
    SmartStaff /ajax/crew/get-unavailabilities-bulk.php endpoint.

    Replaces the per-crew HTML scrape (get_crew_unavailabilities) which lost
    hour-level data and dropped same-day entries shorter than a full day.

    Returns (unavails_by_user_id, error):
        unavails_by_user_id : {user_id_str: [{id, start, end, reason}]}
        error               : str or None

    On any failure returns ({}, err) so the caller can fall back to the
    legacy per-crew HTML scrape.
    """
    start_s = start_dt.strftime("%Y-%m-%d")
    end_s   = end_dt.strftime("%Y-%m-%d")
    url = f"{BASE_URL}/ajax/crew/get-unavailabilities-bulk.php?start={start_s}&end={end_s}"
    try:
        resp = ss.get(url, allow_redirects=True, timeout=30)
    except Exception as e:
        return {}, f"request failed: {e}"
    if resp.status_code != 200:
        return {}, f"HTTP {resp.status_code}"
    try:
        data = json.loads(resp.text or "{}")
    except Exception as e:
        return {}, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return {}, data["error"]

    unavails_by_user_id = {}
    for u in data.get("unavails", []):
        uid = str(u.get("user_id", ""))
        if not uid:
            continue
        unavails_by_user_id.setdefault(uid, []).append({
            "id":     u.get("event_id"),
            "start":  u["start"],
            "end":    u["end"],
            "reason": u.get("reason", "") or u.get("title", "") or "Unavailable",
        })

    return unavails_by_user_id, None


def fetch_booked_crew_bulk(ss, start_dt, end_dt):
    """Bulk fetch of every confirmed (status=5) crew-call assignment in the
    window via the SmartStaff /ajax/crew/get-booked-crew-bulk.php endpoint.

    Returns (assignments, error):
        assignments : list of {call_id, user_id, name, start, end, venue}
                      start/end are ISO strings (datetime conversion happens
                      at the consumer); venue may be empty string.
        error       : str or None

    On any failure returns ([], err) so the caller can fall back gracefully
    (or, for the schedule clash detection, simply skip the feature).
    """
    start_s = start_dt.strftime("%Y-%m-%d")
    end_s   = end_dt.strftime("%Y-%m-%d")
    url = f"{BASE_URL}/ajax/crew/get-booked-crew-bulk.php?start={start_s}&end={end_s}"
    try:
        resp = ss.get(url, allow_redirects=True, timeout=30)
    except Exception as e:
        return [], f"request failed: {e}"
    if resp.status_code != 200:
        return [], f"HTTP {resp.status_code}"
    try:
        data = json.loads(resp.text or "{}")
    except Exception as e:
        return [], f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return [], data["error"]

    assignments = []
    for a in data.get("assignments", []):
        # Decode HTML entities in names (the endpoint emits things like "O&#39;Brien")
        from html import unescape
        assignments.append({
            "call_id": a.get("call_id"),
            "user_id": a.get("user_id"),
            "name":    unescape(a.get("user", "")),
            "start":   a["start"],
            "end":     a["end"],
            "venue":   a.get("venue", "") or "",
        })

    return assignments, None


def _get_unavails_for_window(ss, start_dt, end_dt):
    """Unified unavailability fetcher: tries the bulk endpoint when enabled,
    falls back to per-crew HTML scrape on failure. Returns
    unavails_by_user_id keyed by str(crew_id), matching the legacy
    unavail_cache shape so callers can drop in the result without coercion.

    Fallback iterates all crew via the existing get_crew_unavailabilities
    scrape; this is slow (~2 min for 392 crew) but covers the case where the
    new endpoint is not yet deployed on a given SmartStaff instance.
    """
    if USE_BULK_UNAVAILS_ENDPOINT:
        unavails, err = fetch_unavails_bulk(ss, start_dt, end_dt)
        if err is None:
            return unavails
        print(f"[bulk-unavails] endpoint failed ({err}); falling back to per-crew scrape")
    # Fallback: per-crew HTML scrape. Threaded to match the legacy preload's
    # behaviour. `today` is the search-from date so the scraper drops past
    # unavails consistent with its existing filter.
    from concurrent.futures import ThreadPoolExecutor, as_completed as _as_completed
    crew_data, _ = load_cache()
    if not crew_data:
        return {}
    today = start_dt
    out = {}
    with ThreadPoolExecutor(max_workers=15) as executor:
        uf = {executor.submit(get_crew_unavailabilities, ss, cid, today): cid
              for cid, _ in crew_data.items()}
        for future in _as_completed(uf):
            cid = uf[future]
            try:    out[str(cid)] = future.result()
            except: out[str(cid)] = []
    return out


def fetch_crew_bulk(ss, include_inactive=False):
    """Bulk fetch every crew member with name, mobile, rating, groups, and
    inductions in one HTTP call, via /ajax/crew/list-crew-bulk.php.

    Returns (crew_list, error):
        crew_list: [{id, name, phone, email, rating, paygradeID, active,
                     groups: [...], inductions: {venue: {status, completed}}}]
        error: str or None
    """
    url = f"{BASE_URL}/ajax/crew/list-crew-bulk.php"
    if include_inactive:
        url += "?active=0"
    try:
        resp = ss.get(url, allow_redirects=True, timeout=30)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        return None, f"HTTP {resp.status_code}"
    try:
        data = json.loads(resp.text or "{}")
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return None, data["error"]

    out = []
    for c in data.get("crew", []):
        out.append({
            "id":         str(c["id"]),
            "manage_id":  str(c["id"]),
            "name":       c.get("name", ""),
            "phone":      c.get("mobile", "") or "",
            "email":      c.get("email", "") or "",
            "rating":     int(c.get("rating") or 0),
            "paygradeID": int(c.get("paygradeID") or 0),
            "active":     int(c.get("active") or 0),
            "groups":     c.get("groups", []) or [],
            "inductions": _filter_inductions(c.get("inductions", {})),
            "ein":        c.get("ein") or c.get("id"),  # prefer endpoint EIN; fall back to userID
            "postcode":   str(c.get("postcode") or "").strip(),
            "notes":      c.get("notes") or "",          # users.notes — for the name-hover card
            "stats":      c.get("stats") or {},   # Late / No-show tallies for the hover card
        })
    return out, None


def _get_shifts_for_window(ss, start_dt, end_dt):
    """Unified shifts fetcher: tries the bulk endpoint when enabled, falls back
    to scrape_shifts_from_bookings on failure. Returns just shifts_by_name to
    match the legacy signature; unavailabilities from the bulk call are
    discarded here (callers that need them should call fetch_shifts_bulk
    directly)."""
    if USE_BULK_ENDPOINTS:
        shifts, _unavails, err = fetch_shifts_bulk(ss, start_dt, end_dt)
        if err is None:
            return shifts
        # log to stderr but don't crash — fall through to scraper
        print(f"[bulk-shifts] endpoint failed ({err}); falling back to scraper")
    return scrape_shifts_from_bookings(ss, start_dt, end_dt)


def scrape_shifts_from_bookings(ss, start_dt, end_dt):
    """Build a crew→shifts map by scraping all bookings in the date window.
    Uses scrape_schedule which covers ALL bookings (including fully booked ones).
    Returns {crew_name: [{start, end, venue}]}
    """
    from concurrent.futures import ThreadPoolExecutor, as_completed as _as_completed

    # scrape_schedule returns a FLAT list of call dicts
    days = max(1, (end_dt - start_dt).days)
    try:
        all_calls = scrape_schedule(ss, days=days)  # flat list
    except Exception:
        return {}

    # Filter to our window and only calls with booked crew
    window_calls = []
    for c in all_calls:
        if c.get("booked", 0) == 0:
            continue  # no crew booked — skip
        try:
            cs = datetime.fromisoformat(c["start_iso"])
            ce = datetime.fromisoformat(c["end_iso"])
        except:
            continue
        if ce < start_dt or cs > end_dt:
            continue
        window_calls.append({
            "booking_id": c["booking_id"],
            "call_id":    c["call_id"],
            "start":      c["start_iso"],
            "end":        c["end_iso"],
            "venue":      c.get("venue", ""),
        })

    if not window_calls:
        return {}

    # For each call, fetch confirmed crew in parallel
    def fetch_call_crew(call):
        try:
            resp = ss.get(f"{BASE_URL}/bookings/{call['booking_id']}/callsheet/{call['call_id']}")
            soup = BeautifulSoup(resp.text, "html.parser")
            crew = []
            for table in soup.find_all("table"):
                rows = table.find_all("tr")
                found = []
                for row in rows[1:]:
                    tds = row.find_all("td")
                    if len(tds) < 3: continue
                    name_raw = tds[1].get_text(strip=True)
                    status   = tds[2].get_text(strip=True).lower()
                    if not name_raw: continue
                    if "confirm" in status and "unconfirm" not in status:
                        if "," in name_raw:
                            parts = name_raw.split(",", 1)
                            name = parts[1].strip() + " " + parts[0].strip()
                        else:
                            name = name_raw.strip()
                        found.append(name)
                if found:
                    crew = found
                    break
            return call, crew
        except:
            return call, []

    shifts_by_name = {}
    with ThreadPoolExecutor(max_workers=20) as executor:
        futures = {executor.submit(fetch_call_crew, c): c for c in window_calls}
        for future in _as_completed(futures):
            call, crew_names = future.result()
            for name in crew_names:
                # Normalise to "Last, First" to match crew cache key format
                # fetch_call_crew already converts "Last, First" → "First Last"
                # so convert back: "First Last" → "Last, First"
                parts = name.strip().split()
                if len(parts) >= 2:
                    cache_name = parts[-1] + ", " + " ".join(parts[:-1])
                else:
                    cache_name = name
                if cache_name not in shifts_by_name:
                    shifts_by_name[cache_name] = []
                shifts_by_name[cache_name].append({
                    "start":  call["start"],
                    "end":    call["end"],
                    "venue":  call["venue"],
                    "status": 5,  # scraper only collects confirmed crew (see "confirm" filter above) — tag so the ==5 conflict/forecast checks pass on this fallback path
                })

    return shifts_by_name


# trigger_forecast_preload and trigger_unavail_preload removed in 3.4.5.
# Forecast computation is now done at request time in /api/forecast.

# ─── CONFLICT RULES ───────────────────────────────────────────────────────────

LONG_SHIFT_HRS  = 8
LONG_GAP_HRS    = 6
VENUE_GAP_HRS   = 2
MAX_24H_HRS     = 16

def check_conflict(shifts, target_start, target_end, target_venue):
    for s in shifts:
        shift_start = datetime.fromisoformat(s["start"])
        shift_end   = datetime.fromisoformat(s["end"])
        shift_venue = s.get("venue", "")
        shift_dur   = (shift_end - shift_start).total_seconds() / 3600

        # Rule 1: overlap
        if shift_start < target_end and shift_end > target_start:
            return True, f"Rule 1 - Overlap: {shift_start.strftime('%d %b %H:%M')}-{shift_end.strftime('%H:%M')} @ {shift_venue or '?'}"

        # Gap
        if target_start >= shift_end:
            gap = (target_start - shift_end).total_seconds() / 3600
        elif shift_start >= target_end:
            gap = (shift_start - target_end).total_seconds() / 3600
        else:
            continue

        # Rule 2: long shift gap
        if shift_dur >= LONG_SHIFT_HRS and gap < LONG_GAP_HRS:
            return True, f"Rule 2 - {shift_dur:.0f}hr shift needs {LONG_GAP_HRS}hr gap (only {gap:.1f}hrs): {shift_start.strftime('%d %b %H:%M')} @ {shift_venue or '?'}"

        # Rule 3: venue change
        if shift_venue and target_venue and shift_venue != target_venue and gap < VENUE_GAP_HRS:
            return True, f"Rule 3 - Venue change {shift_venue}→{target_venue} needs {VENUE_GAP_HRS}hr gap (only {gap:.1f}hrs)"

    # Rule 4: rolling 24hr window
    for win_start, win_end in [
        (target_end - timedelta(hours=24), target_end),
        (target_start, target_start + timedelta(hours=24)),
    ]:
        total = 0.0
        for s in shifts:
            ss = datetime.fromisoformat(s["start"])
            se = datetime.fromisoformat(s["end"])
            if se <= win_start or ss >= win_end:
                continue
            total += (min(se, win_end) - max(ss, win_start)).total_seconds() / 3600
        t_start = max(target_start, win_start)
        t_end   = min(target_end,   win_end)
        if t_end > t_start:
            total += (t_end - t_start).total_seconds() / 3600
        if total > MAX_24H_HRS:
            return True, f"Rule 4 - Would work {total:.1f}hrs in 24hr window (max {MAX_24H_HRS}hrs)"

    return False, ""


def _tag_shift_for_timeline(shift, targets):
    """Annotate a nearby shift for Crew Finder timeline colouring (3.5.1).

    Tags are computed against the searched target call(s) so the frontend can
    colour each bar by its relationship to the *requested* shift, reusing the
    SAME check_conflict rules (single source of truth) instead of duplicating
    the overlap/gap maths in JS.

      is_target        — this shift IS one of the target calls (the crew member
                         is already assigned to the call being filled). The bar
                         is coloured by its confirmation status, not by conflict:
                         confirmed (5) -> green, declined (6) -> red, anything
                         else -> grey. You can't "conflict" with the very call
                         you're filling.
      conflicts_target — this single non-target shift would trigger a conflict
                         (Rule 1-4) against a target call. Drives red (confirmed)
                         / amber (unconfirmed).
      overlaps_target  — this single non-target shift overlaps a target window in
                         time (Rule 1 only). Drives amber for declined shifts.
    """
    s_call_id = str(shift.get("call_id"))
    is_target = any(s_call_id == str(t["call_id"]) for t in targets)
    conflicts_target = False
    overlaps_target  = False
    if not is_target:
        s_start = datetime.fromisoformat(shift["start"])
        s_end   = datetime.fromisoformat(shift["end"])
        for t in targets:
            if s_call_id == str(t["call_id"]):
                continue
            c_flag, _ = check_conflict([shift], t["start"], t["end"], t.get("venue", ""))
            if c_flag:
                conflicts_target = True
            if s_start < t["end"] and s_end > t["start"]:
                overlaps_target = True
    return {**shift,
            "is_target":        is_target,
            "conflicts_target": conflicts_target,
            "overlaps_target":  overlaps_target}

# ─── IMPORT LOG ───────────────────────────────────────────────────────────────

def load_import_log():
    if not os.path.exists(IMPORT_LOG_FILE):
        return []
    try:
        with open(IMPORT_LOG_FILE) as f:
            return json.load(f)
    except Exception:
        return []

def save_import_log(log):
    with open(IMPORT_LOG_FILE, "w") as f:
        json.dump(log, f, indent=2)

def find_import_log_entry(estimate_id):
    for entry in load_import_log():
        if entry.get("estimate_id") == estimate_id:
            return entry
    return None

# ─── SMARTSTAFF BOOKING/CALL CREATION ────────────────────────────────────────

def ss_get_customers(ss):
    """Scrape all customers from /customers list pages → list of {id, name}."""
    customers = []
    seen = set()
    page = 0
    while True:
        resp = ss.get(f"{BASE_URL}/customers?p={page}")
        soup = BeautifulSoup(resp.text, "html.parser")
        found = 0
        for a in soup.find_all("a", href=re.compile(r"^customer/edit/(\d+)$")):
            m = re.search(r"customer/edit/(\d+)", a["href"])
            name = a.get_text(strip=True)
            if m and name and name != "view/edit" and m.group(1) not in seen:
                seen.add(m.group(1))
                customers.append({"id": m.group(1), "name": name})
                found += 1
        if not found:
            break
        # Check for next page
        has_next = any(
            a.get_text(strip=True).upper() == "NEXT"
            for a in soup.find_all("a", href=re.compile(r"customers\?p="))
        )
        if not has_next:
            break
        page += 1
    return customers

def ss_get_contacts(ss):
    """Scrape all contacts from /contacts list pages → list of {id, name}."""
    contacts = []
    seen = set()
    page = 0
    while True:
        resp = ss.get(f"{BASE_URL}/contacts?p={page}")
        soup = BeautifulSoup(resp.text, "html.parser")
        found = 0
        for a in soup.find_all("a", href=re.compile(r"contact/edit/(\d+)")):
            m = re.search(r"contact/edit/(\d+)", a["href"])
            name = a.get_text(strip=True)
            if m and name and name != "view/edit" and m.group(1) not in seen:
                seen.add(m.group(1))
                contacts.append({"id": m.group(1), "name": name})
                found += 1
        if not found:
            break
        has_next = any(
            a.get_text(strip=True).upper() == "NEXT"
            for a in soup.find_all("a", href=re.compile(r"contacts\?p="))
        )
        if not has_next:
            break
        page += 1
    return contacts

def ss_get_venues(ss):
    """Scrape all venues from /venues list pages → list of {id, name}."""
    venues = []
    seen = set()
    page = 0
    while True:
        resp = ss.get(f"{BASE_URL}/venues?p={page}")
        soup = BeautifulSoup(resp.text, "html.parser")
        found = 0
        for a in soup.find_all("a", href=re.compile(r"^venues/edit/(\d+)$")):
            m = re.search(r"venues/edit/(\d+)", a["href"])
            name = a.get_text(strip=True)
            if m and name and name != "view/edit" and m.group(1) not in seen:
                seen.add(m.group(1))
                venues.append({"id": m.group(1), "name": name})
                found += 1
        if not found:
            break
        has_next = any(
            a.get_text(strip=True).upper() == "NEXT"
            for a in soup.find_all("a", href=re.compile(r"venues\?p="))
        )
        if not has_next:
            break
        page += 1
    return venues

def fuzzy_match(name, options):
    """Find best match for name in list of {id, name} dicts.
    Four-tier matching:
      1. Exact (case-insensitive)
      2. Token-sort — catches "Smith, John" ↔ "John Smith"
      3. All tokens present in target (subset match)
      4. Substring
    Returns matching dict or None.
    """
    if not name or not options:
        return None

    import re as _re
    def normalise(s):
        s = s.lower().strip()
        s = _re.sub(r"[,.'`]", " ", s)
        s = _re.sub(r"\s+", " ", s).strip()
        return s

    def token_sort_key(s):
        return " ".join(sorted(normalise(s).split()))

    name_n = normalise(name)
    name_ts = token_sort_key(name)
    name_tokens = set(name_n.split())

    # Tier 1 — exact
    for o in options:
        if normalise(o["name"]) == name_n:
            return o

    # Tier 2 — token sort (handles "Svendsen, Jesse" ↔ "Jesse Svendsen")
    for o in options:
        if token_sort_key(o["name"]) == name_ts:
            return o

    # Tier 3 — all tokens from query present in candidate
    for o in options:
        cand_tokens = set(normalise(o["name"]).split())
        if name_tokens and name_tokens.issubset(cand_tokens):
            return o

    # Tier 4 — substring
    for o in options:
        cand_n = normalise(o["name"])
        if name_n in cand_n or cand_n in name_n:
            return o

    return None

def fetch_import_lookups(ss):
    """Bulk fetch of the customer / venue / contact lookup lists used by the
    Estimate Import matcher, via /ajax/crew/import-lookups-bulk.php. Replaces
    the three paginated HTML scrapes (ss_get_customers / ss_get_venues /
    ss_get_contacts) with one round trip.

    Returns (lookups, error):
        lookups : {"customers": [...], "venues": [...], "contacts": [...],
                   "customer_map": [...]}  — customer/venue/contact ids are
                   coerced to str to match the shape the scrapers returned, so
                   the matcher and front-end are unchanged. Extra columns
                   (phone, email, postcode, ...) ride along untouched.
        error   : str or None
    On any failure returns (None, err) so the caller can fall back to the
    scrapers.
    """
    url = f"{BASE_URL}/ajax/crew/import-lookups-bulk.php"
    try:
        resp = ss.get(url, allow_redirects=True, timeout=30)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        return None, f"HTTP {resp.status_code}"
    try:
        data = json.loads(resp.text or "{}")
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return None, data["error"]

    def _norm(rows):
        out = []
        for r in (rows or []):
            d = dict(r)
            d["id"] = str(r.get("id"))
            out.append(d)
        return out

    return {
        "customers":    _norm(data.get("customers")),
        "venues":       _norm(data.get("venues")),
        "contacts":     _norm(data.get("contacts")),
        "customer_map": [
            {"customer_id": str(m.get("customer_id")),
             "user_id":     str(m.get("user_id")),
             "default":     int(m.get("default") or 0)}
            for m in (data.get("customer_map") or [])
        ],
    }, None


def _customer_contacts(customer_id, contacts, customer_map):
    """Given a matched customer id, return (scoped_contacts, default_contact):
    the subset of `contacts` linked to that customer via customer_map, plus the
    customer's default contact (default=1) if one is linked. Returns ([], None)
    when there's no customer match or no map — callers then fall back to
    matching against the full contact list (pre-bulk behaviour)."""
    if not customer_id or not customer_map:
        return [], None
    cid = str(customer_id)
    linked_ids = []
    default_id = None
    for m in customer_map:
        if str(m.get("customer_id")) == cid:
            uid = str(m.get("user_id"))
            linked_ids.append(uid)
            if m.get("default") and default_id is None:
                default_id = uid
    if not linked_ids:
        return [], None
    by_id = {str(c["id"]): c for c in contacts}
    scoped = [by_id[u] for u in linked_ids if u in by_id]
    default_contact = by_id.get(default_id) if default_id else None
    return scoped, default_contact


def _scoped_contact_match(name, all_contacts, scoped_contacts, default_contact, use_default):
    """Match a contact name, preferring the customer's own contacts.

    Order: name match within the customer's contacts -> (optionally) that
    customer's default contact -> name match against every contact. The final
    fall-through is the exact pre-bulk behaviour, so a missing/empty map or an
    unmatched customer degrades to matching against all contacts."""
    if scoped_contacts:
        m = fuzzy_match(name, scoped_contacts)
        if m:
            return m
        if use_default and default_contact:
            return default_contact
    return fuzzy_match(name, all_contacts)


def ss_create_booking(ss, booking_data):
    """POST to SmartStaff to create a booking.
    booking_data keys: booking_name, booking_date (dd-Mon-yy), invoice_ref,
                       notes, customer_id, contact_id, onsite_contact_id, venue_id
    Returns: (booking_id, error_str)

    Field names confirmed from /bookings/add form inspection:
      name, creation_date, reference, status, customer, contact, onsiteUserID, venue
    """
    resp = ss.get(f"{BASE_URL}/bookings/add")
    soup = BeautifulSoup(resp.text, "html.parser")

    post_data = {}
    form = soup.find("form")
    if form:
        for inp in form.find_all("input", {"type": "hidden"}):
            if inp.get("name"):
                post_data[inp["name"]] = inp.get("value", "")

    post_data.update({
        "name":          booking_data["booking_name"],
        "creation_date": booking_data["booking_date"],
        "status":        "0",  # 0 = Active
        "reference":     booking_data.get("invoice_ref", ""),
        "notes":         booking_data.get("notes", ""),
        "customer":      booking_data.get("customer_id", ""),
        "contact":       booking_data.get("contact_id", ""),
        "onsiteUserID":  booking_data.get("onsite_contact_id", ""),
        "venue":         booking_data.get("venue_id", ""),
        "action":        "add",
    })

    resp = ss.post(f"{BASE_URL}/bookings/add", data=post_data, allow_redirects=True)

    # SmartStaff redirects to /bookings/{id} on success
    m = re.search(r"/bookings/(\d+)", resp.url)
    if m:
        return m.group(1), None

    m = re.search(r"/bookings/(\d+)", resp.text)
    if m:
        return m.group(1), None

    return None, f"Booking creation failed — unexpected response URL: {resp.url}"

def ss_create_call(ss, booking_id, call_data):
    """POST to SmartStaff to create a call within a booking.
    URL confirmed: /bookings/{id}/callsheet/add
    Field names confirmed from form inspection:
      call_name (select), call_name_hidden, start_date, start_time, length,
      required, notes, times_filled, call_locked, edit_times,
      is_pubhol, is_pubhol_tomorrow, action
    Returns: (call_id, error_str)
    """
    resp = ss.get(f"{BASE_URL}/bookings/{booking_id}/callsheet/add")
    soup = BeautifulSoup(resp.text, "html.parser")

    post_data = {}
    form = soup.find("form")
    if form:
        for inp in form.find_all("input", {"type": "hidden"}):
            if inp.get("name"):
                post_data[inp["name"]] = inp.get("value", "")

    cn = call_data["call_name"]
    # For "Other" calls the select value is "Other" but the actual displayed
    # name is free text, carried in call_name_hidden. call_name_free overrides
    # the hidden field; labour calls leave it unset so name == select value.
    # NOTE: SmartStaff's call_name_hidden input has a typo in its HTML
    # (type="test", not "text"/"hidden"), so the form-scrape above does NOT
    # pick it up — we always set it explicitly here regardless.
    cn_hidden = call_data.get("call_name_free") or cn
    post_data.update({
        "call_name":          cn,
        "call_name_hidden":   cn_hidden,
        "start_date":         call_data["start_date"],
        "start_time":         call_data["start_time"],
        "length":             str(call_data["duration_hours"]),
        "required":           str(call_data["crew_required"]),
        "notes":              call_data.get("notes", "") or "",
        "action":             "add",
    })

    # Checkboxes — only include in POST if True (unchecked fields are omitted)
    if call_data.get("times_filled"):
        post_data["times_filled"] = "1"
    if call_data.get("call_locked"):
        post_data["call_locked"] = "1"
    if call_data.get("crew_can_edit_times"):
        post_data["edit_times"] = "1"
    if call_data.get("public_holiday_same_day"):
        post_data["is_pubhol"] = "1"
    if call_data.get("public_holiday_next_day"):
        post_data["is_pubhol_tomorrow"] = "1"

    resp = ss.post(
        f"{BASE_URL}/bookings/{booking_id}/callsheet/add",
        data=post_data,
        allow_redirects=True
    )

    final_url = resp.url.replace("//bookings", "/bookings")  # fix double-slash if present

    # Success: redirected to a specific callsheet
    m = re.search(r"/bookings/\d+/callsheet/(\d+)", final_url)
    if m:
        return m.group(1), None

    # Also check response body for callsheet link
    m = re.search(r"/bookings/\d+/callsheet/(\d+)", resp.text)
    if m:
        return m.group(1), None

    # SmartStaff redirects to /bookings/{id} after successful call creation
    # (confirmed from live testing — treat this as success)
    if re.search(r"/bookings/\d+$", final_url.rstrip("/")):
        return "created", None

    return None, f"Call creation failed — unexpected response: {resp.url}"

def ss_create_venue(ss, data):
    """Create a venue via SmartStaff venues/add in ajax mode (returns bare id).
    data keys: venue (required), active(bool), address, suburb, state, postcode, has_induction(bool)
    Returns (venue_id, error_str). add-venue.php has no required-field validation,
    so a non-numeric response means auth/permission failure, not a field error."""
    post = {
        "action":   "add",
        "ajax":     "1",
        "venue":    (data.get("venue") or "").strip(),
        "address":  data.get("address", ""),
        "suburb":   data.get("suburb", ""),
        "state":    data.get("state", ""),
        "postcode": data.get("postcode", ""),
    }
    # add-venue.php checkboxes are presence-based (isset), so only send when on
    if data.get("active", True):
        post["active"] = "1"
    if data.get("has_induction"):
        post["has_induction"] = "1"

    resp = ss.post(f"{BASE_URL}/venues/add", data=post, allow_redirects=True)
    txt = (resp.text or "").strip()
    if txt.isdigit():
        return txt, None
    return None, f"Venue creation failed — unexpected response: {txt[:200]}"

def ss_create_customer(ss, data):
    """Quick-add a customer via SmartStaff customer/add in ajax mode (returns id).
    Option (a): lean path — only customer_name is meaningful. add-customer.php still
    inserts a linked portal users row (usergroupID 42) from the blank username/password
    we send; accepted side effect of reusing the existing endpoint.
    data keys: customer_name (required), active(bool), phone, email
    Returns (customer_id, error_str)."""
    post = {
        "action":            "add",
        "ajax":              "1",
        "customer_name":     (data.get("customer_name") or "").strip(),
        "customer_username": "",
        "customer_password": "",
        "phone":             data.get("phone", ""),
        "email":             data.get("email", ""),
        "active":            "1" if data.get("active", True) else "",
    }
    resp = ss.post(f"{BASE_URL}/customer/add", data=post, allow_redirects=True)
    txt = (resp.text or "").strip()
    if txt.isdigit():
        return txt, None
    # add-customer.php reads ->info_hash off a null object on new adds; if the PHP
    # config emits notices before the ajax die(), the id is still the trailing token.
    m = re.search(r"(\d+)\s*$", txt)
    if m and "fatal error" not in txt.lower():
        return m.group(1), None
    return None, f"Customer creation failed — unexpected response: {txt[:200]}"

def ss_create_contact(ss, customer_id, data):
    """Quick-add a contact via SmartStaff contact/add (customerID rides in the
    query string). add-contact.php maps the new user to the customer via
    customer_map and returns the bare user id in ajax mode. A username collision
    (or any validation error) makes the PHP re-render the full add-contact page
    instead of an id, so a non-numeric response is treated as failure.
    data keys: username (required, unique), firstname, lastname, mobile, phone, email, active(bool)
    Returns (contact_id, error_str)."""
    post = {
        "action":    "add",
        "ajax":      "1",
        "username":  (data.get("username") or "").strip(),
        "firstname": data.get("firstname", ""),
        "lastname":  data.get("lastname", ""),
        "mobile":    data.get("mobile", ""),
        "phone":     data.get("phone", ""),
        "email":     data.get("email", ""),
        "active":    "1" if data.get("active", True) else "",
    }
    url = f"{BASE_URL}/contact/add?customerID={customer_id}"
    resp = ss.post(url, data=post, allow_redirects=True)
    txt = (resp.text or "").strip()
    if txt.isdigit():
        return txt, None
    # add-contact.php does not echo its error text; the usual cause of a non-id
    # response is a duplicate username (note: a blank username collides with the
    # credential-less portal users created by lean customer quick-adds).
    return None, "Couldn't create contact — the username may already be in use. Try a different username."

# Temp password applied to a newly-created crew member. Username is their EIN,
# so the record is portal-ready immediately; new_employee is set on the add path
# (add-crew.php), which can drive a first-login change prompt. NOTE: this repo is
# public — if you'd rather the default not live in source, read it from config.json
# instead (e.g. CONFIG.get("new_crew_temp_password", "12345")).
NEW_CREW_TEMP_PASSWORD = "12345"


def ss_create_crew(ss, data, ein, password, photo=None):
    """Create a crew member (usergroupID 3) via SmartStaff crew/add in ajax mode.
    Username is set to the EIN and a temp password applied. add-crew.php force-sets
    usergroupID=3, active=1, rating=1, start_date and new_employee on the add path;
    we supply the person fields, crew-group memberships and credentials.
    Returns (user_id, error_str). A non-numeric body is a failure — the patched
    add-crew.php returns 'ERROR: ...' for a username/EIN collision."""
    post = {
        "action":            "add",
        "ajax":              "1",
        "username":          str(ein),
        "password":          password,
        "ein":               str(ein),
        "active":            "1",
        "rating":            "1",
        "firstname":         data.get("firstname", ""),
        "lastname":          data.get("lastname", ""),
        "mobile":            data.get("mobile", ""),
        "phone":             "",
        "phone_work":        "",
        "dob":               data.get("dob", ""),        # YYYY-MM-DD; add-crew.php strtotime()s it
        "address":           data.get("address", ""),
        "suburb":            data.get("suburb", ""),
        "state":             data.get("state", ""),
        "postcode":          data.get("postcode", ""),
        "email":             data.get("email", ""),
        "emergency_contact": data.get("emergency_contact", ""),
        "emergency_phone":   data.get("emergency_phone", ""),
        "notes":             data.get("notes", ""),
    }
    # groupsList[] is a repeated key (one per selected crew group id), so build a
    # list of tuples rather than a dict.
    payload = list(post.items())
    for gid in (data.get("groups") or []):
        payload.append(("groupsList[]", str(gid)))

    # Optional profile picture. Sent as a real multipart file part so PHP's
    # is_uploaded_file() passes and add-crew.php's phpThumb step writes
    # crewimg_<id>.jpg. With files=None requests falls back to urlencoded, i.e.
    # the no-photo path is byte-for-byte the old behaviour.
    files = None
    if photo is not None and getattr(photo, "filename", ""):
        files = {"profilepic": (photo.filename, photo.stream,
                                photo.mimetype or "application/octet-stream")}

    resp = ss.post(f"{BASE_URL}/crew/add", data=payload, files=files, allow_redirects=True)
    txt = (resp.text or "").strip()
    if txt.isdigit():
        return txt, None
    if txt.upper().startswith("ERROR:"):
        return None, (txt[6:].strip() or "Crew creation failed.")
    return None, f"Crew creation failed — unexpected response: {txt[:200]}"


def ss_crew_lookups(ss):
    """Crew-group options + next EIN for the Add User form, via the admin-gated
    /ajax/crew/crew-lookups.php endpoint. Returns (data_dict, error_str)."""
    url = f"{BASE_URL}/ajax/crew/crew-lookups.php"
    try:
        resp = ss.get(url, timeout=15)
    except Exception as e:
        return None, f"crew-lookups request failed: {e}"
    if resp.status_code != 200:
        return None, f"crew-lookups returned HTTP {resp.status_code}"
    try:
        return resp.json(), None
    except Exception:
        return None, "crew-lookups returned a non-JSON body"


def ss_create_booking_bulk(ss, booking, calls):
    """Create a booking and all of its calls in ONE call via create-booking.php,
    replacing the per-record scrape-and-POST path. No form scrape, no per-call
    HTTP round trips, no pacing — the endpoint does every insert server-side.

    booking : {name, creation_date, status, customer_id, contact_id,
               onsite_contact_id, venue_id, notes, reference}
    calls   : [{call_name, start_date, start_time, length, required, notes,
                is_pubhol, is_pubhol_tomorrow}, ...]  (dates may be ISO or unix)

    Returns (result, error):
        result : {booking_id, call_ids: [...], call_errors: [{index, detail}]}
        error  : str or None
    """
    url = f"{BASE_URL}/ajax/crew/create-booking.php"
    try:
        resp = ss.post(url, json={"booking": booking, "calls": calls}, timeout=60)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        detail = ""
        try:
            detail = resp.json().get("error", "")
        except Exception:
            detail = (resp.text or "")[:200]
        return None, f"HTTP {resp.status_code}: {detail}"
    try:
        data = resp.json()
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return None, data["error"]
    return data, None


def ss_update_booking_bulk(ss, booking_id, booking):
    """Edit a booking's detail fields in ONE call via update-booking.php.

    Plain UPDATE bookings of the detail columns. The endpoint deliberately never
    writes the status column, so the close/invoice/lock cascade (add-booking.php
    action=edit, status==1) can never be triggered from an edit.

    booking : {name, creation_date, customer_id, contact_id, onsite_contact_id,
               venue_id, notes, reference}  (creation_date may be ISO or unix)

    Returns (result, error):
        result : {ok, booking_id, affected_rows}
        error  : str or None
    """
    url = f"{BASE_URL}/ajax/crew/update-booking.php"
    try:
        resp = ss.post(url, params={"id": booking_id}, json={"booking": booking}, timeout=60)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        detail = ""
        try:
            detail = resp.json().get("error", "")
        except Exception:
            detail = (resp.text or "")[:200]
        return None, f"HTTP {resp.status_code}: {detail}"
    try:
        data = resp.json()
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return None, data["error"]
    return data, None


def ss_update_call_bulk(ss, call_id, call):
    """Edit a call's detail fields in ONE call via update-call.php.

    UPDATE calls (editable subset) then re-syncs every assigned crew member's
    calendar entry via SmartStaff's own $sss->addToCalendar — exactly what
    add-call.php action=edit does — so booked crew don't keep stale times after a
    time change. The endpoint never writes call_locked, so no accounting cascade.

    call : {call_name, start_date, start_time, length, required, notes}
           (start_date may be ISO or unix)

    Returns (result, error):
        result : {ok, call_id, booking_id, crew_synced, affected_rows}
        error  : str or None
    """
    url = f"{BASE_URL}/ajax/crew/update-call.php"
    try:
        resp = ss.post(url, params={"id": call_id}, json={"call": call}, timeout=60)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        detail = ""
        try:
            detail = resp.json().get("error", "")
        except Exception:
            detail = (resp.text or "")[:200]
        return None, f"HTTP {resp.status_code}: {detail}"
    try:
        data = resp.json()
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return None, data["error"]
    return data, None


def ss_link_calls_bulk(ss, action, call_ids):
    """Link or unlink a set of calls via link-calls.php (admin session).

    action   : 'link' (needs >=2 call_ids, same booking, all unlinked) or
               'unlink' (clears link_group; dissolves singleton groups).
    call_ids : list of call ids.

    Returns (result, error). On a link the endpoint returns {ok, link_group,
    call_ids}; on unlink {ok, unlinked, dissolved_singletons}.
    """
    url = f"{BASE_URL}/ajax/crew/link-calls.php"
    try:
        resp = ss.post(url, json={"action": action, "call_ids": call_ids}, timeout=60)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        detail = ""
        try:
            j = resp.json()
            detail = j.get("error", "")
            if j.get("errors"):
                detail = (detail + ": " + "; ".join(j["errors"])).strip(": ")
        except Exception:
            detail = (resp.text or "")[:200]
        return None, detail or f"HTTP {resp.status_code}"
    try:
        data = resp.json()
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return None, data["error"]
    return data, None


def ss_update_crew_status(ss, call_id, user_id, status):
    """Set one crew member's status on a call in ONE request via
    update-crew-status.php.

    Writes call_crew_map.status, then (status 5 ONLY) re-syncs that crew
    member's calendar via SmartStaff's own $sss->addToCalendar — byte-identical
    to add-call.php action=confirm. Decline/no-show/pending/unconfirmed leave the
    calendar untouched (matches native; keeps declined entries visible). No SMS
    is ever sent.

    status : one of 0 (unconfirmed), 1 (pending), 5 (confirmed), 6 (declined),
             8 (no-show). The endpoint owns the whitelist and re-validates.

    Returns (result, error):
        result : {ok, call_id, booking_id, user_id, status, calendar_synced,
                  affected_rows}
        error  : str or None
    """
    url = f"{BASE_URL}/ajax/crew/update-crew-status.php"
    try:
        resp = ss.post(url, params={"id": call_id},
                       json={"userID": user_id, "status": status}, timeout=60)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        detail = ""
        try:
            detail = resp.json().get("error", "")
        except Exception:
            detail = (resp.text or "")[:200]
        return None, f"HTTP {resp.status_code}: {detail}"
    try:
        data = resp.json()
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return None, data["error"]
    return data, None

def _build_import_payload(booking_data, lines, non_labour, resolved_call_names, earliest_date):
    """Map the import's booking_data + labour lines + non-labour items into the
    create-booking.php payload (booking + calls), plus a parallel call_meta list
    used to map the endpoint's call_ids/call_errors back to per-line log entries.

    Calls are ordered labour-lines first, then non-labour 'Other' items — the
    same order the scrape path created them in. Dates are sent ISO (YYYY-MM-DD);
    the endpoint strtotime's them in the Melbourne tz, matching the form handler.
    """
    booking_date = earliest_date or datetime.now().strftime("%Y-%m-%d")
    booking_payload = {
        "name":              booking_data["booking_name"],
        "creation_date":     booking_date,
        "status":            0,
        "customer_id":       booking_data.get("customer_id", ""),
        "contact_id":        booking_data.get("contact_id", ""),
        "onsite_contact_id": booking_data.get("onsite_contact_id", ""),
        "venue_id":          booking_data.get("venue_id", ""),
        "notes":             booking_data.get("notes", ""),
        "reference":         booking_data.get("invoice_ref", ""),
    }

    calls_payload = []
    call_meta     = []

    for ll in lines:
        cn = resolved_call_names[ll["line_id"]]
        calls_payload.append({
            "call_name":          cn,
            "start_date":         ll["date"],
            "start_time":         ll["start_time"] + ":00",
            "length":             ll["duration_hours"],
            "required":           ll["quantity"],
            "notes":              ll.get("shift_notes") or "",
            "is_pubhol":          bool(ll.get("public_holiday_same_day", False)),
            "is_pubhol_tomorrow": bool(ll.get("public_holiday_next_day", False)),
        })
        call_meta.append({
            "line_id":   ll["line_id"],
            "call_name": cn,
            "date":      ll["date"],
            "label":     f"Line {ll['line_id']} ({cn})",
        })

    nl_date = earliest_date or datetime.now().strftime("%Y-%m-%d")
    for nl in non_labour:
        item_name = nl_item_name(nl)
        lid = nl.get("line_id", "?")
        calls_payload.append({
            "call_name":  item_name,
            "start_date": nl_date,
            "start_time": "00:00:00",
            "length":     0,
            "required":   0,
            "notes":      nl_compose_notes(nl),
        })
        call_meta.append({
            "line_id":   lid,
            "call_name": f"Other: {item_name}",
            "date":      earliest_date or "",
            "label":     f"Non-labour {lid} ({item_name})",
        })

    return booking_payload, calls_payload, call_meta

def format_ss_date(date_str):
    """Convert YYYY-MM-DD → 'Month D, YYYY' for SmartStaff start_date field.
    e.g. '2026-05-21' → 'May 21, 2026'
    """
    try:
        dt = datetime.strptime(date_str, "%Y-%m-%d")
        return dt.strftime("%B ") + str(dt.day) + dt.strftime(", %Y")
    except Exception:
        return date_str

def format_booking_date(date_str):
    """Convert YYYY-MM-DD → 'dd-Mon-yy' for SmartStaff booking_date field."""
    try:
        dt = datetime.strptime(date_str, "%Y-%m-%d")
        return dt.strftime("%d-%b-%y")  # e.g. '21-May-26'
    except Exception:
        return date_str

# ─── NON-LABOUR ITEMS ─────────────────────────────────────────────────────────
# GigPower export schema 1.1 adds a `non_labour_lines` array (truck hire,
# consumables, harness hire, etc.). These are quote lines, NOT shifts — they
# carry no date/time/duration. Each becomes a SmartStaff "Other" call:
#   - call_name select = "Other"; free-text name = title (→ description fallback)
#   - date/time/length left blank → SmartStaff auto-defaults them (confirmed
#     by live test: blank submit saves a call dated to the booking date,
#     time 00:00:00, length 0, crew 0)
#   - crew_required = 0
#   - cost detail (qty × unit_cost_ex_gst) composed into the Notes field, since
#     the export emits no totals (computed downstream per the import contract)
# See GOAT_Import_Function_Spec.md §2/§5.

NON_LABOUR_CALL_NAME = "Other"  # SmartStaff select value that unlocks free-text naming

def nl_item_name(nl):
    """Lead descriptor for a non-labour line: title, falling back to description.
    Per spec §4: title is the lead field; when title is '' fall back to description.
    """
    title = (nl.get("title") or "").strip()
    if title:
        return title
    return (nl.get("description") or "").strip()

def nl_compose_notes(nl):
    """Build the SmartStaff Notes text for a non-labour line: description (if any)
    plus a computed cost summary line. The export carries no totals (spec §3), so
    quantity × unit_cost_ex_gst is computed here.
    """
    parts = []
    desc = (nl.get("description") or "").strip()
    # Only repeat description in notes if it differs from the name we're using
    # as the call's free-text name (avoids "Harness Hire / Harness Hire").
    if desc and desc != nl_item_name(nl):
        parts.append(desc)

    qty  = nl.get("quantity")
    cost = nl.get("unit_cost_ex_gst")
    if isinstance(qty, (int, float)) and isinstance(cost, (int, float)):
        line_total = qty * cost
        def money(n):
            return f"${n:,.2f}".rstrip("0").rstrip(".") if n == int(n) else f"${n:,.2f}"
        parts.append(f"Qty {qty} × {money(cost)} ex GST = {money(line_total)} ex GST")
    elif isinstance(qty, (int, float)):
        parts.append(f"Qty {qty}")

    return "\n".join(parts)

def extract_non_labour(payload):
    """Return the non_labour_lines array (empty list if absent). Tolerates the
    section being missing entirely (older 1.0 exports without it)."""
    nls = payload.get("non_labour_lines")
    return nls if isinstance(nls, list) else []

def validate_non_labour(nls):
    """Validate non-labour lines. Looser than labour: no date/time/duration
    required (they're synthesised by SmartStaff). Returns list of error strings.
    """
    errors = []
    seen_ids = set()
    for nl in nls:
        if not isinstance(nl, dict):
            errors.append("Non-labour line is not an object")
            continue
        lid = nl.get("line_id", "?")
        if lid in seen_ids:
            errors.append(f"Duplicate non-labour line_id: {lid}")
        seen_ids.add(lid)

        # Defensive: spec §4 says the Estimator filters blank rows, but handle
        # a fully-empty line rather than assume it can't happen.
        if not nl_item_name(nl):
            errors.append(f"Non-labour line {lid} has no title or description — cannot name the call")

        qty = nl.get("quantity")
        if qty is not None and (not isinstance(qty, (int, float)) or qty <= 0):
            errors.append(f"Invalid quantity on non-labour line {lid} (must be > 0 if present)")

        cost = nl.get("unit_cost_ex_gst")
        if cost is not None and not isinstance(cost, (int, float)):
            errors.append(f"Invalid unit_cost_ex_gst on non-labour line {lid} (must be a number)")

    return errors

def validate_payload(payload):
    """Validate import payload. Returns list of error strings (empty = valid)."""
    errors = []

    if not isinstance(payload, dict):
        return ["Payload must be a JSON object"]

    if payload.get("schema_version") not in ("1.0", "1.1"):
        errors.append(f"Unsupported schema version: {payload.get('schema_version')!r} (expected '1.0' or '1.1')")

    est = payload.get("estimate", {})
    if not est.get("estimate_id"):
        errors.append("Missing: estimate.estimate_id")
    if not est.get("quote_number"):
        errors.append("Missing: estimate.quote_number")
    if est.get("status") != "Approved":
        errors.append(f"Estimate status is '{est.get('status')}' — only Approved estimates can be imported")

    if not payload.get("customer", {}).get("company_name"):
        errors.append("Missing: customer.company_name")

    event = payload.get("event", {})
    if not event.get("event_name"):
        errors.append("Missing: event.event_name")

    lines = payload.get("labour_lines", [])
    non_labour = extract_non_labour(payload)
    if not lines and not non_labour:
        errors.append("No labour_lines or non_labour_lines in payload")

    seen_ids = set()
    for ll in lines:
        lid = ll.get("line_id", "?")
        if lid in seen_ids:
            errors.append(f"Duplicate line_id: {lid}")
        seen_ids.add(lid)

        qty = ll.get("quantity")
        if not isinstance(qty, int) or qty < 1:
            errors.append(f"Invalid quantity on line {lid} (must be integer ≥ 1)")

        dur = ll.get("duration_hours")
        if not isinstance(dur, (int, float)) or dur <= 0:
            errors.append(f"Invalid duration_hours on line {lid} (must be > 0)")

        date = ll.get("date", "")
        try:
            datetime.strptime(date, "%Y-%m-%d")
        except Exception:
            errors.append(f"Invalid date '{date}' on line {lid} (expected YYYY-MM-DD)")

        time = ll.get("start_time", "")
        try:
            datetime.strptime(time, "%H:%M")
        except Exception:
            errors.append(f"Invalid start_time '{time}' on line {lid} (expected HH:MM)")

    # Non-labour lines (schema 1.1) — looser rules, no scheduling fields required
    errors.extend(validate_non_labour(non_labour))

    return errors

# ─── IMPORT STATE ─────────────────────────────────────────────────────────────

_import_progress = {}  # tracks active import progress

# ─── ROUTES ───────────────────────────────────────────────────────────────────

@app.route("/")
def index():
    if not session.get("sid") or not get_ss_session():
        return redirect(url_for("login"))
    return render_template("index.html")

@app.route("/login", methods=["GET", "POST"])
def login():
    error = None
    if request.method == "POST":
        username = request.form.get("username", "").strip()
        password = request.form.get("password", "").strip()
        remember = request.form.get("remember", "on") == "on"

        ss, err = create_ss_session(username, password)
        if err:
            error = err
        else:
            import uuid
            sid = str(uuid.uuid4())
            session["sid"] = sid
            session.permanent = remember
            _ss_sessions[sid] = ss

            # Capture identity + cohort (the source of truth for all role
            # gating). Default to least-privilege 'crew' if whoami is
            # unreachable for any reason.
            ident = fetch_whoami(ss) or {"cohort": "crew", "name": "",
                                         "ein": "", "user_id": None,
                                         "usergroupID": None}
            _ss_identity[sid] = ident
            # Per-session creds so an expired session re-auths as THIS user,
            # never as the saved admin account.
            _ss_creds[sid] = {"username": username, "password": password}
            cohort = ident.get("cohort", "crew")

            start_keepalive()  # ensure keepalive thread is running

            # The roster cache only backs the all-crew views (admin/leadership)
            # and only their sessions can build it (bulk endpoints are
            # admin/leadership only). Skip it for crew.
            if cohort in READ_ALL_COHORTS:
                trigger_cache_refresh(ss)    # rebuild crew cache (background)
                start_cache_autorefresh()    # keep it fresh on a timer

            # Only an ADMIN login may persist creds to config.json — those creds
            # double as the impersonation account used by _make_admin_ss, so a
            # non-admin "remember" must NOT overwrite them. Per-session reauth
            # uses _ss_creds instead, so non-admins still get a remembered login.
            if remember and cohort == "admin":
                cfg = load_config()
                cfg["username"] = username
                cfg["password"] = password
                save_config(cfg)

            return redirect(url_for("index"))

    # Pre-fill from config
    cfg = load_config()
    return render_template("login.html",
        error=error,
        saved_username=cfg.get("username", ""),
        version=APP_VERSION
    )

@app.route("/logout")
def logout():
    sid = session.pop("sid", None)
    if sid:
        _ss_sessions.pop(sid, None)
        _ss_identity.pop(sid, None)
        _ss_creds.pop(sid, None)
        _pre_elevation.pop(sid, None)
    return redirect(url_for("login"))

# ─── ADMIN STEP-UP ("sudo") ───────────────────────────────────────────────────
# A listed crew user (whoami.can_elevate == true) elevates to admin by
# authenticating a real usergroupID==1 account, without a separate login. The
# crew session is stashed and swapped for a freshly-authenticated admin session;
# Exit Admin restores it. The allow-list only governs who may *attempt* this —
# the grant always comes from verifying admin credentials here, so the cohort
# field still can never confer admin on its own.

@app.route("/api/elevate", methods=["POST"])
def api_elevate():
    sid = session.get("sid")
    if not sid or not get_ss_session():
        return jsonify({"error": "Not logged in"}), 401

    # Defence in depth: only a listed crew user may even attempt this. The real
    # gate is the admin credential check below.
    if not (current_identity() or {}).get("can_elevate"):
        return jsonify({"error": "Not permitted to elevate"}), 403

    body = request.get_json(silent=True) or {}
    username = (body.get("username") or "").strip()
    password = (body.get("password") or "").strip()
    if not username or not password:
        return jsonify({"error": "Username and password required"}), 400

    # Authenticate the supplied account as a SEPARATE SmartStaff session.
    ss_admin, err = create_ss_session(username, password)
    if err or not ss_admin:
        return jsonify({"error": "Invalid admin credentials"}), 401

    # It must actually be an admin (usergroupID == 1 -> cohort 'admin').
    admin_ident = fetch_whoami(ss_admin)
    if not admin_ident or admin_ident.get("cohort") != "admin":
        return jsonify({"error": "That account is not an admin"}), 403

    # Stash the crew session/identity/creds once, then swap in the admin one.
    if sid not in _pre_elevation:
        _pre_elevation[sid] = {
            "ss":    _ss_sessions.get(sid),
            "ident": _ss_identity.get(sid),
            "creds": _ss_creds.get(sid),
        }
    _ss_sessions[sid] = ss_admin
    _ss_identity[sid] = admin_ident
    # Session-only creds so an expired elevated session re-auths as admin.
    # NOT persisted to config.json — elevation is transient.
    _ss_creds[sid] = {"username": username, "password": password}

    # Operator views need the crew cache, which only an admin/leadership session
    # can build — kick it off now that we're admin.
    trigger_cache_refresh(ss_admin)
    start_cache_autorefresh()

    return jsonify({"ok": True, "cohort": "admin",
                    "name": admin_ident.get("name", "")})


@app.route("/api/exit-admin", methods=["POST"])
def api_exit_admin():
    sid = session.get("sid")
    if not sid:
        return jsonify({"error": "Not logged in"}), 401

    pre = _pre_elevation.pop(sid, None)
    if pre:
        # Restore the crew session/identity/creds. If the crew SmartStaff
        # session expired while elevated, get_ss_session() will re-auth it from
        # the restored crew creds on the next call.
        if pre.get("ss") is not None:
            _ss_sessions[sid] = pre["ss"]
        if pre.get("ident") is not None:
            _ss_identity[sid] = pre["ident"]
        if pre.get("creds") is not None:
            _ss_creds[sid] = pre["creds"]

    ident = current_identity() or {}
    return jsonify({"ok": True, "cohort": ident.get("cohort", "crew"),
                    "name": ident.get("name", "")})

@app.route("/api/elevators", methods=["GET", "POST"])
@require_cohort("admin")
def api_elevators():
    """Admin-only proxy to SmartStaff's manage-elevators.php. The browser can't
    reach SmartStaff directly, so list/add/remove of the admin-elevation EIN
    allow-list goes through the server-side admin session. 'add' also sets the
    user's cohort to 'operations' (done in the PHP), since elevators are
    operations by policy."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    if request.method == "GET":
        params = {"action": "list"}
    else:
        body = request.get_json(silent=True) or {}
        action = body.get("action")
        if action not in ("add", "remove"):
            return jsonify({"error": "action must be 'add' or 'remove'"}), 400
        ein = str(body.get("ein", "")).strip()
        if not ein.isdigit():
            return jsonify({"error": "a numeric ein is required"}), 400
        params = {"action": action, "ein": ein}

    try:
        resp = ss.get(f"{BASE_URL}/ajax/crew/manage-elevators.php",
                      params=params, timeout=15)
        return (resp.text, resp.status_code, {"Content-Type": "application/json"})
    except Exception as e:
        return jsonify({"error": f"elevators request failed: {e}"}), 502

@app.route("/api/cohort", methods=["GET", "POST"])
@require_cohort("admin")
def api_cohort():
    """Admin-only proxy to manage-cohort.php. GET lists crew currently in a
    non-default cohort (operations/leadership); POST sets a crew member's cohort
    to operations / leadership / crew. 'admin' is never settable here (that is
    usergroupID == 1). The PHP independently re-gates on admin and scopes writes
    to usergroupID == 3."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    if request.method == "GET":
        params = {"action": "list"}
    else:
        body   = request.get_json(silent=True) or {}
        ein    = str(body.get("ein", "")).strip()
        cohort = str(body.get("cohort", "")).strip().lower()
        if not ein.isdigit():
            return jsonify({"error": "a numeric ein is required"}), 400
        if cohort not in ("operations", "leadership", "crew"):
            return jsonify({"error": "cohort must be operations, leadership or crew"}), 400
        params = {"action": "set", "ein": ein, "cohort": cohort}

    try:
        resp = ss.get(f"{BASE_URL}/ajax/crew/manage-cohort.php",
                      params=params, timeout=15)
        return (resp.text, resp.status_code, {"Content-Type": "application/json"})
    except Exception as e:
        return jsonify({"error": f"cohort request failed: {e}"}), 502

@app.route("/api/calls")
@require_cohort("admin")
def api_calls():
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    force = request.args.get("force") == "1"
    sid   = session.get("sid", "")

    # Cache calls in memory per session for 2 minutes to avoid hammering SmartStaff
    cache_key = f"calls_{sid}"
    cached    = _calls_cache.get(cache_key)
    if cached and not force:
        age = (datetime.now() - cached["at"]).total_seconds()
        if age < 120:
            return jsonify({"calls": cached["calls"], "cached": True})

    calls  = None
    error  = None
    result = [None]

    def do_scrape():
        try:
            result[0] = fetch_unfilled_calls(ss)
        except Exception as e:
            result[0] = []

    t = threading.Thread(target=do_scrape)
    t.start()
    t.join(timeout=25)  # give up after 25 seconds

    calls = result[0] if result[0] is not None else []
    _calls_cache[cache_key] = {"calls": calls, "at": datetime.now()}
    return jsonify({"calls": calls})

@app.route("/api/call/<booking_id>/<call_id>")
@require_cohort("admin")
def api_call_details(booking_id, call_id):
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    details, err = scrape_call_details(ss, booking_id, call_id)
    if err:
        return jsonify({"error": err}), 500
    return jsonify(details)

@app.route("/api/booking/<booking_id>")
@require_cohort(*READ_ALL_COHORTS)
def api_booking_details(booking_id):
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    if USE_BULK_ENDPOINTS and USE_BULK_BOOKING_ENDPOINT:
        data, err = fetch_booking_bulk(ss, booking_id)
        if err is None and data is not None:
            return jsonify(data)
        print(f"[bulk-booking] endpoint failed ({err}); falling back to scrape")
    details, err = scrape_booking_details(ss, booking_id)
    if err:
        return jsonify({"error": err}), 500
    return jsonify(details)


@app.route("/api/recruitment/candidates", methods=["GET"])
@require_cohort("admin", "operations")
def api_recruitment_candidates():
    """Read-only list of job applicants for the ops recruitment review.

    Calls the deployed edge function server-side, sending our secret in the
    X-Goat-Service-Key header. The key stays in Python — the browser/index.html
    never sees it. Only admin or operations cohorts reach this (require_cohort)."""
    if not GOAT_RECRUITMENT_KEY:
        return jsonify({"error": "Recruitment key not configured"}), 500
    try:
        r = http.get(
            RECRUITMENT_CANDIDATES_URL,
            headers={"X-Goat-Service-Key": GOAT_RECRUITMENT_KEY},
            timeout=15,
        )
    except Exception as e:
        print(f"[recruitment] request failed: {e}")
        return jsonify({"error": "Recruitment service unavailable"}), 502
    if r.status_code != 200:
        print(f"[recruitment] edge function returned {r.status_code}")
        return jsonify({"error": "Recruitment service error"}), 502
    try:
        return jsonify(r.json())
    except Exception:
        return jsonify({"error": "Bad response from recruitment service"}), 502


@app.route("/api/recruitment/candidate/<cand_id>", methods=["GET"])
@require_cohort("admin", "operations")
def api_recruitment_candidate_detail(cand_id):
    """Read-only REVIEWABLE detail for ONE applicant (the expanded panel).

    Same auth + key pattern as the candidates list route above: only admin/
    operations reach it (require_cohort), and the secret key stays in Python — it
    is sent to the edge function in the X-Goat-Service-Key header and never seen
    by the browser. The browser only sends us the candidate id in the URL.

    The edge function returns an allowlisted set of fields (it deliberately
    excludes health data) plus short-lived signed URLs for the headshot/licence
    files, so the browser fetches this fresh on every expand to get URLs that are
    still valid. We just proxy the JSON straight through."""
    if not GOAT_RECRUITMENT_KEY:
        return jsonify({"error": "Recruitment key not configured"}), 500
    cand_id = str(cand_id or "").strip()
    if not cand_id:
        return jsonify({"error": "Missing applicant id"}), 400
    try:
        r = http.get(
            RECRUITMENT_CANDIDATE_DETAIL_URL,
            headers={"X-Goat-Service-Key": GOAT_RECRUITMENT_KEY},
            params={"id": cand_id},
            timeout=15,
        )
    except Exception as e:
        print(f"[recruitment] candidate-detail request failed: {e}")
        return jsonify({"error": "Recruitment service unavailable"}), 502
    if r.status_code == 404:
        return jsonify({"error": "Applicant not found"}), 404
    if r.status_code != 200:
        print(f"[recruitment] candidate-detail edge function returned {r.status_code}")
        return jsonify({"error": "Recruitment service error"}), 502
    try:
        return jsonify(r.json())
    except Exception:
        return jsonify({"error": "Bad response from recruitment service"}), 502


@app.route("/api/recruitment/candidate/<cand_id>/health", methods=["GET"])
@require_cohort("admin")
def api_recruitment_candidate_health(cand_id):
    """ADMIN-ONLY: the candidate's SEALED health answers — the most sensitive data
    we hold.

    NOTE THE GATE: @require_cohort("admin") — NOT "admin","operations" like every
    other recruitment route. This decorator IS the real access control: a logged-in
    operations user is refused here with the standard 403 ("Forbidden for your
    access level") BEFORE this function body runs, so the edge function is never
    even called for them. The health edge function trusts our gate — it has no
    cohort check of its own — which is exactly why this route must stay admin-only.

    Same key discipline as the other recruitment routes: GOAT_RECRUITMENT_KEY stays
    in Python, sent to the edge function in the X-Goat-Service-Key header, never
    seen by the browser. The edge function returns only { reference, name, health }."""
    if not GOAT_RECRUITMENT_KEY:
        return jsonify({"error": "Recruitment key not configured"}), 500
    cand_id = str(cand_id or "").strip()
    if not cand_id:
        return jsonify({"error": "Missing applicant id"}), 400
    try:
        r = http.get(
            RECRUITMENT_CANDIDATE_HEALTH_URL,
            headers={"X-Goat-Service-Key": GOAT_RECRUITMENT_KEY},
            params={"id": cand_id},
            timeout=15,
        )
    except Exception as e:
        print(f"[recruitment] candidate-health request failed: {e}")
        return jsonify({"error": "Recruitment service unavailable"}), 502
    if r.status_code == 404:
        return jsonify({"error": "Applicant not found"}), 404
    if r.status_code != 200:
        print(f"[recruitment] candidate-health edge function returned {r.status_code}")
        return jsonify({"error": "Recruitment service error"}), 502
    try:
        return jsonify(r.json())
    except Exception:
        return jsonify({"error": "Bad response from recruitment service"}), 502


@app.route("/api/recruitment/candidate/<cand_id>/work-eligibility", methods=["GET"])
@require_cohort("admin")
def api_recruitment_candidate_work_eligibility(cand_id):
    """ADMIN-ONLY: a working-visa applicant's immigration detail — passport/visa
    data, a short-lived signed URL for the visa PDF, and the AI-suggested visa
    facts.

    SAME GATE AS HEALTH: @require_cohort("admin") — NOT "admin","operations". This
    decorator IS the real access control (immigration PII): a logged-in operations
    user is refused here with the standard 403 BEFORE this body runs, so the edge
    function is never called for them. The shared candidate-detail feed only ever
    exposes work_eligibility.status; everything sensitive comes from THIS route.

    Same key discipline as the other recruitment routes: GOAT_RECRUITMENT_KEY stays
    in Python, sent in the X-Goat-Service-Key header, never seen by the browser."""
    if not GOAT_RECRUITMENT_KEY:
        return jsonify({"error": "Recruitment key not configured"}), 500
    cand_id = str(cand_id or "").strip()
    if not cand_id:
        return jsonify({"error": "Missing applicant id"}), 400
    try:
        r = http.get(
            RECRUITMENT_CANDIDATE_WORK_ELIGIBILITY_URL,
            headers={"X-Goat-Service-Key": GOAT_RECRUITMENT_KEY},
            params={"id": cand_id},
            timeout=15,
        )
    except Exception as e:
        print(f"[recruitment] candidate-work-eligibility request failed: {e}")
        return jsonify({"error": "Recruitment service unavailable"}), 502
    if r.status_code == 404:
        return jsonify({"error": "Applicant not found"}), 404
    if r.status_code != 200:
        print(f"[recruitment] candidate-work-eligibility edge function returned {r.status_code}")
        return jsonify({"error": "Recruitment service error"}), 502
    try:
        return jsonify(r.json())
    except Exception:
        return jsonify({"error": "Bad response from recruitment service"}), 502


@app.route("/api/recruitment/candidate/<cand_id>/vevo-verify", methods=["POST"])
@require_cohort("admin")
def api_recruitment_vevo_verify(cand_id):
    """ADMIN-ONLY: record (or clear) the "VEVO verified" compliance flag after an
    admin has checked a working-visa applicant's work rights via VEVO.

    Admin-gated for the same reason as the work-eligibility read: verifying work
    rights requires the visa data only admins can see. We stamp verified_by with
    the current operator's identity server-side — the browser never supplies it.
    Body from the browser is just the candidate id in the URL (+ optional
    {verified:false} to un-tick)."""
    if not GOAT_RECRUITMENT_KEY:
        return jsonify({"error": "Recruitment key not configured"}), 500
    cand_id = str(cand_id or "").strip()
    if not cand_id:
        return jsonify({"error": "Missing applicant id"}), 400

    data = request.get_json(silent=True) or {}
    # `verified` defaults to true (record the check); false clears it.
    verified = data.get("verified", True)
    # Identity is set server-side from the session, never trusted from the client.
    ident = current_identity() or {}
    verified_by = str(ident.get("name") or ident.get("ein") or "admin").strip()

    payload = {"id": cand_id, "verified": bool(verified), "verified_by": verified_by}
    try:
        r = http.post(
            RECRUITMENT_VEVO_VERIFY_URL,
            headers={"X-Goat-Service-Key": GOAT_RECRUITMENT_KEY},
            json=payload,
            timeout=15,
        )
    except Exception as e:
        print(f"[recruitment] vevo-verify request failed: {e}")
        return jsonify({"error": "Recruitment service unavailable"}), 502
    if r.status_code == 404:
        return jsonify({"error": "Applicant not found"}), 404
    if r.status_code != 200:
        print(f"[recruitment] vevo-verify edge function returned {r.status_code}")
        return jsonify({"error": "Recruitment service error"}), 502
    try:
        return jsonify(r.json())
    except Exception:
        return jsonify({"error": "Bad response from recruitment service"}), 502


@app.route("/api/recruitment/set-status", methods=["POST"])
@require_cohort("admin", "operations")
def api_recruitment_set_status():
    """Move a job applicant to a new status (triage action).

    Same auth + key pattern as the read route above: only admin/operations reach
    it (require_cohort), and the secret key stays in Python — it is sent to the
    edge function in the X-Goat-Service-Key header and never exposed to the
    browser. The browser only sends us {id, status}."""
    if not GOAT_RECRUITMENT_KEY:
        return jsonify({"error": "Recruitment key not configured"}), 500

    data = request.get_json(silent=True) or {}
    cand_id = str(data.get("id", "")).strip()
    status = str(data.get("status", "")).strip()

    # Validate here too, so we never forward junk to the edge function.
    if not cand_id:
        return jsonify({"error": "Missing applicant id"}), 400
    if status not in RECRUITMENT_VALID_STATUSES:
        return jsonify({"error": "Invalid status"}), 400

    payload = {"id": cand_id, "status": status}
    # Optional session-date fields, sent ONLY by the bookings import when marking
    # candidates "booked" (to record which TryBooking session they booked). Normal
    # single-row / batch triage never includes these keys, so their behaviour is
    # unchanged. We pass them straight through; the edge function stores them.
    # session_date may be null (the display date couldn't be parsed) while
    # session_date_text still carries the raw string — that's intentional.
    if "session_date" in data:
        payload["session_date"] = data.get("session_date")
    if "session_date_text" in data:
        payload["session_date_text"] = str(data.get("session_date_text") or "")
    # Commencement (start) date, sent by "Mark Sent to EH" — the ops-entered date
    # merged into the candidate's employment contract. Passed straight through; the
    # edge function stores it and (once set) emails the contract-signing link.
    if "commencement_date" in data:
        payload["commencement_date"] = data.get("commencement_date")

    try:
        r = http.post(
            RECRUITMENT_SET_STATUS_URL,
            headers={"X-Goat-Service-Key": GOAT_RECRUITMENT_KEY},
            json=payload,
            timeout=15,
        )
    except Exception as e:
        print(f"[recruitment] set-status request failed: {e}")
        return jsonify({"error": "Recruitment service unavailable"}), 502
    if r.status_code == 404:
        return jsonify({"error": "Applicant not found"}), 404
    if r.status_code != 200:
        print(f"[recruitment] set-status edge function returned {r.status_code}")
        return jsonify({"error": "Recruitment service error"}), 502
    try:
        return jsonify(r.json())
    except Exception:
        return jsonify({"error": "Bad response from recruitment service"}), 502


@app.route("/api/recruitment/invite", methods=["POST"])
@require_cohort("admin", "operations")
def api_recruitment_invite():
    """Email an applicant their induction booking link and mark them invited.

    Unlike set-status (which only relabels the applicant), this calls the
    recruitment-invite edge function, which sends the branded Resend email and —
    only if the email actually sends — sets status=invited_to_induction and
    stamps induction_invited_at. Same auth + key pattern as the other
    recruitment routes: only admin/operations reach it (require_cohort), and the
    secret key stays in Python (sent to the edge function in X-Goat-Service-Key,
    never seen by the browser). The browser only sends us {id}."""
    if not GOAT_RECRUITMENT_KEY:
        return jsonify({"error": "Recruitment key not configured"}), 500

    data = request.get_json(silent=True) or {}
    cand_id = str(data.get("id", "")).strip()
    if not cand_id:
        return jsonify({"error": "Missing applicant id"}), 400

    try:
        r = http.post(
            RECRUITMENT_INVITE_URL,
            headers={"X-Goat-Service-Key": GOAT_RECRUITMENT_KEY},
            json={"id": cand_id},
            timeout=15,
        )
    except Exception as e:
        print(f"[recruitment] invite request failed: {e}")
        return jsonify({"error": "Recruitment service unavailable"}), 502
    # Forward the edge function's own status + JSON so the operator sees a useful
    # message (e.g. "No induction link set", "Failed to send invite email",
    # "Candidate not found") instead of a generic error. The response also
    # carries the "reinvite" flag we surface in the UI.
    try:
        return jsonify(r.json()), r.status_code
    except Exception:
        print(f"[recruitment] invite edge function returned {r.status_code}")
        return jsonify({"error": "Recruitment service error"}), 502


# ─── CONVERT A CANDIDATE TO A SMARTSTAFF CREW MEMBER ──────────────────────────
# "Sent to EH" candidates (Supabase, via the recruitment feed) can be turned into
# a real SmartStaff crew member. This reuses the SAME crew-creation path as the
# Administration "Add Crew Member" button (ss_create_crew -> add-crew.php), which
# auto-assigns ein/username/active/rating/usergroupID and takes NO paygrade/tax
# fields. The crew WRITE goes to whatever SmartStaff BASE_URL points at (config
# .json "base_url"); keep that on the test box until you deliberately switch it.

# Status we stamp a candidate with once their crew record exists. This is NOT in
# RECRUITMENT_VALID_STATUSES on purpose: the generic /set-status proxy must not be
# able to move anyone to active_crew — only a successful conversion here may, and
# it talks to the edge function directly (below). The recruitment-set-status edge
# function must ALSO allow this value and persist smartstaff_user_id (see the
# convert route).
RECRUITMENT_CONVERTED_STATUS = "active_crew"

# Fixed licence types we push into SmartStaff during convert-B (see
# _push_candidate_licences). This list is one of three copies that must stay in
# sync (Supabase onboarding, here, and admin-add-license.php's allow-list); if it
# ever changes, all three move together. Anything NOT in this list — including
# 'Induction Certificate' — is skipped GOAT-side and rejected endpoint-side, so a
# licence write can never touch an induction row.
LICENCE_TYPE_ALLOWLIST = ["CI", "EWP", "WWC", "Forklift", "Truck", "Working at Heights"]
_LICENCE_TYPE_CANON = {t.lower(): t for t in LICENCE_TYPE_ALLOWLIST}


def _canonical_licence_type(t):
    """Map an incoming type to its canonical allow-list spelling (case-insensitive),
    or None if it isn't an allowed licence type. Returning the canonical value means
    the PHP endpoint's exact-match allow-list always agrees with us."""
    return _LICENCE_TYPE_CANON.get(str(t or "").strip().lower())


# ─── LICENCE EXPIRY COMPLIANCE ────────────────────────────────────────────────
# The Manage Crew -> Licences tab is the first place date_certified / date_expiry
# are entered, which turns licences into a light compliance tool. LICENCE_TYPES
# is derived from LICENCE_TYPE_ALLOWLIST (the single canonical list) so the two
# can never drift.
LICENCE_TYPES = tuple(LICENCE_TYPE_ALLOWLIST)

# v1 decision: chase only the hard-expiry types. A blank expiry on these is a
# real gap ("unknown"); a blank on the others is fine ("na"). A present date is
# always scored normally, whatever the type.
LICENCE_EXPIRY_EXPECTED = {
    "WWC": True, "Forklift": True, "Truck": True,
    "CI": False, "EWP": False, "Working at Heights": False,
}

LICENCE_WARN_DAYS = 60   # global v1 window (inductions use 14; the helper is parameterised)


def compliance_status(target_date, today, warn_days, expiry_expected):
    """Pure date -> status. target_date: a date/datetime or None.
    Returns one of: 'valid' | 'expiring_soon' | 'expired' | 'unknown' | 'na'.

    Deliberately domain-free — feed it any (date, expected?) pair and it yields a
    status. _compute_induction_status() already does the equivalent for inductions
    and is proven; the portability goal (one helper for both) is served by BUILDING
    this now and adopting it for inductions in a separate, low-risk change — NOT by
    folding it into this feature. Don't refactor the induction path here."""
    if target_date is None:
        return 'unknown' if expiry_expected else 'na'
    days_left = (target_date - today).days
    if days_left < 0:
        return 'expired'
    if days_left <= warn_days:
        return 'expiring_soon'
    return 'valid'


def _licence_parse_date(s):
    """Parse a 'YYYY-MM-DD' string (as admin-list-licenses.php returns) to a date,
    or None for null/blank/malformed. Never raises."""
    if not s:
        return None
    try:
        return datetime.strptime(str(s)[:10], "%Y-%m-%d").date()
    except Exception:
        return None


# Attention-first sort weight for licence status pills (mirrors the induction
# tab's {Expired:0, 'Expiring Soon':1, ...} ordering).
_LICENCE_STATUS_ORDER = {"expired": 0, "expiring_soon": 1, "unknown": 2, "valid": 3, "na": 4}


def _decorate_licences(licences):
    """Attach a compliance `status` to each licence row from admin-list-licenses
    .php, then sort attention-first. Returns a new list; input rows are copied."""
    today = datetime.now().date()
    out = []
    for lic in (licences or []):
        if not isinstance(lic, dict):
            continue
        row = dict(lic)
        ltype = row.get("type") or ""
        expected = LICENCE_EXPIRY_EXPECTED.get(ltype, False)
        row["status"] = compliance_status(
            _licence_parse_date(row.get("date_expiry")),
            today, LICENCE_WARN_DAYS, expected)
        out.append(row)
    out.sort(key=lambda r: (_LICENCE_STATUS_ORDER.get(r.get("status"), 9),
                            str(r.get("type") or "").lower()))
    return out


def _licence_date_ymd(v):
    """Normalise a Supabase date/datetime to a strict 'YYYY-MM-DD', or '' if it
    isn't a recognisable date. '' tells the endpoint to store NULL (never
    0000-00-00). Accepts '2024-05-01' and '2024-05-01T00:00:00Z' alike."""
    m = re.match(r"^(\d{4}-\d{2}-\d{2})", str(v or "").strip())
    return m.group(1) if m else ""


def ss_push_licence(ss, user_id, licence_type, date_certified, date_expiry, pdf_bytes):
    """POST ONE licence to admin-add-license.php on the shared admin session `ss`
    (admin-gated, explicit target user). Multipart when a PDF is supplied, plain
    form otherwise. Returns (result_dict, error_str). A result with
    skipped=True means the (user, type) row already existed — a normal, expected
    outcome on a retry, not an error."""
    data = {
        "user":           str(user_id),
        "type":           licence_type,
        "date_certified": date_certified or "",
        "date_expiry":    date_expiry or "",
    }
    files = None
    if pdf_bytes:
        files = {"licence_pdf": ("licence.pdf", pdf_bytes, "application/pdf")}
    try:
        resp = ss.post(f"{BASE_URL}/ajax/crew/admin-add-license.php",
                       data=data, files=files, allow_redirects=True)
    except Exception as e:
        return None, f"request failed: {e}"
    try:
        out = json.loads(resp.text or "{}")
    except Exception:
        return None, f"HTTP {resp.status_code}: {(resp.text or '')[:200]}"
    if not (isinstance(out, dict) and out.get("ok")):
        return None, (isinstance(out, dict) and out.get("error")) or f"HTTP {resp.status_code}"
    return out, None


def ss_push_contract(ss, user_id, pdf_bytes, signed_at, version):
    """POST the signed employment contract to admin-add-contract.php on the shared
    admin session `ss` (admin-gated, explicit target user). The PDF is required.
    Mirrors ss_push_licence. Returns (result_dict, error_str); a result with
    skipped=True means a contract row already existed (a normal retry outcome)."""
    if not pdf_bytes:
        return None, "no contract PDF"
    data = {
        "user":      str(user_id),
        "signed_at": signed_at or "",
        "version":   version or "",
    }
    files = {"contract_pdf": ("contract.pdf", pdf_bytes, "application/pdf")}
    try:
        resp = ss.post(f"{BASE_URL}/ajax/crew/admin-add-contract.php",
                       data=data, files=files, allow_redirects=True)
    except Exception as e:
        return None, f"request failed: {e}"
    try:
        out = json.loads(resp.text or "{}")
    except Exception:
        return None, f"HTTP {resp.status_code}: {(resp.text or '')[:200]}"
    if not (isinstance(out, dict) and out.get("ok")):
        return None, (isinstance(out, dict) and out.get("error")) or f"HTTP {resp.status_code}"
    return out, None


def ss_push_visa(ss, user_id, fields, pdf_bytes):
    """POST the visa record to admin-set-visa.php on the shared admin session `ss`
    (admin-gated, explicit target user). Upserts user_visa and sets the
    users.is_visa_worker flag. `fields` is the flat form dict (only non-empty keys
    are sent); the visa PDF is optional. Mirrors ss_push_licence. Returns
    (result_dict, error_str)."""
    data = {"user": str(user_id)}
    for k, v in (fields or {}).items():
        if v is not None and v != "":
            data[k] = str(v)
    files = None
    if pdf_bytes:
        files = {"visa_pdf": ("visa.pdf", pdf_bytes, "application/pdf")}
    try:
        resp = ss.post(f"{BASE_URL}/ajax/crew/admin-set-visa.php",
                       data=data, files=files, allow_redirects=True)
    except Exception as e:
        return None, f"request failed: {e}"
    try:
        out = json.loads(resp.text or "{}")
    except Exception:
        return None, f"HTTP {resp.status_code}: {(resp.text or '')[:200]}"
    if not (isinstance(out, dict) and out.get("ok")):
        return None, (isinstance(out, dict) and out.get("error")) or f"HTTP {resp.status_code}"
    return out, None


def _push_candidate_licences(ss, user_id, licences):
    """Convert-B: push a converted candidate's onboarding licences into SmartStaff
    user_licenses, ONE admin-add-license.php call at a time (sequential — the box
    has per-session file-lock contention under concurrent writes).

    `licences` is the candidate-detail 'licences' list; each item may carry
    {type, date_certified, url(signed PDF)}. Only allow-listed types are pushed.
    The PDF is fetched from its short-lived signed URL exactly as the headshot is.

    Best-effort: every outcome is recorded and returned; nothing here raises, so a
    licence problem never aborts the conversion. Returns a list of per-licence
    dicts: {type, ok, skipped?, id?, pdf_file?, error?}."""
    results = []
    if not isinstance(licences, list):
        return results
    for lic in licences:
        if not isinstance(lic, dict):
            continue
        canon = _canonical_licence_type(lic.get("type"))
        if not canon:
            continue   # not an allow-listed licence (e.g. induction cert) — skip
        date_cert = _licence_date_ymd(lic.get("date_certified"))

        # Fetch the PDF bytes from the signed URL, if the licence has one. A licence
        # with no URL is pushed as a metadata-only row (pdf_file NULL). A URL that
        # won't download is a per-licence failure — recorded, then we move on.
        pdf_bytes = None
        url = lic.get("url")
        if url:
            try:
                r = http.get(url, timeout=20)
                if r.status_code == 200 and r.content:
                    pdf_bytes = r.content
                else:
                    results.append({"type": canon, "ok": False,
                                    "error": f"PDF fetch HTTP {r.status_code}"})
                    continue
            except Exception as e:
                results.append({"type": canon, "ok": False,
                                "error": f"PDF fetch failed: {e}"})
                continue

        out, err = ss_push_licence(ss, user_id, canon, date_cert, "", pdf_bytes)
        if err:
            results.append({"type": canon, "ok": False, "error": err})
        elif out.get("skipped"):
            results.append({"type": canon, "ok": True, "skipped": True})
        else:
            results.append({"type": canon, "ok": True, "skipped": False,
                            "id": out.get("id"), "pdf_file": out.get("pdf_file")})
    return results


# ─── CONVERT-B: INDUCTIONS ────────────────────────────────────────────────────
# Push a converted candidate's onboarding inductions into SmartStaff, mirroring
# the licence push above. The onboarding form stores a single grouped code
# MELB_PARK for the five Melbourne Park arenas; we expand it to its member codes
# here and let add-my-induction.php fan the one PDF across their venue_ids.
MELB_PARK_MEMBERS = ["RLA", "MCA", "JCA", "Centrepiece", "AAMI"]

# Operator-facing labels for the convert modal (the onboarding dropdown's 17
# codes). Falls back to the raw code for anything unmapped.
INDUCTION_CODE_LABELS = {
    "MELB_PARK":         "Melbourne Park",
    "Marvel":            "Marvel Stadium",
    "MCG":               "Melbourne Cricket Ground (MCG)",
    "MCEC":              "Melbourne Convention & Exhibition Centre (MCEC)",
    "Festival Hall":     "Festival Hall",
    "Hamer Hall":        "Hamer Hall",
    "Palais":            "Palais Theatre",
    "Sidney Myer":       "Sidney Myer Music Bowl",
    "Federation Square": "Federation Square",
    "Crown":             "Crown Melbourne",
    "Docklands":         "Docklands Studios",
    "Hanging Rock":      "Hanging Rock Reserve",
    "GMHBA":             "GMHBA Stadium",
    "Mt Duneed":         "Mt Duneed Estate",
    "MOPT":              "MOPT Catwalk",
    "Royal Botanic":     "Royal Botanic Gardens",
    "Forum":             "Forum Melbourne",
}


def _induction_venue_id_for_code(code, venues):
    """Resolve one induction venue CODE ('MCG', 'RLA') to a SmartStaff venue_id,
    matching INDUCTION_VENUE_MAP's name keywords against the live venue list.
    Only matches venues flagged has_induction=1. Keywords are tried
    most-specific first (e.g. 'mcg - gate 7' before 'mcg'), so the id is the
    deterministic best match. Returns the id as a string, or None when nothing
    monitored matches."""
    for kw in INDUCTION_VENUE_MAP.get(code, []):
        for v in (venues or []):
            if v.get("has_induction") and kw in (v.get("name") or "").lower():
                return str(v.get("id"))
    return None


def _push_candidate_inductions(ss, user_id, inductions):
    """Convert-B: push a converted candidate's onboarding inductions into
    SmartStaff, ONE add-my-induction.php call per onboarding entry (sequential,
    via impersonation — the same per-session file-lock contention as licences).

    `inductions` is the candidate-detail 'inductions' list; each item may carry
    {venue_code, date, url(signed PDF)}. venue_code is a stable code; MELB_PARK
    expands to its five arenas and the PDF fans across their resolved venue_ids
    in one POST (add-my-induction.php's native fan-out).

    Idempotency mirrors the licence (user, type) skip: we read the crew member's
    current inductions once and skip venues already on file — add-my-induction
    .php INSERTs a user_licenses row per call, so a re-convert without this skip
    would duplicate it (crew_venue_induction itself self-cleans via
    delete-then-insert).

    Best-effort: every outcome is recorded and returned; nothing here raises, so
    an induction problem never aborts the conversion. Returns a list of per-entry
    dicts: {venue_code, label, ok, skipped?, error?, venue_ids?}."""
    results = []
    entries = ([i for i in inductions if isinstance(i, dict)]
               if isinstance(inductions, list) else [])
    if not entries:
        return results

    # Resolve codes against the live venue list (has_induction venues only). If
    # it can't be fetched we can't map anything — flag every entry and stop.
    venues, verr = fetch_venues_bulk(ss)
    if verr or not venues:
        for ind in entries:
            code = str(ind.get("venue_code") or "").strip()
            results.append({"venue_code": code,
                            "label": INDUCTION_CODE_LABELS.get(code, code) or "Induction",
                            "ok": False, "error": f"venue list unavailable: {verr or 'empty'}"})
        return results

    # Idempotency: the venue_ids the crew member is ALREADY inducted at (any row
    # with a completion date). Unlike admin-add-license.php, add-my-induction.php
    # has NO server-side (user, type) skip — it INSERTs a user_licenses row every
    # call — so this read is the ONLY duplicate guard. If it can't be read we
    # can't tell "nothing on file" from "unknown", so we fail safe: flag every
    # entry for retry rather than push blind and risk duplicate rows on a
    # re-convert. Conversion and licences are unaffected.
    current, cerr = fetch_crew_inductions(user_id)
    if cerr or not isinstance(current, list):
        for ind in entries:
            code = str(ind.get("venue_code") or "").strip()
            results.append({"venue_code": code,
                            "label": INDUCTION_CODE_LABELS.get(code, code) or "Induction",
                            "ok": False,
                            "error": f"couldn't read existing inductions: {cerr or 'no data'}"})
        return results
    present_ids = set()
    for v in current:
        if v.get("complete_ts"):
            present_ids.add(str(v.get("venue_id")))

    for ind in entries:
        code  = str(ind.get("venue_code") or "").strip()
        label = INDUCTION_CODE_LABELS.get(code, code) or "Induction"
        member_codes = MELB_PARK_MEMBERS if code == "MELB_PARK" else [code]

        # Resolve every member code to a monitored venue_id. Unresolvable members
        # drop out; if NONE resolve, flag the whole entry (never guess).
        resolved_ids = []
        for mc in member_codes:
            vid = _induction_venue_id_for_code(mc, venues)
            if vid and vid not in resolved_ids:
                resolved_ids.append(vid)
        if not resolved_ids:
            results.append({"venue_code": code, "label": label, "ok": False,
                            "error": "unresolved venue"})
            continue

        # Skip venues already on file; a group whose every member is present is a
        # whole-entry skip. This is what stops duplicate user_licenses rows.
        missing_ids = [vid for vid in resolved_ids if vid not in present_ids]
        if not missing_ids:
            results.append({"venue_code": code, "label": label, "ok": True, "skipped": True})
            continue

        # Date -> strict YYYY-MM-DD (NULL-safe, reusing the licence normaliser). A
        # blank/malformed date is skipped and flagged, never written as 0000-00-00.
        cdate = _licence_date_ymd(ind.get("date"))
        if not cdate:
            results.append({"venue_code": code, "label": label, "ok": False,
                            "error": "missing or invalid date"})
            continue

        # Certificate PDF from the signed URL (same download path as licences). No
        # URL, or a failed download, is a per-entry failure — an induction without
        # its certificate isn't useful.
        url = ind.get("url")
        if not url:
            results.append({"venue_code": code, "label": label, "ok": False,
                            "error": "no certificate PDF"})
            continue
        try:
            r = http.get(url, timeout=20)
            if r.status_code == 200 and r.content:
                pdf_bytes = r.content
            else:
                results.append({"venue_code": code, "label": label, "ok": False,
                                "error": f"PDF fetch HTTP {r.status_code}"})
                continue
        except Exception as e:
            results.append({"venue_code": code, "label": label, "ok": False,
                            "error": f"PDF fetch failed: {e}"})
            continue

        # One POST for the entry — the PDF fans across all missing venue_ids.
        # add_crew_induction reads cert.read()/cert.filename, so hand it a tiny
        # file-like carrying the bytes.
        cert = types.SimpleNamespace(read=lambda b=pdf_bytes: b,
                                     filename="induction.pdf")
        out, err = add_crew_induction(user_id, ",".join(missing_ids), cdate, cert)
        if err:
            results.append({"venue_code": code, "label": label, "ok": False, "error": err})
        else:
            for vid in missing_ids:        # so a duplicate entry THIS run skips too
                present_ids.add(vid)
            results.append({"venue_code": code, "label": label, "ok": True,
                            "skipped": False, "venue_ids": missing_ids})
    return results


# ─── CONVERT-B: CONTRACT + VISA ───────────────────────────────────────────────
# Carry the signed employment contract (all crew) and — for working-visa crew —
# the visa PDF + visa fields across to SmartStaff, the system of record for active
# crew. Same best-effort, idempotent, sequential contract as the licence/induction
# pushes: nothing here raises, so a contract/visa problem never aborts the
# conversion; both SmartStaff endpoints are idempotent per user, so a partial/
# failed push is simply retried by re-running convert.
def _push_candidate_contract(ss, user_id, contract):
    """Push the candidate's signed contract PDF into SmartStaff user_documents
    (admin-add-contract.php). `contract` is the candidate-detail 'contract' block
    {url(signed PDF), signed_at, version}. Returns a single {ok, skipped?, error?}
    dict; never raises."""
    if not isinstance(contract, dict) or not contract.get("url"):
        return {"ok": False, "error": "no signed contract on file"}
    try:
        r = http.get(contract.get("url"), timeout=20)
        if r.status_code != 200 or not r.content:
            return {"ok": False, "error": f"PDF fetch HTTP {r.status_code}"}
        pdf_bytes = r.content
    except Exception as e:
        return {"ok": False, "error": f"PDF fetch failed: {e}"}

    out, err = ss_push_contract(ss, user_id, pdf_bytes,
                                contract.get("signed_at"), contract.get("version"))
    if err:
        return {"ok": False, "error": err}
    if out.get("skipped"):
        return {"ok": True, "skipped": True}
    return {"ok": True, "skipped": False, "id": out.get("id"), "pdf_file": out.get("pdf_file")}


def _push_candidate_visa(ss, user_id, cand_id):
    """Push a working-visa candidate's visa record + PDF into SmartStaff user_visa
    (admin-set-visa.php). Reads the ADMIN-gated work-eligibility feed for the
    passport/visa fields + AI-extracted visa facts + recorded VEVO check, downloads
    the visa PDF, and upserts. Only call this when work_eligibility.status ==
    'working_visa'. Returns {ok, updated?, error?}; never raises."""
    feed, ferr = _recruit_fetch_work_eligibility(cand_id)
    if ferr or not isinstance(feed, dict):
        return {"ok": False, "error": f"work-eligibility fetch failed: {ferr or 'no data'}"}

    we = feed.get("work_eligibility") or {}
    ex = feed.get("visa_extraction") or {}
    vc = feed.get("vevo_check") or {}
    fields = {
        "work_eligibility_status": we.get("status"),
        "passport_number":   we.get("passport_number"),
        "passport_country":  we.get("passport_country"),
        "visa_subclass":     ex.get("visa_subclass"),
        "visa_grant_number": ex.get("visa_grant_number"),
        "trn":               ex.get("trn"),
        "visa_grant_date":   ex.get("visa_grant_date"),
        "visa_expiry":       ex.get("visa_expiry"),
        "visa_conditions":   ex.get("visa_conditions"),
        "vevo_verified_at":  vc.get("verified_at"),
        "vevo_verified_by":  vc.get("verified_by"),
    }
    hwl = ex.get("has_work_limitation")
    if hwl is True:
        fields["has_work_limitation"] = "1"
    elif hwl is False:
        fields["has_work_limitation"] = "0"

    # Visa PDF is optional — a metadata-only upsert still records the fields.
    pdf_bytes = None
    visa = feed.get("visa") or {}
    vurl = visa.get("url")
    if vurl:
        try:
            r = http.get(vurl, timeout=20)
            if r.status_code == 200 and r.content:
                pdf_bytes = r.content
        except Exception:
            pdf_bytes = None

    out, err = ss_push_visa(ss, user_id, fields, pdf_bytes)
    if err:
        return {"ok": False, "error": err}
    return {"ok": True, "updated": bool(out.get("updated")),
            "id": out.get("id"), "visa_pdf": out.get("visa_pdf")}


def _recruit_split_name(full):
    """Default first/last split for the preview: first word = firstname, the rest
    = lastname. Ops can edit both in the modal, so this only has to be a sane
    starting guess ("Mary Jane Watson" -> "Mary" / "Jane Watson")."""
    parts = str(full or "").split()
    if not parts:
        return "", ""
    if len(parts) == 1:
        return parts[0], ""
    return parts[0], " ".join(parts[1:])


def _recruit_norm_name(s):
    """Lowercase, drop punctuation, collapse whitespace — for tolerant name
    comparison ("de Silva" == "De  Silva.")."""
    s = re.sub(r"[^a-z0-9 ]+", " ", str(s or "").lower())
    return re.sub(r"\s+", " ", s).strip()


def _recruit_name_tokens(s):
    """Set of normalised word-tokens in a name. Order-independent, so it matches
    the roster's "Lastname, Firstname" against the candidate's "First Last"."""
    return set(t for t in _recruit_norm_name(s).split() if t)


def _recruit_crew_matches(crew, cand_first, cand_last, cand_email):
    """Duplicate guard. Given the crew roster (from fetch_crew_bulk) and the
    candidate's proposed name + email, return (email_matches, name_matches):

      - email_matches: exact email match, case-insensitive.
      - name_matches:  same OR similar first+last — identical token set, OR two
        shared name tokens, OR a high whole-string similarity (catches typos like
        "Silva"/"Silvo"). Ignores case/whitespace/punctuation.

    Each match is a small dict {name, email, ein, id} safe to show the operator.
    Inactive crew are included by the caller so returners are still caught."""
    email_l = str(cand_email or "").strip().lower()
    cand_tokens = _recruit_name_tokens(cand_first + " " + cand_last)
    cand_join = " ".join(sorted(cand_tokens))

    email_matches, name_matches = [], []
    seen_name_ids = set()
    for c in (crew or []):
        row = {
            "name":  c.get("name", "") or "",
            "email": c.get("email", "") or "",
            "ein":   str(c.get("ein") or c.get("id") or ""),
            "id":    str(c.get("id") or ""),
            "active": int(c.get("active", 1) or 0),
        }
        if email_l and row["email"].strip().lower() == email_l:
            email_matches.append(row)

        ctokens = _recruit_name_tokens(row["name"])
        if not ctokens or not cand_tokens:
            continue
        cjoin = " ".join(sorted(ctokens))
        similar = difflib.SequenceMatcher(None, cand_join, cjoin).ratio()
        if (ctokens == cand_tokens
                or len(ctokens & cand_tokens) >= 2
                or similar >= 0.87):
            if row["id"] not in seen_name_ids:
                seen_name_ids.add(row["id"])
                name_matches.append(row)
    return email_matches, name_matches


def _recruit_fetch_feed_row(cand_id):
    """Fetch the recruitment feed and return this candidate's row (dict) or
    (None, error). The feed is the authoritative source for `status`, `name` and
    `worked_with_gigpower` — the fields the convert flow gates on."""
    if not GOAT_RECRUITMENT_KEY:
        return None, "Recruitment key not configured"
    try:
        r = http.get(RECRUITMENT_CANDIDATES_URL,
                     headers={"X-Goat-Service-Key": GOAT_RECRUITMENT_KEY},
                     timeout=15)
    except Exception as e:
        print(f"[recruitment] convert feed request failed: {e}")
        return None, "Recruitment service unavailable"
    if r.status_code != 200:
        print(f"[recruitment] convert feed returned {r.status_code}")
        return None, "Recruitment service error"
    try:
        body = r.json()
    except Exception:
        return None, "Bad response from recruitment service"
    rows = body.get("candidates") if isinstance(body, dict) else body
    if not isinstance(rows, list):
        rows = []
    for c in rows:
        if str(c.get("id")) == str(cand_id):
            return c, None
    return None, "not_found"


def _recruit_fetch_detail(cand_id):
    """Fetch the candidate's reviewable detail (address/dob/emergency + a FRESH
    signed headshot URL) straight from the edge function, exactly like
    api_recruitment_candidate_detail does. Returns (dict, error)."""
    if not GOAT_RECRUITMENT_KEY:
        return None, "Recruitment key not configured"
    try:
        r = http.get(RECRUITMENT_CANDIDATE_DETAIL_URL,
                     headers={"X-Goat-Service-Key": GOAT_RECRUITMENT_KEY},
                     params={"id": cand_id}, timeout=15)
    except Exception as e:
        print(f"[recruitment] convert detail request failed: {e}")
        return None, "Recruitment service unavailable"
    if r.status_code == 404:
        return None, "not_found"
    if r.status_code != 200:
        print(f"[recruitment] convert detail returned {r.status_code}")
        return None, "Recruitment service error"
    try:
        return r.json(), None
    except Exception:
        return None, "Bad response from recruitment service"


def _recruit_fetch_work_eligibility(cand_id):
    """Fetch the ADMIN work-eligibility feed for ONE candidate (passport/visa
    fields, AI-extracted visa facts, a FRESH signed visa URL, and the recorded VEVO
    check), exactly like _recruit_fetch_detail but from the admin-gated endpoint.
    Only used by the convert-B visa push. Returns (dict, error)."""
    if not GOAT_RECRUITMENT_KEY:
        return None, "Recruitment key not configured"
    try:
        r = http.get(RECRUITMENT_CANDIDATE_WORK_ELIGIBILITY_URL,
                     headers={"X-Goat-Service-Key": GOAT_RECRUITMENT_KEY},
                     params={"id": cand_id}, timeout=15)
    except Exception as e:
        print(f"[recruitment] convert work-eligibility request failed: {e}")
        return None, "Recruitment service unavailable"
    if r.status_code == 404:
        return None, "not_found"
    if r.status_code != 200:
        print(f"[recruitment] convert work-eligibility returned {r.status_code}")
        return None, "Recruitment service error"
    try:
        return r.json(), None
    except Exception:
        return None, "Bad response from recruitment service"


def _recruit_worked_before(feed_row, detail):
    """worked_with_gigpower isn't consumed anywhere else yet and may live on the
    feed OR the detail payload, so read it from either, coercing to a real bool."""
    for src in (feed_row or {}, detail or {}):
        if "worked_with_gigpower" in src:
            v = src.get("worked_with_gigpower")
            if isinstance(v, str):
                return v.strip().lower() in ("1", "true", "yes", "y", "t")
            return bool(v)
    return False


def _recruit_convert_context(cand_id):
    """Assemble everything the convert flow needs for ONE candidate:
    (feed_row, detail, error). error is a short code: 'not_found', 'not_eh'
    handled by callers; anything else is a service error string."""
    feed_row, err = _recruit_fetch_feed_row(cand_id)
    if err:
        return None, None, err
    detail, derr = _recruit_fetch_detail(cand_id)
    if derr and derr != "not_found":
        # Detail is needed for the address/headshot but a transient detail error
        # shouldn't mask the (successful) feed read — surface it plainly.
        return feed_row, None, derr
    return feed_row, (detail or {}), None


@app.route("/api/recruitment/candidate/<cand_id>/convert-preview", methods=["GET"])
@require_cohort("admin", "operations")
def api_recruitment_convert_preview(cand_id):
    """Everything the "Convert to crew" modal needs, computed server-side, WITHOUT
    creating anything:

      - the proposed first/last name split (editable in the modal),
      - the exact fields that would be sent to SmartStaff (so ops can eyeball them),
      - the duplicate guard: exact-email + fuzzy-name matches against the live crew
        roster (fetch_crew_bulk, admin-gated), plus the worked-with-Gig-Power flag.

    Only sent_to_eh candidates are convertible; anything else returns 409 so the
    UI can explain why. The crew roster lookup needs a SmartStaff session."""
    cand_id = str(cand_id or "").strip()
    if not cand_id:
        return jsonify({"error": "Missing applicant id"}), 400
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    feed_row, detail, err = _recruit_convert_context(cand_id)
    if err == "not_found":
        return jsonify({"error": "Applicant not found"}), 404
    if err:
        return jsonify({"error": err}), 502

    status = str(feed_row.get("status") or "").strip()
    if status != "all_docs_received":
        return jsonify({"error": "Only an 'All Docs Received' candidate can be converted "
                                 f"(this one is '{status or 'unknown'}')."}), 409

    name = feed_row.get("name") or ""
    first, last = _recruit_split_name(name)
    email  = detail.get("email")  or feed_row.get("email")  or ""
    phone  = detail.get("phone")  or feed_row.get("phone")  or ""
    worked = _recruit_worked_before(feed_row, detail)

    # Duplicate guard — include inactive crew so returners are still flagged.
    crew, cerr = fetch_crew_bulk(ss, include_inactive=True)
    if cerr:
        return jsonify({"error": f"Couldn't load the crew roster to check for "
                                 f"duplicates: {cerr}"}), 502
    email_matches, name_matches = _recruit_crew_matches(crew, first, last, email)

    # "What will be sent" — the exact mapping ss_create_crew will use (minus the
    # auto-assigned ein/username/password/active/rating). Shown read-only.
    will_send = {
        "firstname":         first,
        "lastname":          last,
        "email":             email,
        "mobile":            phone,
        "dob":               detail.get("dob") or "",
        "street_address":    detail.get("street_address") or "",
        "suburb":            detail.get("suburb") or "",
        "state":             detail.get("state") or "",
        "postcode":          detail.get("postcode") or "",
        "emergency_contact": detail.get("emergency_name") or "",
        "emergency_phone":   detail.get("emergency_phone") or "",
    }
    # Work-rights signal for the Convert warning. Non-PII only: whether this is a
    # working-visa applicant (from the shared detail feed's work_eligibility.status)
    # and whether an admin has recorded the VEVO check (vevo_check). The warning
    # fires for working_visa applicants and clears once vevo_check is set. It's an
    # offence to employ a non-citizen without work rights — hence the conscious check.
    we = detail.get("work_eligibility") or {}
    working_visa = (we.get("status") == "working_visa")
    vevo_check = detail.get("vevo_check") or None
    return jsonify({
        "id":                 cand_id,
        "name":               name,
        "proposed_firstname": first,
        "proposed_lastname":  last,
        "will_send":          will_send,
        "headshot_present":   bool(detail.get("headshot_url")),
        "worked_with_gigpower": worked,
        "email_matches":      email_matches,
        "name_matches":       name_matches,
        "working_visa":       working_visa,
        "vevo_check":         vevo_check,
    })


@app.route("/api/recruitment/candidate/<cand_id>/convert", methods=["POST"])
@require_cohort("admin", "operations")
def api_recruitment_convert(cand_id):
    """Create the SmartStaff crew record for a 'Sent to EH' candidate, then stamp
    the candidate active_crew with its new SmartStaff id.

    Body: { firstname, lastname, acknowledged? }.
      - firstname/lastname: the (possibly ops-edited) name split; required.
      - acknowledged: the operator ticked "I've checked — create anyway". Required
        when the duplicate guard finds an email/name match OR the candidate said
        they've worked with Gig Power before.

    Ordering matters for retries: everything that can fail cleanly happens BEFORE
    the SmartStaff write, so a rejected conversion changes nothing (in SmartStaff
    OR Supabase) and can be retried. Once the crew record exists we can't delete
    it, so a failure to stamp Supabase afterwards is reported as a warning (the
    crew WAS created) — the operator finishes the status move by hand rather than
    re-running convert and creating a duplicate."""
    cand_id = str(cand_id or "").strip()
    if not cand_id:
        return jsonify({"error": "Missing applicant id"}), 400

    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    body = request.get_json(silent=True) or {}
    first = str(body.get("firstname", "")).strip()
    last  = str(body.get("lastname", "")).strip()
    acknowledged = bool(body.get("acknowledged"))

    # 1. Authoritative re-check: candidate must still be all_docs_received.
    feed_row, detail, err = _recruit_convert_context(cand_id)
    if err == "not_found":
        return jsonify({"error": "Applicant not found"}), 404
    if err:
        return jsonify({"error": err}), 502
    status = str(feed_row.get("status") or "").strip()
    if status != "all_docs_received":
        return jsonify({"error": "This candidate is no longer 'All Docs Received' "
                                 f"(now '{status or 'unknown'}') — refusing to "
                                 "convert."}), 409

    # 2. Name fallback (if the client somehow sent blanks, use the default split).
    if not first or not last:
        d_first, d_last = _recruit_split_name(feed_row.get("name") or "")
        first = first or d_first
        last  = last  or d_last
    if not first or not last:
        return jsonify({"error": "First and last name are required."}), 400

    email = detail.get("email") or feed_row.get("email") or ""

    # 3. Server-side duplicate guard — defence in depth. If anything is flagged and
    #    the operator hasn't acknowledged, refuse (mirrors the modal's checkbox).
    crew, cerr = fetch_crew_bulk(ss, include_inactive=True)
    if cerr:
        return jsonify({"error": f"Couldn't load the crew roster to check for "
                                 f"duplicates: {cerr}"}), 502
    email_matches, name_matches = _recruit_crew_matches(crew, first, last, email)
    worked = _recruit_worked_before(feed_row, detail)
    if (email_matches or name_matches or worked) and not acknowledged:
        return jsonify({"error": "Possible existing crew (or they've worked with "
                                 "Gig Power before) — tick the confirm box to "
                                 "create a new record anyway.",
                        "needs_ack": True}), 409

    # 4. Map candidate -> crew fields (NO paygrade/tax — SmartStaff defaults stand,
    #    matching the manual process). A short provenance note aids traceability.
    ref = feed_row.get("reference") or cand_id
    data = {
        "firstname":         first,
        "lastname":          last,
        "mobile":            detail.get("phone") or feed_row.get("phone") or "",
        "email":             email,
        "dob":               detail.get("dob") or "",   # add-crew.php strtotime()s it
        "address":           detail.get("street_address") or "",
        "suburb":            detail.get("suburb") or "",
        "state":             detail.get("state") or "",
        "postcode":          detail.get("postcode") or "",
        "emergency_contact": detail.get("emergency_name") or "",
        "emergency_phone":   detail.get("emergency_phone") or "",
        "notes":             f"Converted from recruitment application {ref}.",
        "groups":            [],
    }

    # 5. Optional headshot: fetch the bytes from the fresh signed URL and hand them
    #    to ss_create_crew as a file-like part. A photo failure is never fatal —
    #    the crew member is still created without a picture.
    photo = None
    headshot_url = detail.get("headshot_url")
    if headshot_url:
        try:
            img = http.get(headshot_url, timeout=20)
            ctype = (img.headers.get("Content-Type") or "").split(";")[0].strip()
            if img.status_code == 200 and img.content and ctype.startswith("image/"):
                ext = "png" if ctype == "image/png" else "jpg"
                photo = types.SimpleNamespace(
                    filename=f"headshot.{ext}",
                    stream=io.BytesIO(img.content),
                    mimetype=ctype or "image/jpeg")
            else:
                print(f"[recruitment] convert: headshot not usable "
                      f"(HTTP {img.status_code}, type {ctype!r})")
        except Exception as e:
            print(f"[recruitment] convert: headshot fetch failed: {e}")

    # 6. Assign the next EIN and create the crew record (points at BASE_URL).
    look, lerr = ss_crew_lookups(ss)
    if lerr:
        return jsonify({"error": f"Couldn't assign an EIN: {lerr}"}), 502
    try:
        ein = int(look.get("next_ein") or 0)
    except (TypeError, ValueError):
        ein = 0
    if ein <= 0:
        return jsonify({"error": "Couldn't determine the next EIN"}), 502

    uid, cxerr = ss_create_crew(ss, data, ein, NEW_CREW_TEMP_PASSWORD, photo=photo)
    if cxerr:
        # Nothing was written to Supabase — safe to retry (e.g. 409 EIN collision).
        return jsonify({"error": cxerr}), 502

    # 7. Crew record now EXISTS in SmartStaff. Stamp the candidate active_crew with
    #    its new id. We send status + smartstaff_user_id + ein to the set-status
    #    edge function in ONE call. NOTE: the edge function must allow the
    #    active_crew status AND persist smartstaff_user_id, or the stamp is lost.
    stamp_warning = None
    if not GOAT_RECRUITMENT_KEY:
        stamp_warning = "Recruitment key not configured — candidate not updated."
    else:
        try:
            sr = http.post(RECRUITMENT_SET_STATUS_URL,
                           headers={"X-Goat-Service-Key": GOAT_RECRUITMENT_KEY},
                           json={"id": cand_id,
                                 "status": RECRUITMENT_CONVERTED_STATUS,
                                 "smartstaff_user_id": uid,
                                 "ein": ein},
                           timeout=15)
            if sr.status_code != 200:
                print(f"[recruitment] convert stamp returned {sr.status_code}")
                stamp_warning = (f"Crew member was created (EIN {ein}) but the "
                                 f"candidate's status couldn't be updated "
                                 f"(HTTP {sr.status_code}). Update it by hand — "
                                 f"do NOT run convert again.")
        except Exception as e:
            print(f"[recruitment] convert stamp failed: {e}")
            stamp_warning = (f"Crew member was created (EIN {ein}) but the "
                             f"candidate's status update failed to send. Update it "
                             f"by hand — do NOT run convert again.")

    # 8. Convert-B — push the candidate's onboarding licences into SmartStaff
    #    user_licenses (one admin-add-license.php POST each, sequential). Purely
    #    best-effort: the endpoint is idempotent per (user, type), so a partial or
    #    failed push can simply be retried, and a licence problem never fails the
    #    conversion (the crew record + status already stand).
    try:
        licence_results = _push_candidate_licences(ss, uid, detail.get("licences"))
    except Exception as e:
        print(f"[recruitment] convert licence push crashed: {e}")
        licence_results = []

    # 9. Convert-B — push the candidate's onboarding inductions into SmartStaff
    #    (one add-my-induction.php POST per entry, sequential; a MELB_PARK entry
    #    fans one PDF across its five arenas). Same best-effort contract as the
    #    licence push: idempotent per venue, so a partial/failed push is retried
    #    by re-running convert, and an induction problem never fails the
    #    conversion (the crew record + status already stand).
    try:
        induction_results = _push_candidate_inductions(ss, uid, detail.get("inductions"))
    except Exception as e:
        print(f"[recruitment] convert induction push crashed: {e}")
        induction_results = []

    # 10. Convert-B — push the signed employment contract into SmartStaff
    #     user_documents (admin-add-contract.php). Every convertible candidate is
    #     all_docs_received, so a signed contract always exists. Best-effort +
    #     idempotent per user: a failure never fails the conversion, and a re-convert
    #     skips an already-stored contract.
    try:
        contract_result = _push_candidate_contract(ss, uid, detail.get("contract"))
    except Exception as e:
        print(f"[recruitment] convert contract push crashed: {e}")
        contract_result = {"ok": False, "error": "contract push crashed"}

    # 11. Convert-B — for a WORKING-VISA candidate only, push the visa record + PDF
    #     into SmartStaff user_visa (admin-set-visa.php), reading the admin-gated
    #     work-eligibility feed. Citizen/PR crew get no visa write. Same best-effort +
    #     idempotent (upsert per user) contract as the pushes above.
    visa_result = None
    we_status = (detail.get("work_eligibility") or {}).get("status")
    if we_status == "working_visa":
        try:
            visa_result = _push_candidate_visa(ss, uid, cand_id)
        except Exception as e:
            print(f"[recruitment] convert visa push crashed: {e}")
            visa_result = {"ok": False, "error": "visa push crashed"}

    return jsonify({
        "ok":       True,
        "id":       uid,
        "ein":      ein,
        "username": str(ein),
        "name":     f"{first} {last}".strip(),
        "status":   RECRUITMENT_CONVERTED_STATUS,
        "temp_password": NEW_CREW_TEMP_PASSWORD,
        "warning":  stamp_warning,
        "licences": licence_results,
        "inductions": induction_results,
        "contract": contract_result,
        "visa":     visa_result,
    })


# ─── COMPLETE KEYPAY SETUP ────────────────────────────────────────────────────
# The post-onboarding payroll write for an active_crew candidate. THE GOAT owns
# the button + the four-stage preview/confirm dialog ONLY; every byte of KeyPay
# traffic goes through the keypay-complete-setup edge function, which holds the
# EH_PAYROLL_API_KEY (a Supabase secret that must never reach Flask or the
# browser). See BRIEF-keypay-complete-setup.md for the full rationale.
#
# TRUST BOUNDARY (§7.2): these routes are NOT a generic pass-through. The commit
# route forwards EXACTLY three client values — candidate_id, commencement_date,
# before_hash — and drops anything else in the body. The edge function rebuilds
# the whole KeyPay payload server-side from a fresh GET + hardcoded constants, so
# the only operator-controllable value in the entire write is the commencement
# date. Relaying arbitrary JSON here would reintroduce the boundary one layer up
# and make that server-side rebuild worthless.
#
# acting_user_id (§5.1a / §7.2) is set HERE from the server session — never read
# from the request body — and the edge function writes it into its redacted
# before/after log line only. It is an opaque internal id (no name, no email),
# never enters the payload rebuild, and never gates behaviour.
#
# AUTH: ships ADMIN-ONLY (§5.1a staging — widening to operations is a separate,
# dated one-line commit after the §8 step-5 verification). Server-side cohort
# gating is authoritative; hiding the button in index.html is presentation only.
# X-Goat-Service-Key authenticates the FLASK SERVICE, not the human — both checks
# are needed. The write-enable gate lives in the edge function, not here.
def _keypay_acting_user_id():
    """Opaque internal user id for the acting operator, from the session identity.
    Log-only per §7.2 — never used for any decision, never returned to the client."""
    return str((current_identity() or {}).get("user_id", "") or "")


@app.route("/api/recruitment/candidate/<cand_id>/keypay-preview", methods=["GET"])
@require_cohort("admin")
def api_recruitment_keypay_preview(cand_id):
    """Stage 1 of the KeyPay flow: read-only. Asks the edge function to GET the EH
    employee, run the identity guard, and return the before-state + computed
    after-state (diff fields only — never the raw employee object; §5.2a).

    Preview is NEVER write-gated (§8): it is the validation the staged rollout
    depends on. We forward the edge function's own status + JSON verbatim so the
    dialog can render every §5 server state (identity mismatch, WRITE_DISABLED on
    the eventual commit, etc.) with its own specific copy."""
    if not GOAT_RECRUITMENT_KEY:
        return jsonify({"error": "Recruitment key not configured"}), 500
    cand_id = str(cand_id or "").strip()
    if not cand_id:
        return jsonify({"error": "Missing candidate id"}), 400
    # OPTIONAL Stage-2 date passthrough. The dialog re-calls preview after the
    # operator enters the commencement date so the edge computes the Start Date
    # diff row (and its overwrite verdict) server-side. Read-only and re-validated
    # independently at commit, so it does not widen the §7.2 trust boundary. Only
    # this one extra value is forwarded; anything else on the query string is
    # ignored. Omitted when absent so the edge shows its placeholder row.
    commencement_date = str(request.args.get("commencement_date", "")).strip()
    edge_body = {
        "mode":           "preview",
        "candidate_id":   cand_id,
        "acting_user_id": _keypay_acting_user_id(),  # log-only, from session
    }
    if commencement_date:
        edge_body["commencement_date"] = commencement_date
    try:
        r = http.post(
            KEYPAY_COMPLETE_SETUP_URL,
            headers={"X-Goat-Service-Key": GOAT_RECRUITMENT_KEY},
            json=edge_body,
            timeout=30,
        )
    except Exception as e:
        print(f"[keypay] preview request failed: {e}")
        return jsonify({"error": "KeyPay service unavailable"}), 502
    # Pass the edge function's status + body straight through. A read has no
    # unknown-outcome hazard, so a failure here is a plain failure.
    try:
        return jsonify(r.json()), r.status_code
    except Exception:
        print(f"[keypay] preview edge function returned {r.status_code}")
        return jsonify({"error": "KeyPay service error"}), 502


@app.route("/api/recruitment/candidate/<cand_id>/keypay-commit", methods=["POST"])
@require_cohort("admin")
def api_recruitment_keypay_commit(cand_id):
    """Stage 4 of the KeyPay flow: the live payroll write. Forwards EXACTLY
    candidate_id + commencement_date + before_hash (+ session acting_user_id);
    everything else in the body is dropped, not relayed (§7.2). The edge function
    re-GETs, re-verifies identity, checks before_hash, rebuilds the payload from
    constants, POSTs to KeyPay, asserts the returned id, and re-GETs.

    Fail-LOUD, never fail-open (§7.3), and — critically — NO auto-retry: retrying
    a write of unknown outcome is how a duplicate employee gets created. A network
    timeout here does NOT mean the write failed; the edge function may have
    completed it. So a timeout is surfaced as outcome-UNKNOWN (504 + code), which
    the dialog renders as "check KeyPay before retrying", never as a failure."""
    if not GOAT_RECRUITMENT_KEY:
        return jsonify({"error": "Recruitment key not configured"}), 500
    cand_id = str(cand_id or "").strip()
    if not cand_id:
        return jsonify({"error": "Missing candidate id"}), 400

    body = request.get_json(silent=True) or {}
    # Forward ONLY these two client-supplied values — nothing else crosses.
    commencement_date = str(body.get("commencement_date", "")).strip()
    before_hash       = str(body.get("before_hash", "")).strip()
    if not commencement_date:
        return jsonify({"error": "Missing commencement date"}), 400
    if not before_hash:
        return jsonify({"error": "Missing before_hash — re-run the preview"}), 400

    try:
        r = http.post(
            KEYPAY_COMPLETE_SETUP_URL,
            headers={"X-Goat-Service-Key": GOAT_RECRUITMENT_KEY},
            json={
                "mode":              "commit",
                "candidate_id":      cand_id,
                "commencement_date": commencement_date,
                "before_hash":       before_hash,
                "acting_user_id":    _keypay_acting_user_id(),  # log-only, from session
            },
            # Longer than the edge function's own ~30s AbortController so its
            # outcome-unknown handling wins the race rather than our socket.
            timeout=40,
        )
    except http.exceptions.Timeout:
        # Outcome unknown — the write may have landed. Do NOT report failure and
        # do NOT retry (§5.4 / §7.3). The dialog turns this into the "we didn't
        # get a response, check KeyPay" screen.
        print("[keypay] commit timed out — outcome unknown, no retry")
        return jsonify({
            "error": "No response from KeyPay — the outcome is unknown.",
            "code":  "TIMEOUT_UNKNOWN",
        }), 504
    except Exception as e:
        print(f"[keypay] commit request failed: {e}")
        return jsonify({"error": "KeyPay service unavailable"}), 502
    # Forward the edge function's own status + body verbatim so the dialog can
    # render each distinct outcome — 403 WRITE_DISABLED, 409 stale before_hash /
    # CALIBRATION_REQUIRED, a duplicate-id mismatch, KeyPay's raw validation body,
    # or success — with the right copy. A generic banner is not acceptable here.
    try:
        return jsonify(r.json()), r.status_code
    except Exception:
        print(f"[keypay] commit edge function returned {r.status_code}")
        return jsonify({"error": "KeyPay service error"}), 502


@app.route("/api/availability", methods=["POST"])
@require_cohort("admin")
def api_availability():
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    data            = request.json
    required_groups = data.get("required_groups", [])
    min_rating      = int(data.get("min_rating", 3))
    exclude_pt      = bool(data.get("exclude_pt", False))  # drop public-transport-only crew
    radius_km       = data.get("radius_km")     # None => geo filter off (back-compat)
    origin          = data.get("origin")        # {"mode":"venue"} or {"mode":"postcode","postcode":"3000"}
    only_ids        = data.get("only_crew_ids")
    spot_ids        = set(str(x) for x in only_ids) if only_ids else None

    # Support both single call (legacy) and multiple calls
    # Multi-call payload: { "calls": [ {booking_id, call_id, start_dt, end_dt, venue, call_num, call_name}, ... ] }
    # Single call payload (legacy): { booking_id, call_id, start_dt, end_dt, venue }
    raw_calls = data.get("calls")
    if not raw_calls:
        raw_calls = [{
            "booking_id": data["booking_id"],
            "call_id":    data["call_id"],
            "start_dt":   data["start_dt"],
            "end_dt":     data["end_dt"],
            "venue":      data.get("venue", ""),
            "call_num":   data.get("call_num", data["call_id"]),
            "call_name":  data.get("call_name", ""),
        }]

    # Parse target windows
    targets = []
    for c in raw_calls:
        targets.append({
            "booking_id": c["booking_id"],
            "call_id":    c["call_id"],
            "call_num":   c.get("call_num", c["call_id"]),
            "call_name":  c.get("call_name", ""),
            "start":      datetime.fromisoformat(c["start_dt"]),
            "end":        datetime.fromisoformat(c["end_dt"]),
            "venue":      c.get("venue", ""),
        })

    today = datetime.now()

    # Geo radius filter (optional). radius_km absent/falsy => disabled (back-compat).
    geo_active    = bool(radius_km)
    origin_coords = resolve_origin(origin, targets) if geo_active else None
    if geo_active and not origin_coords:
        mode = (origin or {}).get("mode")
        if mode == "venue":
            vstr = (targets[0].get("venue") if targets else "") or ""
            msg = ("No venue has been specified for this event, so distance can't be "
                   "measured from it \u2014 switch Origin to Postcode and enter one.") if not vstr.strip() else \
                  (f"Venue \"{vstr}\" isn't in the known-venue list, so distance can't be "
                   "measured from it \u2014 switch Origin to Postcode and enter one.")
        else:
            msg = "Could not resolve that postcode \u2014 check it's a valid 4-digit AU postcode."
        return jsonify({"error": msg}), 400
    location_unknown = []

    # Reference time for nearby-shift window (earliest call start)
    ref_start = min(t["start"] for t in targets)

    # Load crew list and cache (prefers bulk endpoint, falls back to scraper)
    all_crew      = _get_all_crew(ss)
     # Spot-check: narrow the roster to the requested crew before any work.
    if spot_ids is not None:
        all_crew = [c for c in all_crew if str(c.get("id")) in spot_ids]
    cache, _      = load_cache()
    updated_cache = dict(cache)

    # Build a lookup from the bulk fetch (if used) so per-crew profile lookups
    # avoid an extra HTTP request per cache miss.
    _bulk_lookup = {str(c["id"]): c for c in all_crew} if USE_BULK_ENDPOINTS else None

    available = []
    conflicts  = []
    skipped    = []

    # Step 1: filter by rating/groups using cache only (fast)
    candidates = []
    for crew in all_crew:
        cid = crew["id"]
        if cid in cache:
            groups     = cache[cid]["groups"]
            rating     = cache[cid]["rating"]
            inductions = cache[cid].get("inductions", {})
        else:
            groups, rating, inductions = _get_crew_profile(ss, cid, bulk_lookup=_bulk_lookup)
            updated_cache[crew.get("manage_id", cid)] = {
                "name":      crew["name"],
                "phone":     crew.get("phone", ""),
                "user_id":   crew.get("id", cid),
                "ein":       crew.get("ein", crew.get("id", cid)),
                "groups":    groups,
                "rating":    rating,
                "inductions": inductions,
                "postcode":  crew.get("postcode", ""),
            }

        crew["groups"]     = groups
        crew["rating"]     = rating
        crew["inductions"] = inductions
        # Ensure user_id is set for display — cache may have it stored
        if not crew.get("user_id"):
            crew["user_id"] = cache.get(cid, {}).get("user_id", cid)
        # EIN for display: prefer freshly-scraped EIN, then cached EIN, then userID
        if not crew.get("ein"):
            crew["ein"] = cache.get(cid, {}).get("ein", crew.get("id", cid))

        reasons = []
        if rating < min_rating:
            reasons.append(f"Rating {rating} < {min_rating}")
        groups_lower = [g.lower() for g in groups]
        for cert in required_groups:
            if cert.lower() not in groups_lower:
                reasons.append(f"Missing: {cert}")
        # PT-only crew rely on public transport -- exclude on request so they
        # are not offered for venues/times they cannot reasonably reach.
        if exclude_pt and "pt only" in groups_lower:
            reasons.append("PT-only (excluded)")

        # Rating/group gate: applied for a normal search; bypassed for a spot-check.
        if spot_ids is None and reasons:
            skipped.append({**crew, "reason": " | ".join(reasons)})
            continue

        # Geo filter — for a normal search this excludes out-of-radius crew. For a
        # spot-check we still compute the distance for display but never exclude.
        if geo_active:
            cpc    = crew.get("postcode") or cache.get(cid, {}).get("postcode", "")
            ccoord = postcode_to_coords(cpc)
            if not ccoord:
                if spot_ids is None:
                    location_unknown.append({**crew, "reason": "No/unknown postcode"})
                    continue
            else:
                dist = haversine_km(origin_coords[0], origin_coords[1], ccoord["lat"], ccoord["lon"])
                crew["distance_km"] = round(dist, 1)
                if spot_ids is None and dist > float(radius_km):
                    skipped.append({**crew, "reason": f"{round(dist)} km away"})
                    continue

        candidates.append(crew)

    # Step 2: fetch shifts; unavailabilities from disk cache (instant).
    # Single bulk fetch over the target window (prefers the bulk endpoint, falls
    # back to the bookings scraper). Replaces the former per-crew get_crew_shifts
    # thread pool, which under-reported upcoming bookings. Keyed by crew NAME.
    #
    # Each shift carries a status from call_crew_map (5=confirmed, 6=declined,
    # 1=pending, 8=noshow, 0=unset, None=orphan). Only status==5 represents a
    # commitment that should cause a conflict; other statuses pass through to
    # the timeline as informational so the operator has context.
    win_start = min(t["start"] for t in targets) - timedelta(days=2)
    win_end   = max(t["end"]   for t in targets) + timedelta(days=2)
    shifts_by_name = _get_shifts_for_window(ss, win_start, win_end)
    # Live unavailability read (3.4.5) — sub-second via bulk endpoint, replaces
    # the cache lookup. Keyed by str(user_id) to match the previous shape.
    unavail_cache = _get_unavails_for_window(ss, win_start, win_end)

    # Step 3: check conflicts using shifts + cached unavailabilities
    for crew in candidates:
        cid          = crew["id"]
        all_shifts   = shifts_by_name.get(crew["name"], [])
        shifts       = [s for s in all_shifts if s.get("status") == 5]  # conflict checks: confirmed only
        unavails     = unavail_cache.get(cid, [])
        inductions = crew["inductions"]

        call_results = []
        any_conflict = False
        for t in targets:
            # Exclude this target call's own shifts so a crew member already
            # booked on this call doesn't trigger a self-conflict (they're
            # already surfaced in the "Already Booked" section at the top).
            shifts_for_target = [s for s in shifts if str(s.get("call_id")) != str(t["call_id"])]
            conflict, reason = check_conflict(shifts_for_target, t["start"], t["end"], t["venue"])
            if not conflict:
                for u in unavails:
                    if datetime.fromisoformat(u["start"]) <= t["end"] and datetime.fromisoformat(u["end"]) >= t["start"]:
                        conflict = True
                        reason   = f"Unavailable: {u.get('reason','Leave/unavailable')}"
                        break

            # Induction check — only if call has a known venue
            induction_warning = ""
            if t["venue"]:
                ind_status, ind_venue = induction_status_for_venue(inductions, t["venue"])
                if ind_status == "Incomplete":
                    induction_warning = f"No induction: {t['venue']}"
                elif ind_status == "Expired":
                    induction_warning = f"Expired induction: {t['venue']}"
                elif ind_status == "Expiring Soon":
                    induction_warning = f"Expiring induction: {t['venue']}"

            call_results.append({
                "call_id":           t["call_id"],
                "call_num":          t["call_num"],
                "call_name":         t["call_name"],
                "available":         not conflict,
                "detail":            reason,
                "induction_warning": induction_warning,
            })
            if conflict:
                any_conflict = True

        avail_count = sum(1 for r in call_results if r["available"])
        total_calls = len(targets)

        # Collect all induction warnings across calls (deduplicated)
        induction_warnings = list(dict.fromkeys(
            r["induction_warning"] for r in call_results if r["induction_warning"]
        ))

        # Nearby shifts for timeline — include shifts within 3 days of ANY target call,
        # plus any shift explicitly cited in a conflict reason (may be further away).
        # NOTE: iterate all_shifts (not the confirmed-only `shifts`) so the timeline
        # can render non-confirmed entries informationally — see status filter above.
        nearby_set = set()
        nearby = []
        for s in all_shifts:
            s_start = datetime.fromisoformat(s["start"])
            in_window = any(
                abs((s_start - t["start"]).total_seconds()) <= 3 * 86400
                for t in targets
            )
            # Also include if this shift's time appears in any conflict detail string
            cited = any(
                s_start.strftime("%d %b %H:%M") in r["detail"]
                for result in call_results
                for r in [result]
                if r.get("detail")
            )
            key = s["start"]
            if (in_window or cited) and key not in nearby_set:
                nearby_set.add(key)
                nearby.append(_tag_shift_for_timeline(s, targets))

        nearby_unavails = [u for u in unavails if any(
            datetime.fromisoformat(u["end"]) >= t["start"] - timedelta(days=3)
            and datetime.fromisoformat(u["start"]) <= t["end"] + timedelta(days=3)
            for t in targets)]

        conflict_details = " | ".join(r["detail"] for r in call_results if r["detail"])

        entry = {
            **crew,
            "shifts":              nearby,
            "unavails":            nearby_unavails,
            "call_results":        call_results,
            "avail_count":         avail_count,
            "total_calls":         total_calls,
            "detail":              conflict_details,
            "induction_warnings":  induction_warnings,
        }

        if avail_count == total_calls:
            available.append(entry)
        elif avail_count == 0:
            conflicts.append(entry)
        else:
            # Partial — available for some calls; goes in a separate bucket
            # We include in available list but flagged as partial
            entry["partial"] = True
            available.append(entry)

    # Save cache
    save_cache(updated_cache)

    # Sort: full availability first, then partial, then by rating desc
    available.sort(key=lambda x: (-x["avail_count"], -x["rating"]))
    conflicts.sort(key=lambda x: x["rating"], reverse=True)

    return jsonify({
        "available":   available,
        "conflicts":   conflicts,
        "skipped":     skipped,
        "targets":     [{"call_id": t["call_id"], "booking_id": t["booking_id"], "call_num": t["call_num"],
                         "call_name": t["call_name"], "start_dt": t["start"].isoformat(),
                         "end_dt": t["end"].isoformat(), "venue": t["venue"]} for t in targets],
        # Legacy single-call fields for backward compat
        "call_id":    targets[0]["call_id"],
        "booking_id": targets[0]["booking_id"],
        # Geo radius search
        "location_unknown": location_unknown,
        "origin_label":     origin_coords[2] if origin_coords else None,
    })

@app.route("/api/cache/status")
@require_cohort(*READ_ALL_COHORTS)
def api_cache_status():
    cache, is_fresh = load_cache()
    age_str = "No cache"
    if os.path.exists(CACHE_FILE):
        try:
            with open(CACHE_FILE) as f:
                d = json.load(f)
            saved = datetime.fromisoformat(d.get("saved_at", "2000-01-01"))
            hours = (datetime.now() - saved).total_seconds() / 3600
            age_str = f"{hours:.1f} hours ago"
        except Exception:
            pass
    return jsonify({
        "fresh":    is_fresh,
        "profiles": len(cache),
        "age":      age_str,
    })

@app.route("/api/cache/refresh", methods=["POST"])
@require_cohort(*READ_ALL_COHORTS)
def api_cache_refresh():
    """Trigger a full parallel cache refresh in the background."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    # If a refresh is already running, don't start another
    if not trigger_cache_refresh(ss):
        return jsonify({"status": "Already running", "progress": _refresh_progress})

    return jsonify({"status": "Refresh started in background"})


@app.route("/api/cache/progress")
@require_cohort(*READ_ALL_COHORTS)
def api_cache_progress():
    """Return current refresh progress."""
    return jsonify(_refresh_progress)

@app.route("/api/inductions")
@require_cohort(*READ_ALL_COHORTS)
def api_inductions():
    """Return induction status for all cached crew, with expiry checking."""
    ss = get_ss_session()
    cache, _ = load_cache()
    name_map = {cid: data.get("name", "") for cid, data in cache.items()}

    all_venues = sorted({
        venue_name
        for data in cache.values()
        for venue_name in data.get("inductions", {})
        if venue_name.strip().lower() not in INDUCTION_EXCLUDE
    })

    result = []
    for cid, data in cache.items():
        groups     = data.get("groups") or []
        rating     = data.get("rating", 0)
        inductions = data.get("inductions", {})
        name       = name_map.get(cid, cid)

        venue_status = _compute_induction_status(inductions)

        result.append({"id": data.get("user_id", cid), "ein": data.get("ein", data.get("user_id", cid)), "name": name, "groups": groups, "rating": rating, "venue_status": venue_status})

    result.sort(key=lambda x: x["name"] or "")
    return jsonify({"crew": result, "venues": all_venues})
    return jsonify({"groups": [
        "W.B.", "PhD.", "CI Card", "JOAT", "Audio", "Backline",
        "Fork", "Lights", "Set/Stg", "Spot", "Truck", "Wardrobe",
        "EWP", "MCEC", "MCG", "MOPT"
    ]})

@app.route("/api/session/ping")
def api_session_ping():
    """Lightweight endpoint to check if the SmartStaff session is still valid."""
    ss = _ss_sessions.get(session.get("sid"))
    if not ss:
        return jsonify({"valid": False, "reason": "no_session"}), 401
    valid = is_ss_session_valid(ss)
    if not valid:
        # Try silent re-auth
        sid = session.get("sid")
        if reauth_ss_session(sid):
            return jsonify({"valid": True, "reauthed": True})
        return jsonify({"valid": False, "reason": "expired"}), 401
    return jsonify({"valid": True})


@app.route("/api/version")
def api_version():
    """Check for updates against GitHub version.json."""
    try:
        resp = http.get(VERSION_URL, timeout=5)
        remote = resp.json()
        remote_ver = remote.get("version", "0.0.0")
        update_available = remote_ver != APP_VERSION

        def parse_ver(v):
            try: return tuple(int(x) for x in v.split("."))
            except: return (0,0,0)

        newer = parse_ver(remote_ver) > parse_ver(APP_VERSION)
        return jsonify({
            "current":          APP_VERSION,
            "latest":           remote_ver,
            "update_available": newer,
            "dmg_url":          remote.get("dmg_url", ""),
            "release_notes":    remote.get("release_notes", ""),
            "release_date":     remote.get("release_date", ""),
        })
    except Exception as e:
        return jsonify({"current": APP_VERSION, "update_available": False, "error": str(e)})

@app.route("/api/groups")
@require_cohort(*READ_ALL_COHORTS)
def api_groups():
    # Live crew_groups list (names) for the Crew Finder filter chips. Falls back
    # to a static list if SmartStaff is unreachable or list-groups.php is absent,
    # so the sidebar still renders rather than erroring.
    fallback = ["W.B.", "PhD.", "CI Card", "JOAT", "Audio", "Backline",
                "Fork", "Lights", "Set/Stg", "Spot", "Truck", "Wardrobe",
                "EWP", "MCEC", "MCG", "MOPT"]
    ss = get_ss_session()
    if ss:
        try:
            resp = ss.get(f"{BASE_URL}/ajax/crew/list-groups.php",
                          allow_redirects=True, timeout=15)
            if resp.status_code == 200:
                data  = json.loads(resp.text or "{}")
                names = [g.get("name") for g in data.get("groups", []) if g.get("name")]
                if names:
                    return jsonify({"groups": names})
        except Exception:
            pass
    return jsonify({"groups": fallback})

@app.route("/api/crew-roster")
@require_cohort("admin")
def api_crew_roster():
    """Lightweight {id, name, ein} list for the Crew Finder spot-check autocomplete.

    Admin-only to match the Crew Finder tab. Sourced from the same bulk roster
    fetch the availability search uses, sorted by name for a clean datalist.
    """
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    roster = [
        {"id": c.get("id"), "name": c.get("name", ""), "ein": c.get("ein", c.get("id"))}
        for c in _get_all_crew(ss)
        if c.get("name")
    ]
    roster.sort(key=lambda c: c["name"].lower())
    return jsonify({"crew": roster})

# ─── FORECAST ─────────────────────────────────────────────────────────────────

# _forecast_cache (in-memory) removed in 3.4.5 — no caching layer.

@app.route("/api/forecast")
@require_cohort(*READ_ALL_COHORTS)
def api_forecast():
    """Compute and return the Crew Utilization forecast grid for a window.

    Live read since 3.4.5 — no disk cache, no in-memory cache. Shifts and
    unavailabilities come from the bulk SmartStaff endpoints (sub-second).
    Day-by-day coverage is computed inline. The `force` query param is
    accepted for backwards compatibility with existing clients but is now
    a no-op since every response is fresh.
    """
    ss = get_ss_session()
    if not ss: return jsonify({"error":"Not logged in"}), 401

    start_str = request.args.get("start_date", datetime.now().strftime("%Y-%m-%d"))
    try:    days = min(28, max(1, int(request.args.get("days", 28))))
    except: days = 28
    try:    start_dt = datetime.strptime(start_str, "%Y-%m-%d").replace(hour=0, minute=0, second=0)
    except: return jsonify({"error":"Invalid start_date"}), 400
    end_dt = start_dt + timedelta(days=days)

    crew_data, _ = load_cache()
    if not crew_data:
        return jsonify({"error":"No crew cache. Run a cache refresh first."}), 400

    # Live fetches — both sub-second via the bulk endpoints
    shifts_by_name = _get_shifts_for_window(ss, start_dt, end_dt)
    unavail_cache  = _get_unavails_for_window(ss, start_dt, end_dt)

    results = []
    for cid, info in crew_data.items():
        name     = info.get("name", "")
        shifts   = [s for s in shifts_by_name.get(name, []) if s.get("status") == 5]
        unavails = unavail_cache.get(cid, [])
        ws = [s for s in shifts   if datetime.fromisoformat(s["start"]) < end_dt and datetime.fromisoformat(s["end"]) > start_dt]
        wu = [u for u in unavails if datetime.fromisoformat(u["start"]) < end_dt and datetime.fromisoformat(u["end"]) > start_dt]

        day_hours = {}
        day_calls = {}
        total_hours = 0.0
        for s in ws:
            s_start = datetime.fromisoformat(s["start"])
            s_end   = datetime.fromisoformat(s["end"])
            cs = max(s_start, start_dt)
            ce = min(s_end,   end_dt)
            total_hours += (ce - cs).total_seconds() / 3600
            call_meta = {
                "booking": s.get("booking_name", "") or "",
                "call":    s.get("call_name", "") or "",
                "venue":   s.get("venue", "") or "",
                "time":    s_start.strftime("%H:%M") + "–" + s_end.strftime("%H:%M"),
            }
            cur = cs.replace(hour=0, minute=0, second=0, microsecond=0)
            while cur < ce:
                ds = cur.strftime("%Y-%m-%d")
                day_hours[ds] = day_hours.get(ds, 0) + (min(ce, cur + timedelta(days=1)) - max(cs, cur)).total_seconds() / 3600
                day_calls.setdefault(ds, []).append(call_meta)
                cur += timedelta(days=1)

        day_unavail = {}
        day_unavail_partial = {}
        day_unavail_hours = {}
        for u in wu:
            u_start = datetime.fromisoformat(u["start"])
            u_end   = datetime.fromisoformat(u["end"])
            us = max(u_start, start_dt)
            ue = min(u_end,   end_dt)
            cur = us.replace(hour=0, minute=0, second=0, microsecond=0)
            while cur <= ue:
                ds = cur.strftime("%Y-%m-%d")
                day_start = cur
                day_end   = cur + timedelta(days=1)
                cov_start = max(u_start, day_start)
                cov_end   = min(u_end,   day_end)
                if cov_end > cov_start:
                    starts_at_midnight = (cov_start - day_start).total_seconds() <= 60
                    ends_at_midnight   = (day_end   - cov_end).total_seconds()   <= 120
                    covers_full = starts_at_midnight and ends_at_midnight
                    if ds not in day_unavail:
                        day_unavail[ds] = u.get("reason", "Unavailable")
                        day_unavail_partial[ds] = not covers_full
                        if not covers_full:
                            day_unavail_hours[ds] = cov_start.strftime("%H:%M") + "–" + cov_end.strftime("%H:%M")
                    elif covers_full:
                        day_unavail_partial[ds] = False
                        day_unavail_hours.pop(ds, None)
                cur = cur + timedelta(days=1)

        results.append({
            "id":                 info.get("user_id", cid),
            "ein":                info.get("ein", info.get("user_id", cid)),
            "manage_id":          cid,
            "name":               name,
            "phone":              info.get("phone", ""),
            "rating":             info.get("rating", 0),
            "groups":             info.get("groups", []),
            "total_hours":        round(total_hours, 1),
            "day_hours":          day_hours,
            "day_calls":          day_calls,
            "day_unavail":        day_unavail,
            "day_unavail_partial":day_unavail_partial,
            "day_unavail_hours":  day_unavail_hours,
        })

    dates = [(start_dt + timedelta(days=i)).strftime("%Y-%m-%d") for i in range(days)]
    return jsonify({
        "start_date": start_str,
        "end_date":   end_dt.strftime("%Y-%m-%d"),
        "dates":      dates,
        "crew":       results,
    })


@app.route("/api/forecast/preload-status")
@require_cohort(*READ_ALL_COHORTS)
def api_forecast_preload_status():
    """Compatibility shim — caches were removed in 3.4.5 so there is nothing
    to preload. Reports live-mode and always-fresh so existing clients (the
    cache indicator in the header, the Crew Utilization 'cached Xm ago' label)
    don't error on missing fields. Will be retired alongside front-end refresh
    in a future release."""
    return jsonify({
        "mode":     "live",
        "forecast": {"running": False, "fresh": True, "cache_age": 0, "elapsed": 0,
                     "done": 0, "total": 0, "error": None},
        "unavail":  {"running": False, "fresh": True, "cache_age": 0, "elapsed": 0,
                     "done": 0, "total": 0, "error": None},
        "auto_refresh": {"running": False},
    })

@app.route("/api/forecast/preload", methods=["POST"])
@require_cohort(*READ_ALL_COHORTS)
def api_forecast_preload():
    """Compatibility shim — no-op since 3.4.5. Forecast and unavailability
    data are fetched live; there is no cache to preload. Returns OK so existing
    clients don't error."""
    ss = get_ss_session()
    if not ss: return jsonify({"error":"Not logged in"}), 401
    return jsonify({"status":"Live mode — no preload required"})


def scrape_schedule(ss, days=14):
    """Scrape all bookings from /bookings, return calls for the next `days` days.
    Handles pagination. Returns calls grouped by booking_id, filtered to date window.
    Also scrapes venue and contact from each booking header.
    """
    today    = datetime.now().replace(hour=0, minute=0, second=0, microsecond=0)
    end_date = today + timedelta(days=days)
    all_calls = []
    seen_calls = set()
    page = 0

    while True:
        url  = f"{BASE_URL}/bookings?p={page}" if page > 0 else f"{BASE_URL}/bookings"
        resp = ss.get(url)
        soup = BeautifulSoup(resp.text, "html.parser")

        page_calls = []
        earliest_date = None  # track earliest date on this page to know when to stop

        for link in soup.find_all("a", href=re.compile(r"bookings/(\d+)/callsheet/(\d+)")):
            href = link.get("href", "")
            m    = re.search(r"bookings/(\d+)/callsheet/(\d+)", href)
            if not m:
                continue
            booking_id = m.group(1)
            call_id    = m.group(2)
            if call_id in seen_calls:
                continue
            seen_calls.add(call_id)

            row = link.find_parent("tr")
            if not row:
                continue

            row_text = row.get_text(" ", strip=True)

            # Date
            date_m = re.search(r"(\d{2}/\d{2}/\d{2})", row_text)
            if not date_m:
                continue
            date_str = date_m.group(1)
            try:
                call_date = datetime.strptime(date_str, "%d/%m/%y")
            except ValueError:
                continue

            # Track earliest date on this page for pagination cutoff
            if earliest_date is None or call_date < earliest_date:
                earliest_date = call_date

            # Skip if outside our window
            if call_date < today or call_date >= end_date:
                continue

            # Time, length, call name, booked/required, notes
            time_m   = re.search(r"(\d{2}:\d{2})", row_text)
            length_m = re.search(r"(\d+(?:\.\d+)?)\s*hrs?", row_text, re.I)
            tds      = row.find_all("td")
            call_name = tds[5].get_text(strip=True) if len(tds) >= 6 else ""

            booked = required = 0
            for b_tag in row.find_all("b"):
                bm = re.fullmatch(r"\s*(\d{1,3})\s*/\s*(\d{1,3})\s*", b_tag.get_text())
                if bm:
                    booked   = int(bm.group(1))
                    required = int(bm.group(2))
                    break

            # Notes column — td index varies: dashboard uses td[9], bookings page may differ
            # Try td[9] first, fall back to td[10] if it looks more like notes
            notes = ""
            if len(tds) >= 10:
                n9 = tds[9].get_text(strip=True)
                n10 = tds[10].get_text(strip=True) if len(tds) >= 11 else ""
                # Notes shouldn't look like a link or short action text
                if n9 and n9.lower() not in ("view/edit", "edit", "view"):
                    notes = n9
                elif n10 and n10.lower() not in ("view/edit", "edit", "view"):
                    notes = n10

            # Booking name, venue, contact from ydisplayarea
            booking_name = venue = contact = ""
            table = row.find_parent("table", id=re.compile(r"^booking_"))
            if table:
                for sibling in table.previous_siblings:
                    if getattr(sibling, "name", None) == "div" and "ydisplayarea" in sibling.get("class", []):
                        h2 = sibling.find("h2")
                        if h2:
                            booking_name = h2.get_text(strip=True)
                        info_box = sibling.find("div", class_="bookinginfobox")
                        if info_box:
                            venue   = detect_venue(info_box.get_text()) or info_box.get_text(strip=True)[:40]
                            # Extract contact name
                            contact_m = re.search(r"Contact[:\s]+([^\n/]+)", info_box.get_text())
                            if contact_m:
                                contact = contact_m.group(1).strip()
                        break

            # Build ISO datetimes
            time_str = time_m.group(1) if time_m else "00:00"
            length   = float(length_m.group(1)) if length_m else 0
            try:
                start_dt = datetime.strptime(f"{date_str} {time_str}", "%d/%m/%y %H:%M")
                end_dt   = start_dt + timedelta(hours=length)
            except ValueError:
                start_dt = end_dt = call_date

            page_calls.append({
                "booking_id":   booking_id,
                "call_id":      call_id,
                "booking_name": booking_name,
                "venue":        venue,
                "contact":      contact,
                "call_name":    call_name,
                "date":         date_str,
                "date_iso":     call_date.strftime("%Y-%m-%d"),
                "time":         time_str,
                "length":       length,
                "start_iso":    start_dt.isoformat(),
                "end_iso":      end_dt.isoformat(),
                "booked":       booked,
                "required":     required,
                "full":         booked >= required and required > 0,
                "notes":        notes,
            })

        all_calls.extend(page_calls)

        # Stop paginating if earliest date on this page is already past our window
        # or if no calls found on this page
        if not page_calls and not any(
            link for link in soup.find_all("a", href=re.compile(r"bookings/(\d+)/callsheet/(\d+)"))
        ):
            break
        if earliest_date and earliest_date >= end_date:
            break

        # Check for next page link
        has_next = any(
            a.get_text(strip=True).upper() == "NEXT"
            for a in soup.find_all("a", href=re.compile(r"bookings\?p="))
        )
        if not has_next:
            break
        page += 1

    return sorted(all_calls, key=lambda c: (c["date_iso"], c["time"]))


_schedule_cache = {}  # {sid: {data, at}}

def fetch_calls_bulk(ss, days=14):
    """All-crew call list for the Schedule via the DB-backed get-calls-bulk.php
    (cohort-gated admin+leadership). Returns the same per-call shape as
    scrape_schedule, so api_schedule's grouping is unchanged. Used for
    leadership sessions, which can't scrape the admin /bookings pages."""
    today = datetime.now().replace(hour=0, minute=0, second=0, microsecond=0)
    start = today.strftime("%Y-%m-%d")
    end   = (today + timedelta(days=days)).strftime("%Y-%m-%d")
    try:
        resp = ss.get(f"{BASE_URL}/ajax/crew/get-calls-bulk.php?start={start}&end={end}",
                      allow_redirects=True, timeout=30)
        if resp.status_code != 200:
            app.logger.warning(f"get-calls-bulk HTTP {resp.status_code}")
            return []
        return (resp.json() or {}).get("calls", [])
    except Exception as e:
        app.logger.warning(f"get-calls-bulk failed: {e}")
        return []


def _bulk_call_to_scrape_shape(r):
    """Map one get-calls-bulk.php row to the exact dict shape scrape_calls
    returns, so /api/calls, loadCalls, and the GOAT get_calls tool are unchanged.

    Notes on the two fields the endpoint doesn't carry 1:1:
      - call_num: the DB has no per-booking call sequence number. scrape_calls
        already falls back to call_id when the dashboard '#num' is absent, and
        /api/availability defaults call_num -> call_id, so call_id is the
        behaviour-preserving value here.
      - unfilled: computed from booked/required exactly as scrape_calls does
        (the endpoint exposes 'full'; we don't rely on it, to keep the rule in
        one place)."""
    booked   = int(r.get("booked") or 0)
    required = int(r.get("required") or 0)
    return {
        "booking_id":   r.get("booking_id"),
        "call_id":      r.get("call_id"),
        "call_num":     r.get("call_id"),
        "date":         r.get("date", "") or "",
        "time":         r.get("time", "") or "",
        "length":       float(r.get("length") or 0),
        "call_name":    r.get("call_name", "") or "",
        "booked":       booked,
        "required":     required,
        "unfilled":     booked < required,
        "venue":        r.get("venue", "") or "",
        "notes":        r.get("notes", "") or "",
        "booking_name": r.get("booking_name", "") or "",
        "link_group":   r.get("link_group"),
    }


def fetch_unfilled_calls(ss, horizon_days=90):
    """Crew Finder 'Unfilled Calls' source. Prefers the DB-backed
    get-calls-bulk.php endpoint (the same one the Schedule uses), mapped into
    the scrape_calls() dict shape; falls back to the dashboard scrape on any
    failure or when the bulk path is disabled. Same feature-flag + graceful-
    degradation pattern as the other bulk reads (3.4.3-3.4.6)."""
    use_bulk = USE_BULK_ENDPOINTS and USE_BULK_CALLS_ENDPOINT
    if use_bulk:
        today = datetime.now().replace(hour=0, minute=0, second=0, microsecond=0)
        start = today.strftime("%Y-%m-%d")
        end   = (today + timedelta(days=min(horizon_days, 120))).strftime("%Y-%m-%d")
        try:
            resp = ss.get(f"{BASE_URL}/ajax/crew/get-calls-bulk.php?start={start}&end={end}",
                          allow_redirects=True, timeout=30)
            if resp.status_code == 200:
                data = resp.json() or {}
                if "error" not in data:
                    return [_bulk_call_to_scrape_shape(r) for r in data.get("calls", [])]
                app.logger.warning(f"[bulk-calls] endpoint error: {data.get('error')}; falling back to scrape")
            else:
                app.logger.warning(f"[bulk-calls] HTTP {resp.status_code}; falling back to scrape")
        except Exception as e:
            app.logger.warning(f"[bulk-calls] failed ({e}); falling back to scrape")
    return scrape_calls(ss, f"{BASE_URL}/dash")


@app.route("/api/schedule")
@require_cohort(*READ_ALL_COHORTS)
def api_schedule():
    """Return all calls for the next 14 days grouped by booking, with
    server-side clash detection per call (3.4.6).

    A call is "clashed" if any of its confirmed crew has another confirmed
    assignment in the same window that triggers check_conflict — i.e. an
    overlap (Rule 1), a long-shift gap violation (Rule 2), or a venue
    change without enough buffer (Rule 3). Single source of truth with
    Crew Finder; the front-end just renders what we deliver.
    """
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    days  = min(28, max(1, int(request.args.get("days", 14))))
    force = request.args.get("force") == "1"
    sid   = session.get("sid", "anon")
    key   = f"{sid}_{days}"

    cached = _schedule_cache.get(key)
    if not force and cached and (datetime.now() - cached["at"]).total_seconds() < 120:
        return jsonify(cached["data"])

    # Both cohorts read the Schedule from the DB-backed get-calls-bulk.php now —
    # leadership can't scrape the admin /bookings pages, and admin no longer needs
    # to. Admin keeps the scrape only as a fallback if the bulk endpoint returns
    # nothing (graceful degradation, same pattern as the other bulk migrations).
    calls = fetch_calls_bulk(ss, days=days)
    if not calls and current_cohort() not in LEADERSHIP_COHORTS:
        calls = scrape_schedule(ss, days=days)

    # Group by booking_id
    bookings = {}
    for c in calls:
        bid = c["booking_id"]
        if bid not in bookings:
            bookings[bid] = {
                "booking_id":   bid,
                "booking_name": c.get("booking_name", ""),
                "venue":        c.get("venue", ""),
                "contact":      c.get("contact", ""),
                "calls":        [],
            }
        bookings[bid]["calls"].append(c)

    # Build date axis
    today  = datetime.now().replace(hour=0, minute=0, second=0, microsecond=0)
    dates  = [(today + timedelta(days=i)).strftime("%Y-%m-%d") for i in range(days)]
    end_dt = today + timedelta(days=days)

    # ── Server-side clash detection (3.4.6) ────────────────────────────────
    # Fetch every confirmed crew-call assignment in the window via the bulk
    # endpoint. For each crew member, walk every pair of their assignments
    # and run check_conflict. Attach clashes to each affected call so the
    # front-end can render orange "clash" cells without re-deriving.
    #
    # Feature-flagged: if the endpoint isn't deployed yet, schedule still
    # works, just without clash detection.
    clashes_by_call = {}  # {call_id_str: [{name, against_call, reason}]}
    crew_by_call    = {}  # {call_id_str: [{user_id, name}]}
    if USE_BULK_BOOKED_CREW_ENDPOINT:
        assignments, err = fetch_booked_crew_bulk(ss, today, end_dt)
        if err is None:
            # Group assignments by user_id so each crew's calls are together
            by_user = {}
            for a in assignments:
                uid = a.get("user_id")
                if uid is None:
                    continue
                by_user.setdefault(uid, []).append(a)
                # Also build the per-call crew roster for the response
                cid = str(a.get("call_id", ""))
                if cid:
                    crew_by_call.setdefault(cid, []).append({
                        "user_id": uid,
                        "name":    a.get("name", ""),
                    })

            # For each user with 2+ assignments, check every pair against
            # check_conflict. If a pair clashes, BOTH calls get a clash entry.
            for uid, user_assignments in by_user.items():
                if len(user_assignments) < 2:
                    continue
                # Parse each assignment's datetimes once
                parsed = []
                for a in user_assignments:
                    try:
                        parsed.append({
                            "call_id": a.get("call_id"),
                            "name":    a.get("name", ""),
                            "start":   datetime.fromisoformat(a["start"]),
                            "end":     datetime.fromisoformat(a["end"]),
                            "venue":   a.get("venue", "") or "",
                            "raw":     a,  # for check_conflict shape
                        })
                    except (KeyError, ValueError):
                        continue
                # Pairwise: for each call A, check_conflict against every other
                # call B's shift as if B were the "existing shift" and A the
                # "target". If conflict, record on call A (and symmetrically
                # the same will be detected when A is the "other" for B).
                for i, a_call in enumerate(parsed):
                    other_shifts = [
                        {"start": p["raw"]["start"], "end": p["raw"]["end"],
                         "venue": p["venue"]}
                        for j, p in enumerate(parsed) if j != i
                    ]
                    if not other_shifts:
                        continue
                    conflict, reason = check_conflict(
                        other_shifts,
                        a_call["start"],
                        a_call["end"],
                        a_call["venue"],
                    )
                    if conflict:
                        cid = str(a_call["call_id"])
                        clashes_by_call.setdefault(cid, []).append({
                            "user_id": uid,
                            "name":    a_call["name"],
                            "reason":  reason,
                        })
        # else: endpoint failed — proceed with no clash detection (silent fallback)

    # Attach crew + clashes to each call in the payload
    bookings_list = list(bookings.values())
    for b in bookings_list:
        for c in b["calls"]:
            cid_str = str(c.get("call_id", ""))
            c["crew"]    = crew_by_call.get(cid_str, [])
            c["clashes"] = clashes_by_call.get(cid_str, [])

    payload = {
        "days":        days,
        "dates":       dates,
        "bookings":    bookings_list,
        "total_calls": len(calls),
        "total_clashes": sum(len(v) for v in clashes_by_call.values()),
    }
    _schedule_cache[key] = {"data": payload, "at": datetime.now()}
    return jsonify(payload)


@app.route("/api/booked-crew/<booking_id>/<call_id>")
@require_cohort(*READ_ALL_COHORTS)
def api_booked_crew(booking_id, call_id):
    """Crew already assigned to a call, with their confirmation status.

    Prefers the DB-backed get-booking.php (via fetch_booking_bulk), which knows
    every status including 'backup' (call_crew_map.status = 7). Falls back to
    scraping the callsheet page if that endpoint is unavailable — the legacy
    path only recognises confirmed/unconfirmed/declined and buckets everything
    else (including backup) as 'waiting'."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    # Preferred: DB-backed booking endpoint — correct statuses, incl. 'backup'.
    data, err = fetch_booking_bulk(ss, booking_id)
    if err is None and isinstance(data, dict):
        for c in (data.get("calls") or []):
            if str(c.get("call_id")) == str(call_id):
                roster = [{"name": m.get("name", ""), "status": m.get("status", "")}
                          for m in (c.get("crew") or [])]
                return jsonify({"crew": roster, "total": len(roster)})
        return jsonify({"crew": [], "total": 0})

    # Fallback: legacy callsheet scrape (only if the DB endpoint failed).
    resp = ss.get(f"{BASE_URL}/bookings/{booking_id}/callsheet/{call_id}")
    soup = BeautifulSoup(resp.text, "html.parser")


    crew = []
    status_keywords = {"confirmed", "unconfirmed", "declined", "waiting", "sent"}

    for table in soup.find_all("table"):
        rows = table.find_all("tr")
        found_in_table = []
        for row in rows[1:]:
            tds = row.find_all("td")
            if len(tds) < 3:
                continue
            name_raw = tds[1].get_text(strip=True)  # tds[0] is checkbox
            status   = tds[2].get_text(strip=True).lower()  # tds[2] is status
            if not name_raw:
                continue
            # Direct matching — unconfirmed MUST be checked before confirmed
            if "unconfirm" in status:
                status_key = "unconfirmed"
            elif "confirm" in status:
                status_key = "confirmed"
            elif "decline" in status:
                status_key = "declined"
            elif status:
                status_key = "waiting"
            else:
                continue
            # Convert "Last, First" → "First Last"
            if "," in name_raw:
                parts = name_raw.split(",", 1)
                name = parts[1].strip() + " " + parts[0].strip()
            else:
                name = name_raw.strip()
            found_in_table.append({"name": name, "status": status_key})

        if found_in_table:
            crew = found_in_table
            break

    return jsonify({"crew": crew, "total": len(crew)})


# ─── CREW CARD (name-hover popover) ───────────────────────────────────────────
# The Crew Finder name-hover card shows a crew member's profile photo + notes.
# NOTES come from users.notes via list-crew-bulk.php and ride along on each crew
# object — the frontend reads crew.notes directly (no fetch here).
# PHOTO is served through an authenticated proxy (/api/crew-photo) because the
# SmartStaff image sits behind the login session — the browser can't fetch it
# directly. The photo URL is discovered from the profile page (/crew/manage/<id>)
# and re-derived server-side on every request (never taken from the client).
#
# /api/crew-card is a photo probe / debug endpoint: GET ...?debug=1 dumps the
# image candidates + profile tabs so the headshot path can be confirmed/pinned.

_crew_card_cache = {}          # crew_id -> {"photo_url": str|None, "_debug": {...}}
_crew_card_lock  = threading.Lock()

def _discover_profile_photo(soup, crew_id):
    """Pick the crew member's profile-photo <img> src from the profile page.
    Returns (absolute_url_or_None, raw_candidate_srcs).

    Excludes the 'no photo' placeholder, licence/cert scans, buttons and chrome.
    SmartStaff shows images/nophoto.png in the avatar slot when no photo is set,
    so a member with no headshot correctly resolves to None ('No photo on file').
    """
    # Substrings that are never a profile headshot.
    bad = ("nophoto", "no_photo", "licensepic", "licenseimg", "licence",
           "licensebtn", "logo", "icon", "sprite", "spacer", "blank",
           "flag", "btn", "button", ".svg")
    # Substrings that positively look like a headshot path.
    prefer = ("crewpic", "profilepic", "userpic", "staffpic", "headshot",
              "avatar", "profile", "crewimg", "memberpic", str(crew_id))
    raw, clean = [], []
    for img in soup.find_all("img"):
        src = (img.get("src") or "").strip()
        if not src:
            continue
        raw.append(src)
        if any(b in src.lower() for b in bad):
            continue
        clean.append(src)
    chosen = None
    for src in clean:
        if any(p in src.lower() for p in prefer):
            chosen = src
            break
    if not chosen and clean:
        chosen = clean[0]   # first non-excluded image is the avatar slot
    if chosen and chosen.startswith("/"):
        chosen = BASE_URL.rstrip("/") + chosen
    elif chosen and not chosen.lower().startswith("http"):
        chosen = BASE_URL.rstrip("/") + "/" + chosen
    return chosen, raw

def _profile_debug(soup, crew_id, photo_url):
    """Verbose dump so the real headshot path can be confirmed/pinned in one pass."""
    imgs = [{"src": (i.get("src") or ""),
             "class": " ".join(i.get("class", [])),
             "id": i.get("id") or "",
             "alt": i.get("alt") or ""} for i in soup.find_all("img")]
    tabs = []
    for a in soup.find_all("a"):
        href = a.get("href") or ""
        if "manage" in href or "page=" in href:
            tabs.append({"text": a.get_text(strip=True)[:40], "href": href})
    return {"chosen_photo": photo_url, "imgs": imgs, "tabs": tabs}

@app.route("/api/crew-card/<crew_id>")
@require_cohort(*READ_ALL_COHORTS)
def api_crew_card(crew_id):
    """Photo probe for the name-hover popover (notes come from users.notes on the
    crew object, not from here). Cached per crew_id. ?debug=1 dumps candidates."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    debug = request.args.get("debug") == "1"
    with _crew_card_lock:
        cached = _crew_card_cache.get(str(crew_id))
    if cached and not debug:
        return jsonify({"photo_available": bool(cached.get("photo_url"))})

    try:
        resp = ss.get(f"{BASE_URL}/crew/manage/{crew_id}?page=1")
        soup = BeautifulSoup(resp.text, "html.parser")
    except Exception as e:
        return jsonify({"error": f"profile fetch failed: {e}"}), 502

    photo_url, _ = _discover_profile_photo(soup, crew_id)
    record = {"photo_url": photo_url, "_debug": _profile_debug(soup, crew_id, photo_url)}
    with _crew_card_lock:
        _crew_card_cache[str(crew_id)] = record

    out = {"photo_available": bool(photo_url)}
    if debug:
        out["_debug"] = record["_debug"]
        out["photo_url"] = photo_url
    return jsonify(out)

@app.route("/api/crew-photo/<crew_id>")
@require_cohort(*READ_ALL_COHORTS)
def api_crew_photo(crew_id):
    """Authenticated proxy for a crew member's profile photo, streamed back so the
    browser <img> can render an image that sits behind the SmartStaff session.

    Fast path: the deterministic `images/crewpics/crewimg_<id>.jpg`. Falls back to
    scraping the profile page only if that misses (non-standard filename), and
    caches the discovered URL so the heavy page fetch happens at most once."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    def _serve(url):
        # Only ever fetch a SmartStaff-hosted image, server-derived — no SSRF surface.
        if not url or not url.startswith(BASE_URL.rstrip("/")):
            return None
        try:
            img = ss.get(url, allow_redirects=True)
        except Exception:
            return None
        final = str(getattr(img, "url", "") or "").lower()
        ctype = img.headers.get("Content-Type", "")
        # A missing photo 404s or redirects to nophoto.png; a session timeout
        # redirects to the (non-image) login page. None of these is a photo.
        if img.status_code != 200 or not img.content or "image" not in ctype or "nophoto" in final:
            return None
        return app.response_class(img.content, mimetype=ctype,
                                  headers={"Cache-Control": "private, max-age=600"})

    # 1) Deterministic crewpics path — no profile fetch needed.
    resp = _serve(f"{BASE_URL.rstrip('/')}/images/crewpics/crewimg_{crew_id}.jpg")
    if resp is not None:
        return resp

    # 2) Fallback: discover the real filename from the profile page (cached).
    with _crew_card_lock:
        cached = _crew_card_cache.get(str(crew_id))
    photo_url = cached.get("photo_url") if cached else None
    if not photo_url:
        try:
            page = ss.get(f"{BASE_URL}/crew/manage/{crew_id}?page=1")
            soup = BeautifulSoup(page.text, "html.parser")
            photo_url, _ = _discover_profile_photo(soup, crew_id)
        except Exception:
            photo_url = None
        with _crew_card_lock:
            _crew_card_cache[str(crew_id)] = {"photo_url": photo_url}
    resp = _serve(photo_url)
    return resp if resp is not None else ("", 404)


# ─── GOAT DOMAIN GLOSSARY ─────────────────────────────────────────────────────
# Abbreviations that appear in call names/descriptions, mapped to the role/skill
# words used elsewhere (e.g. crew group names like "Video"/"Audio"/"Lights").
# Both the Ask-the-GOAT crew filter and the main GOAT assistant read this, so a
# request for "VX experience" maps onto the "Video" skill group.
#
# Built-in defaults live here; a "goat_glossary" object in config.json EXTENDS
# and OVERRIDES them. It's read fresh on each request, so adding a term to
# config.json takes effect on the next message — no code change, rebuild, or
# even a restart. Example config.json:
#   "goat_glossary": { "FX": "Special Effects, Pyro", "RX": "Rigging" }
GOAT_GLOSSARY_DEFAULT = {
    "VX": "Video",
    "SX": "Sound, Audio",
    "LX": "Lighting, Lights",
}

def goat_glossary():
    """Built-in defaults merged with any `goat_glossary` map in config.json
    (config extends/overrides). Tolerant of a missing/malformed config entry."""
    g = dict(GOAT_GLOSSARY_DEFAULT)
    try:
        extra = load_config().get("goat_glossary")
        if isinstance(extra, dict):
            for k, v in extra.items():
                if k and v is not None:
                    g[str(k).strip()] = str(v).strip()
    except Exception:
        pass
    return g

def _glossary_text():
    return "\n".join(f"- {k} = {v}" for k, v in goat_glossary().items())


# ─── CREW FINDER · ASK THE GOAT (natural-language filter/sort) ─────────────────
# Translates a recruiter's plain-English request ("lots of RLA experience",
# "experience with ProStage", "sort by hours worked at Marvel") into a small JSON
# filter/sort spec that the frontend applies to the crew ALREADY on screen. The
# model only ever sees the request plus the VOCABULARY of skills/groups and
# induction venues present in the current results — never the full roster — so it
# maps fuzzy phrasing onto real terms while staying token-light. The client does
# the actual matching (against groups / inductions / notes) and sorting.

CREW_ASK_SYSTEM = """You convert a live-events recruiter's natural-language request into a JSON spec for filtering and sorting a crew shortlist. You are given VOCABULARY: the skills/groups and induction venues that actually appear in the current results. Map the request to the closest vocabulary entries.

Respond with ONLY a JSON object (no prose, no markdown fences) of this exact shape:
{
  "match": {"terms": ["<short token>", ...], "fields": ["groups","inductions","notes"], "mode": "any" or "all", "min_rating": <int, omit if none>},
  "sort": {"by": "rating" or "name" or "distance" or "match_count" or "availability", "dir": "asc" or "desc"},
  "explanation": "<one short plain sentence describing what you did>",
  "note": "<short caveat if part of the request can't be honored; omit otherwise>"
}
Omit "sort" if no ordering is implied. Omit "match" terms only if nothing maps.

Guidance:
- "X experience" / "experience with X" / "experienced in X": terms = the distinctive token(s) of X mapped to VOCABULARY; fields default ["groups","inductions","notes"]; mode "all" if several skills are all required, else "any".
- "lots of X experience" / "most experienced in X": match X and sort {"by":"match_count","dir":"desc"}.
- "sort by hours worked at <venue>": hours worked are NOT available in this view. Match the venue so the list shows people with that venue experience, sort {"by":"match_count","dir":"desc"}, and explain the limitation in "note".
- "highest rated" / "best": sort by rating desc. "rating over N" / "at least N stars": set min_rating.
- "closest" / "nearest": sort by distance asc.
- Always prefer terms that literally appear in VOCABULARY. If nothing matches, return empty terms and explain in "note".
- Keep each term to the distinctive token (e.g. "RLA","VX","ProStage","Marvel"), not a whole phrase.
- Expand glossary abbreviations to the role word before matching (e.g. "VX" -> "video"). If unsure which form the data stores, include BOTH the abbreviation and the expansion in terms with mode "any" (e.g. "VX experience" -> ["video","vx"]); prefer whichever appears in VOCABULARY."""

def crew_ask_system():
    return CREW_ASK_SYSTEM + "\n\nGLOSSARY (abbreviations common in call descriptions):\n" + _glossary_text()


@app.route("/api/crew-finder/ask", methods=["POST"])
@require_cohort("admin")
def api_crew_finder_ask():
    """NL request -> filter/sort spec for the on-screen crew list (Crew Finder)."""
    if not get_ss_session():
        return jsonify({"error": "Not logged in"}), 401
    body  = request.get_json(force=True) or {}
    query = (body.get("query") or "").strip()
    if not query:
        return jsonify({"error": "Empty request"}), 400
    vocab  = body.get("vocabulary") or {}
    groups = [str(g) for g in (vocab.get("groups") or [])][:250]
    venues = [str(v) for v in (vocab.get("venues") or [])][:250]

    import anthropic as _anthropic, json as _json
    _api_key = os.environ.get("ANTHROPIC_API_KEY") or load_config().get("anthropic_api_key", "").strip()
    if not _api_key:
        return jsonify({"error": "AI is not configured"}), 500

    user_msg = (
        "VOCABULARY\n"
        f"skills/groups: {', '.join(groups) if groups else '(none)'}\n"
        f"induction venues: {', '.join(venues) if venues else '(none)'}\n\n"
        f"REQUEST: {query}"
    )
    try:
        client = _anthropic.Anthropic(api_key=_api_key)
        resp = client.messages.create(
            model      = "claude-haiku-4-5-20251001",
            max_tokens = 400,
            system     = crew_ask_system(),
            messages   = [{"role": "user", "content": user_msg}],
        )
    except Exception as e:
        return jsonify({"error": f"AI error: {e}"}), 502

    text = "".join(b.text for b in resp.content if hasattr(b, "text")).strip()
    text = text.replace("```json", "").replace("```", "").strip()
    try:
        spec = _json.loads(text)
    except Exception:
        return jsonify({"error": "Couldn't interpret that — try rephrasing."}), 200
    return jsonify({"spec": spec})

@app.route("/api/call-status/<booking_id>/<call_id>")
@require_cohort(*READ_ALL_COHORTS)
def api_call_status(booking_id, call_id):
    """Fetch confirmed/waiting/declined counts from the callsheet page."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    resp = ss.get(f"{BASE_URL}/bookings/{booking_id}/callsheet/{call_id}")
    soup = BeautifulSoup(resp.text, "html.parser")

    # Blue banner contains: "THIS CALL REQUIRES X CREW / Y/Z CREW HAVE BEEN CONTACTED:
    #                         A CONFIRMED  B DECLINED / WAITING ON C CREW TO RESPOND"
    banner = None
    for div in soup.find_all("div"):
        txt = div.get_text(" ", strip=True)
        if "CONFIRMED" in txt.upper() and "WAITING" in txt.upper():
            banner = div
            break

    result = {"confirmed": 0, "declined": 0, "waiting": 0, "required": 0, "contacted": 0}

    if banner:
        txt = banner.get_text(" ", strip=True)
        m = re.search(r"REQUIRES\s+(\d+)\s+CREW", txt, re.I)
        if m: result["required"] = int(m.group(1))
        m = re.search(r"(\d+)\s*/\s*(\d+)\s+CREW\s+HAVE\s+BEEN\s+CONTACTED", txt, re.I)
        if m: result["contacted"] = int(m.group(1))
        m = re.search(r"(\d+)\s+CONFIRMED", txt, re.I)
        if m: result["confirmed"] = int(m.group(1))
        m = re.search(r"(\d+)\s+DECLINED", txt, re.I)
        if m: result["declined"] = int(m.group(1))
        m = re.search(r"WAITING\s+ON\s+(\d+)", txt, re.I)
        if m: result["waiting"] = int(m.group(1))

    return jsonify(result)


GOAT_TOOLS = [
    {
        "name": "get_calls",
        "description": "Fetch current unfilled calls from the SmartStaff dashboard. Returns bookings grouped with their calls, including call name, date/time, venue, booked vs required crew counts, and notes.",
        "input_schema": {
            "type": "object",
            "properties": {
                "force_refresh": {
                    "type": "boolean",
                    "description": "If true, bypass the 2-minute cache and fetch fresh data"
                }
            },
            "required": []
        }
    },
    {
        "name": "search_availability",
        "description": "Search crew availability for one or more specific calls. Returns available crew, conflicts, and skipped crew with their ratings, groups, and shift timeline.",
        "input_schema": {
            "type": "object",
            "properties": {
                "calls": {
                    "type": "array",
                    "description": "List of calls to check availability for",
                    "items": {
                        "type": "object",
                        "properties": {
                            "booking_id": {"type": "string"},
                            "call_id":    {"type": "string"},
                            "call_name":  {"type": "string"},
                            "start_dt":   {"type": "string", "description": "ISO format datetime"},
                            "end_dt":     {"type": "string", "description": "ISO format datetime"},
                            "venue":      {"type": "string"}
                        },
                        "required": ["booking_id", "call_id", "start_dt", "end_dt"]
                    }
                },
                "required_groups": {
                    "type": "array",
                    "items": {"type": "string"},
                    "description": "Filter crew by group membership"
                },
                "min_rating": {
                    "type": "integer",
                    "description": "Minimum star rating (1-10)",
                    "default": 1
                },
                "radius_km": {
                    "type": "integer",
                    "description": "Optional distance filter. If set, only crew living within this many km of the origin are returned as available; crew further away appear in skipped with their distance, and crew with no usable postcode go to location_unknown. Omit to disable distance filtering."
                },
                "near": {
                    "type": "string",
                    "description": "Origin for the radius filter: a 4-digit AU postcode (e.g. '3220') or a known venue name/code (e.g. 'Forum', 'GMHBA'). If omitted while radius_km is set, the origin defaults to the venue of the first call being searched."
                }
            },
            "required": ["calls"]
        }
    },
    {
        "name": "get_inductions",
        "description": "Get induction compliance status. Can filter by venue to see who is compliant, expiring, or non-compliant.",
        "input_schema": {
            "type": "object",
            "properties": {
                "venue_filter": {
                    "type": "string",
                    "description": "Venue code to filter by (e.g. RLA, Forum, MCG)"
                }
            },
            "required": []
        }
    },
    {
        "name": "check_assignment_inductions",
        "description": "Check whether crew CONFIRMED on upcoming calls are properly inducted for the venue they are booked to work. This is the ONLY tool that joins call rosters to induction status -- get_inductions by itself does NOT know who is assigned to which call. Use it for questions like 'do we have anyone with expired or missing inductions assigned to calls at RLA?'. Returns non-compliant assignments grouped by call, each crew member tagged Expired / Expiring Soon / No induction.",
        "input_schema": {
            "type": "object",
            "properties": {
                "venue": {
                    "type": "string",
                    "description": "Optional venue name or code to limit the check to (e.g. 'RLA', 'Rod Laver Arena', 'Marvel', 'MCG'). Omit to sweep every venue with upcoming calls."
                },
                "days": {
                    "type": "integer",
                    "description": "How many days ahead to check. Default 14, max 30.",
                    "default": 14
                }
            },
            "required": []
        }
    },
    {
        "name": "get_forecast",
        "description": "Get crew utilization forecast showing bookings over a date range.",
        "input_schema": {
            "type": "object",
            "properties": {
                "start_date": {
                    "type": "string",
                    "description": "Start date in YYYY-MM-DD format. Defaults to today."
                },
                "days": {
                    "type": "integer",
                    "description": "Number of days to forecast (1-28). Default 7.",
                    "default": 7
                }
            },
            "required": []
        }
    },
    {
        "name": "get_cache_status",
        "description": "Get the current crew cache status — how many profiles are cached and how fresh the data is.",
        "input_schema": {
            "type": "object",
            "properties": {},
            "required": []
        }
    },
    {
        "name": "get_import_log",
        "description": "Get the history of estimate imports — which quotes have been imported, when, and what SmartStaff booking they created.",
        "input_schema": {
            "type": "object",
            "properties": {},
            "required": []
        }
    },
    {
        "name": "lookup_crew_id",
        "description": "Look up a crew member's operational crew_id (userID, used for add_crew_to_call/send_sms) and human-facing EIN (the employee number operators recognise, e.g. 6070) from the cache by searching for a name. Use this before add_crew_to_call to verify you have the correct crew_id. When referring to a person by number to the operator, use their EIN, not crew_id. Always verify IDs this way rather than relying on IDs seen in previous tool results.",
        "input_schema": {
            "type": "object",
            "properties": {
                "name": {
                    "type": "string",
                    "description": "Full or partial name to search for"
                }
            },
            "required": ["name"]
        }
    },

    {
        "name": "add_crew_to_call",
        "description": "WRITE ACTION — Add one or more crew members to a call in SmartStaff. Requires confirmation before execution. Returns a confirmation request with details for the operator to approve.",
        "input_schema": {
            "type": "object",
            "properties": {
                "crew": {
                    "type": "array",
                    "description": "Crew members to add",
                    "items": {
                        "type": "object",
                        "properties": {
                            "name":    {"type": "string"},
                            "crew_id": {"type": "string"}
                        },
                        "required": ["name", "crew_id"]
                    }
                },
                "calls": {
                    "type": "array",
                    "description": "Calls to add them to",
                    "items": {
                        "type": "object",
                        "properties": {
                            "call_id":    {"type": "string"},
                            "booking_id": {"type": "string"},
                            "call_name":  {"type": "string"},
                            "date":       {"type": "string"}
                        },
                        "required": ["call_id", "booking_id"]
                    }
                },
                "confirm": {
                    "type": "boolean",
                    "description": "If true, also confirm the crew (not just add)"
                }
            },
            "required": ["crew", "calls"]
        }
    },
    {
        "name": "send_sms_to_crew",
        "description": "WRITE ACTION — Send an SMS availability request to crew members for a call. Requires confirmation before execution.",
        "input_schema": {
            "type": "object",
            "properties": {
                "crew": {
                    "type": "array",
                    "items": {
                        "type": "object",
                        "properties": {
                            "name":    {"type": "string"},
                            "crew_id": {"type": "string"}
                        },
                        "required": ["name", "crew_id"]
                    }
                },
                "calls": {
                    "type": "array",
                    "items": {
                        "type": "object",
                        "properties": {
                            "call_id":    {"type": "string"},
                            "booking_id": {"type": "string"},
                            "call_name":  {"type": "string"},
                            "date":       {"type": "string"}
                        },
                        "required": ["call_id", "booking_id"]
                    }
                }
            },
            "required": ["crew", "calls"]
        }
    },
    {
        "name": "get_unavailabilities",
        "description": "Read a crew member's current unavailability periods from SmartStaff, including each period's id and exact start/end times. Use this to show someone their unavailability, or to find the event_id needed before calling delete_unavailability. Always call lookup_crew_id first to get the crew_id.",
        "input_schema": {
            "type": "object",
            "properties": {
                "crew_id": {"type": "string", "description": "The crew member's operational userID"}
            },
            "required": ["crew_id"]
        }
    },
    {
        "name": "add_unavailability",
        "description": "WRITE ACTION — Mark a crew member as unavailable in SmartStaff for a date/time range (supports partial days, e.g. unavailable mornings only). Requires confirmation before execution. Always call lookup_crew_id first to get the correct crew_id. Dates must be YYYY-MM-DD; hours 0-23; minutes one of 0,15,30,45.",
        "input_schema": {
            "type": "object",
            "properties": {
                "crew_id":    {"type": "string", "description": "The crew member's operational userID (from lookup_crew_id)"},
                "name":       {"type": "string", "description": "The crew member's name, for the confirmation card"},
                "start_date": {"type": "string", "description": "Start date, YYYY-MM-DD"},
                "start_hour": {"type": "integer", "description": "Start hour, 0-23"},
                "start_min":  {"type": "integer", "description": "Start minute: 0, 15, 30 or 45"},
                "end_date":   {"type": "string", "description": "End date, YYYY-MM-DD"},
                "end_hour":   {"type": "integer", "description": "End hour, 0-23"},
                "end_min":    {"type": "integer", "description": "End minute: 0, 15, 30 or 45"},
                "reason":     {"type": "string", "description": "Reason for unavailability, e.g. 'Uni lectures', 'On leave'"}
            },
            "required": ["crew_id", "name", "start_date", "start_hour", "start_min",
                         "end_date", "end_hour", "end_min", "reason"]
        }
    },
    {
        "name": "delete_unavailability",
        "description": "WRITE ACTION — Remove an existing unavailability period for a crew member in SmartStaff. Requires confirmation before execution. You need the event_id of the period — get it by checking the crew member's current unavailabilities (these come with ids). Always include the crew_id.",
        "input_schema": {
            "type": "object",
            "properties": {
                "crew_id":  {"type": "string", "description": "The crew member's operational userID"},
                "name":     {"type": "string", "description": "The crew member's name, for the confirmation card"},
                "event_id": {"type": "string", "description": "The id of the unavailability period to delete"},
                "summary":  {"type": "string", "description": "Human-readable description of the period being deleted, e.g. '1 Jul 05:00–10:00 Lectures', for the confirmation card"}
            },
            "required": ["crew_id", "name", "event_id"]
        }
    }
]

GOAT_SYSTEM = """You are THE GOAT — the Gig Power Operations and Administration Terminal. An AI assistant embedded inside a live events crew management platform built for Gig Power Pty Ltd in Melbourne, Australia.

Your personality: confident, sharp, occasionally cocky (you are, after all, The GOAT). You have deep knowledge of the live events industry — bump ins, load outs, stagehands, crew bosses, venues, SmartStaff, rosters, inductions. You speak like a seasoned ops person who also happens to be an AI. You're direct, occasionally funny, never waste words. Australian English.

You have access to live SmartStaff data through your tools. Use them proactively — if someone asks about availability, actually check it. If they ask about inductions, pull the data. For "who is assigned to calls at <venue> without a valid induction" or "anyone with expired inductions on upcoming calls", use check_assignment_inductions -- get_inductions alone does NOT know call rosters. When someone asks for crew near a place — "riggers within 20km of the Forum", "who's available near Geelong" — call search_availability with radius_km and near (a 4-digit postcode or a venue name). Crew too far away come back in skipped with their distance, and anyone with no known postcode lands in location_unknown — mention them, don't pretend they're not there.

CRITICAL — WRITE ACTION RULES:
- ALWAYS call lookup_crew_id for each crew member before calling add_crew_to_call, send_sms_to_crew, add_unavailability, delete_unavailability, or get_unavailabilities. Never use an ID from a previous search result without verifying it first — name formats differ between SmartStaff and what the operator types.
- For delete_unavailability, first call get_unavailabilities to find the exact period and its event_id — never guess an id.
- When you call add_crew_to_call, send_sms_to_crew, add_unavailability or delete_unavailability, this generates a CONFIRMATION CARD in the UI for the operator to approve.
- You must NEVER claim the action was completed after calling the tool. The action has NOT happened yet.
- After calling the tool, tell the operator you have prepared the action and they need to confirm it using the card above.
- Only after the operator clicks Confirm will the action actually execute in SmartStaff.
- Example: after calling add_crew_to_call, say something like "I've set that up — confirm it using the card above and I'll get them added."
- Unavailability supports partial days. "Free in the afternoon" means unavailable in the morning — e.g. a uni student with morning lectures is unavailable 09:00–13:00, available after. Translate natural language to explicit start/end times.

Keep responses concise but warm. Occasional GOAT pun or dry humour is encouraged — never at the expense of being useful."""

def goat_system():
    return GOAT_SYSTEM + ("\n\nGLOSSARY — abbreviations common in call names/descriptions; "
        "treat the expansion as the same thing (e.g. a \"VX call\" is a video call, and "
        "\"video\" crew are the right fit):\n") + _glossary_text()


def execute_goat_tool(tool_name, tool_input, ss):
    """Execute a GOAT tool call and return the result as a string."""
    import json as _json

    if tool_name == "lookup_crew_id":
        try:
            query = tool_input.get("name", "").lower().strip()
            crew_data, _ = load_cache()
            # Also search the live crew list for phone numbers etc
            matches = []
            for cid, info in crew_data.items():
                name = info.get("name", "")
                # Match against both "Last, First" and "First Last" formats
                name_parts = [p.strip().lower() for p in name.replace(",", " ").split()]
                query_parts = query.replace(",", " ").split()
                if all(any(qp in np for np in name_parts) for qp in query_parts):
                    matches.append({
                        # crew_id is the operational userID (for add_crew_to_call / send_sms).
                        # The cache key (cid) is the manage_id — NOT valid for add-call.
                        "crew_id": info.get("user_id", cid),
                        "ein":     info.get("ein", info.get("user_id", cid)),  # human-facing EIN for display
                        "name":    name,
                        "rating":  info.get("rating", 0),
                        "groups":  info.get("groups", []),
                        "phone":   info.get("phone", ""),
                    })
            if not matches:
                return _json.dumps({"found": False, "message": f"No crew found matching '{query}'"})
            return _json.dumps({"found": True, "matches": matches[:5]})
        except Exception as e:
            return f"Error looking up crew: {e}"

    elif tool_name == "get_calls":
        force = tool_input.get("force_refresh", False)
        try:
            with app.test_request_context():
                calls = fetch_unfilled_calls(ss)
            return _json.dumps(calls[:20])  # cap at 20 for token budget
        except Exception as e:
            return f"Error fetching calls: {e}"

    elif tool_name == "search_availability":
        try:
            calls   = tool_input.get("calls", [])
            groups  = tool_input.get("required_groups", [])
            rating  = tool_input.get("min_rating", 1)
            today   = datetime.now()
            all_crew = _get_all_crew(ss)
            cache, _ = load_cache()
            results = {"available": [], "conflicts": [], "skipped": [], "location_unknown": []}
            targets = []
            for c in calls:
                try:
                    s = datetime.fromisoformat(c["start_dt"])
                    e = datetime.fromisoformat(c["end_dt"])
                    targets.append({**c, "start": s, "end": e})
                except Exception:
                    pass
            if not targets:
                return "No valid calls provided"
# ── Distance filter (optional) ─────────────────────────────────
            # radius_km absent/falsy => disabled (back-compat). Origin is an
            # explicit `near` (4-digit postcode or known venue), else the first
            # call's venue. Reuses the same helpers as /api/availability.
            radius_km     = tool_input.get("radius_km")
            near          = (tool_input.get("near") or "").strip()
            geo_active    = bool(radius_km)
            origin_coords = None
            if geo_active:
                if near:
                    if near.isdigit() and len(near) == 4:
                        pcc = postcode_to_coords(near)
                        if pcc:
                            origin_coords = (pcc["lat"], pcc["lon"], f"postcode {near}")
                    if not origin_coords:
                        origin_coords = venue_to_coords(near)
                else:
                    origin_coords = venue_to_coords(targets[0].get("venue", ""))
                if not origin_coords:
                    where = near or targets[0].get("venue", "") or "the call"
                    return _json.dumps({"error": f"Couldn't work out a location for '{where}' to measure distance from. Give a 4-digit AU postcode or a known venue name."})
            # Single bulk fetch over the target window (prefers bulk endpoint,
            # falls back to bookings scraper). Keyed by crew NAME. Replaces the
            # former per-crew get_crew_shifts call that under-reported bookings.
            # Filtered to confirmed-only (status==5) for conflict checks; the
            # GOAT search_availability tool has no timeline UI so we don't need
            # to surface non-confirmed entries here.
            win_start = min(t["start"] for t in targets) - timedelta(days=2)
            win_end   = max(t["end"]   for t in targets) + timedelta(days=2)
            shifts_by_name = _get_shifts_for_window(ss, win_start, win_end)

            count = 0
            for crew in all_crew[:50]:  # cap at 50 for speed
                cid = crew["id"]
                cached = cache.get(cid, {})
                crew_groups = cached.get("groups", [])
                crew_rating = cached.get("rating", 0)
                if groups and not any(g in crew_groups for g in groups):
                    results["skipped"].append(crew["name"])
                    continue
                if crew_rating < rating:
                    results["skipped"].append(crew["name"])
                    continue
                # Distance gate — only for crew past the group/rating filter.
                crew_distance = None
                if geo_active:
                    cpc    = crew.get("postcode") or cached.get("postcode", "")
                    ccoord = postcode_to_coords(cpc)
                    if not ccoord:
                        results["location_unknown"].append(crew["name"])
                        continue
                    d = haversine_km(origin_coords[0], origin_coords[1], ccoord["lat"], ccoord["lon"])
                    crew_distance = round(d, 1)
                    if d > float(radius_km):
                        results["skipped"].append(f"{crew['name']} ({round(d)} km away)")
                        continue
                shifts = [s for s in shifts_by_name.get(crew["name"], []) if s.get("status") == 5]
                conflict = False
                for t in targets:
                    # Exclude this target call's own shifts so a crew member already
                    # booked on this call doesn't trigger a self-conflict.
                    shifts_for_target = [s for s in shifts if str(s.get("call_id")) != str(t.get("call_id"))]
                    c_flag, reason = check_conflict(shifts_for_target, t["start"], t["end"], t.get("venue",""))
                    if c_flag:
                        results["conflicts"].append({"name": crew["name"], "reason": reason})
                        conflict = True
                        break
                if not conflict:
                    entry = {
                        "name": crew["name"],
                        "id": cid,
                        "rating": crew_rating,
                        "groups": crew_groups
                    }
                    if crew_distance is not None:
                        entry["distance_km"] = crew_distance
                    results["available"].append(entry)
                count += 1
            return _json.dumps({**results, "origin_label": origin_coords[2] if origin_coords else None})
        except Exception as e:
            return f"Error searching availability: {e}"

    elif tool_name == "get_inductions":
        try:
            cache, _ = load_cache()
            venue_filter = tool_input.get("venue_filter", "").lower()
            summary = {"compliant": [], "expiring": [], "expired": [], "incomplete": []}
            # Use the SAME expiry computation as the Induction Checker page
            # (/api/inductions -> _compute_induction_status), so the GOAT and the
            # page can never disagree. The raw cached status is only Complete/
            # Incomplete (SmartStaff doesn't compute expiry); Expired/Expiring
            # Soon are derived from the completion date here. No [:100] cap — the
            # page covers the whole roster, so this must too, or the totals won't
            # reconcile.
            for cid, info in cache.items():
                inductions   = info.get("inductions", {})
                venue_status = _compute_induction_status(inductions)
                for venue_name, vs in venue_status.items():
                    if venue_filter and venue_filter not in venue_name.lower():
                        continue
                    status = vs.get("status", "")
                    entry = {"name": info.get("name",""), "venue": venue_name}
                    if status == "Complete":
                        summary["compliant"].append(entry)
                    elif status == "Expiring Soon":
                        summary["expiring"].append(entry)
                    elif status == "Expired":
                        summary["expired"].append(entry)
                    else:
                        summary["incomplete"].append(entry)
            # Return counts + first few of each
            return _json.dumps({
                "compliant_count":  len(summary["compliant"]),
                "expiring_count":   len(summary["expiring"]),
                "expired_count":    len(summary["expired"]),
                "incomplete_count": len(summary["incomplete"]),
                "expiring_sample":  summary["expiring"][:5],
                "expired_sample":   summary["expired"][:5],
            })
        except Exception as e:
            return f"Error fetching inductions: {e}"

    elif tool_name == "check_assignment_inductions":
        try:
            venue_q = (tool_input.get("venue") or "").strip()
            days    = min(30, max(1, int(tool_input.get("days", 14) or 14)))

            filt_code, filt_kws = _resolve_induction_venue_query(venue_q)
            if venue_q and not filt_code:
                return _json.dumps({"error": "Don't recognise venue '" + venue_q
                    + "'. Known: " + ", ".join(INDUCTION_VENUE_MAP.keys())})

            with app.test_request_context():
                calls = fetch_unfilled_calls(ss, horizon_days=days)
            if filt_code:
                calls = [c for c in calls
                         if any(k in (c.get("venue") or "").lower() for k in filt_kws)]

            cache, _ = load_cache()
            ind_by_uid = {}
            for e in cache.values():
                uid = str(e.get("user_id", ""))
                if uid:
                    ind_by_uid[uid] = e.get("inductions", {}) or {}

            import time as _t
            noncompliant, unassessable, checked = [], [], 0
            for c in calls[:30]:
                venue = c.get("venue") or ""
                code  = filt_code or _induction_code_for_venue_name(venue)
                if not code:
                    unassessable.append({"call_id": c.get("call_id"),
                        "call_name": c.get("call_name"), "venue": venue})
                    continue
                ct, err = ss_get_call_times(ss, c.get("call_id"))
                if err or not ct:
                    continue
                checked += 1
                flagged = []
                for cm in ct.get("crew", []):
                    if int(cm.get("status", 0) or 0) != 5:   # confirmed only
                        continue
                    st, _m = induction_status_for_venue(ind_by_uid.get(str(cm.get("user_id","")), {}), code)
                    label = "No induction" if (st is None or st == "Incomplete") else st
                    if label in ("No induction", "Expired", "Expiring Soon"):
                        nm = (str(cm.get("firstname","")) + " " + str(cm.get("lastname",""))).strip()
                        flagged.append({"ein": cm.get("ein"), "name": nm, "induction": label})
                if flagged:
                    noncompliant.append({"call_id": c.get("call_id"),
                        "call_name": c.get("call_name"), "venue": venue,
                        "date": c.get("date", ""), "crew": flagged})
                _t.sleep(0.3)

            out = {"window_days": days, "venue_filter": filt_code or "ALL",
                   "calls_checked": checked, "noncompliant_calls": len(noncompliant),
                   "noncompliant": noncompliant,
                   "note": "Confirmed crew only (status 5). 'No induction' = never inducted at that venue."}
            if unassessable:
                out["unassessable_calls"] = unassessable[:10]
                out["note"] += " Calls at venues not in the induction map can't be assessed."
            return _json.dumps(out)
        except Exception as e:
            return f"Error checking assignment inductions: {e}"

    elif tool_name == "get_forecast":
        try:
            start_str = tool_input.get("start_date", datetime.now().strftime("%Y-%m-%d"))
            days      = min(28, max(1, tool_input.get("days", 7)))
            start_dt  = datetime.strptime(start_str, "%Y-%m-%d")
            end_dt    = start_dt + timedelta(days=days)
            crew_data, _ = load_cache()
            today = datetime.now().replace(hour=0, minute=0, second=0, microsecond=0)
            # Single bulk fetch over the forecast window (prefers bulk endpoint,
            # falls back to bookings scraper). Keyed by crew NAME. Replaces the
            # former per-crew get_crew_shifts call that under-reported bookings.
            # Filtered to confirmed-only (status==5) so total_hours reflects
            # committed work, not declined/pending entries.
            shifts_by_name = _get_shifts_for_window(ss, start_dt, end_dt)
            summary = []
            for cid, info in list(crew_data.items())[:30]:  # cap for speed
                shifts = [s for s in shifts_by_name.get(info.get("name", ""), []) if s.get("status") == 5]
                total = sum(
                    (min(datetime.fromisoformat(s["end"]), end_dt) -
                     max(datetime.fromisoformat(s["start"]), start_dt)).total_seconds() / 3600
                    for s in shifts
                    if datetime.fromisoformat(s["start"]) < end_dt and
                       datetime.fromisoformat(s["end"]) > start_dt
                )
                if total > 0:
                    summary.append({"name": info.get("name",""), "total_hours": round(total,1)})
            summary.sort(key=lambda x: x["total_hours"], reverse=True)
            return _json.dumps({"period": f"{start_str} to {end_dt.strftime('%Y-%m-%d')}", "busy_crew": summary[:15]})
        except Exception as e:
            return f"Error fetching forecast: {e}"

    elif tool_name == "get_cache_status":
        try:
            crew_data, fresh = load_cache()
            import os as _os
            age_hrs = 0
            if _os.path.exists(CACHE_FILE):
                age_hrs = round((datetime.now().timestamp() - _os.path.getmtime(CACHE_FILE)) / 3600, 1)
            return _json.dumps({"crew_count": len(crew_data), "age_hours": age_hrs, "is_fresh": fresh})
        except Exception as e:
            return f"Error: {e}"

    elif tool_name == "get_import_log":
        try:
            log = load_import_log()
            return _json.dumps({"count": len(log), "recent": log[-5:] if log else []})
        except Exception as e:
            return f"Error: {e}"

    elif tool_name in ("add_crew_to_call", "send_sms_to_crew"):
        # These return a confirmation request — actual execution happens client-side
        return _json.dumps({
            "requires_confirmation": True,
            "action":  tool_name,
            "crew":    tool_input.get("crew", []),
            "calls":   tool_input.get("calls", []),
            "confirm": tool_input.get("confirm", False),
        })

    elif tool_name == "get_unavailabilities":
        periods, err = fetch_unavailabilities(tool_input.get("crew_id"))
        if err:
            return _json.dumps({"error": err})
        return _json.dumps({"unavailabilities": periods})

    elif tool_name in ("add_unavailability", "delete_unavailability"):
        # Return a confirmation request — actual execution happens client-side
        payload = {
            "requires_confirmation": True,
            "action":  tool_name,
        }
        payload.update(tool_input)
        return _json.dumps(payload)

    return f"Unknown tool: {tool_name}"


_goat_histories = {}  # {sid: [{role, content}, ...]} — per-session conversation history
GOAT_MAX_HISTORY = 50  # max messages to retain per session


@app.route("/api/goat/history")
@require_cohort(*READ_ALL_COHORTS)
def api_goat_history():
    """Return stored conversation history for the current session."""
    if not session.get("sid") or not get_ss_session():
        return jsonify({"error": "Not logged in"}), 401
    sid = session["sid"]
    history = _goat_histories.get(sid, [])
    # Only return user/assistant text messages (skip tool internals)
    clean = [m for m in history if m.get("role") in ("user", "assistant")
             and isinstance(m.get("content"), str)]
    return jsonify({"history": clean})


@app.route("/api/goat/history/clear", methods=["POST"])
@require_cohort(*READ_ALL_COHORTS)
def api_goat_history_clear():
    """Clear conversation history for the current session."""
    if not session.get("sid"):
        return jsonify({"error": "Not logged in"}), 401
    _goat_histories.pop(session["sid"], None)
    return jsonify({"status": "cleared"})


@app.route("/api/goat", methods=["POST"])
@require_cohort(*READ_ALL_COHORTS)
def api_goat():
    """GOAT AI endpoint — runs Claude with tools, executes tool calls, streams final response."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    body     = request.get_json(force=True)
    messages = body.get("messages", [])
    if not messages:
        return jsonify({"error": "No messages"}), 400

    sid = session.get("sid", "anon")

    # Merge incoming messages into server-side history
    # The frontend sends the full history each time — use it as the source of truth
    # but cap at GOAT_MAX_HISTORY to keep token usage bounded
    _goat_histories[sid] = messages[-GOAT_MAX_HISTORY:]

    import anthropic as _anthropic
    _api_key = os.environ.get("ANTHROPIC_API_KEY") or load_config().get("anthropic_api_key","").strip()
    try:
        client = _anthropic.Anthropic(api_key=_api_key)
    except Exception as e:
        return jsonify({"error": f"Anthropic client error: {e}"}), 500

    def generate():
        import json as _json
        msgs = list(messages[-GOAT_MAX_HISTORY:])

        # Agentic loop — run until no more tool calls
        while True:
            try:
                response = client.messages.create(
                    model      = "claude-haiku-4-5-20251001",
                    max_tokens = 1024,
                    system     = goat_system(),
                    tools      = GOAT_TOOLS,
                    messages   = msgs,
                )
            except Exception as e:
                yield f"data: {_json.dumps({'type':'error','error':str(e)})}\n\n"
                return

            # Check for tool use
            tool_calls = [b for b in response.content if b.type == "tool_use"]

            if tool_calls:
                # Add assistant message with tool calls
                msgs.append({"role": "assistant", "content": response.content})

                # Execute each tool and collect results
                tool_results = []
                for tc in tool_calls:
                    yield f"data: {_json.dumps({'type':'tool_start','tool':tc.name})}\n\n"
                    result = execute_goat_tool(tc.name, tc.input, ss)

                    try:
                        parsed = _json.loads(result)
                        if parsed.get("requires_confirmation"):
                            yield f"data: {_json.dumps({'type':'confirm_request','payload':parsed})}\n\n"
                    except Exception:
                        pass

                    tool_results.append({
                        "type":        "tool_result",
                        "tool_use_id": tc.id,
                        "content":     result,
                    })

                msgs.append({"role": "user", "content": tool_results})
                continue

            # No tool calls — stream the final text response
            full_response = ""
            for block in response.content:
                if hasattr(block, "text"):
                    words = block.text.split(" ")
                    for i, word in enumerate(words):
                        chunk = word + (" " if i < len(words)-1 else "")
                        full_response += chunk
                        yield f"data: {_json.dumps({'type':'text','text':chunk})}\n\n"

            # Persist the final assistant response to server-side history
            if full_response:
                hist = _goat_histories.get(sid, list(msgs))
                hist.append({"role": "assistant", "content": full_response})
                _goat_histories[sid] = hist[-GOAT_MAX_HISTORY:]

            yield f"data: {_json.dumps({'type':'done'})}\n\n"
            return

    return app.response_class(generate(), mimetype="text/event-stream",
                              headers={"X-Accel-Buffering": "no",
                                       "Cache-Control": "no-cache"})



@app.route("/api/goat/add-crew", methods=["POST"])
@require_cohort("admin")
def api_goat_add_crew():
    """Add crew to calls server-side using the existing SmartStaff session."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    body    = request.get_json(force=True)
    crew    = body.get("crew", [])
    calls   = body.get("calls", [])
    calls   = expand_linked_calls(ss, calls)
    confirm = body.get("confirm", False)
    action  = "confcrew" if confirm else "addcrew"

    results = []
    for call in calls:
        for c in crew:
            url = f"{BASE_URL}/add-call.php?action={action}&id={call['call_id']}&userID={c['crew_id']}"
            try:
                resp = ss.get(url, allow_redirects=True)
                ok = resp.status_code == 200
                results.append({
                    "crew":    c["name"],
                    "call":    call.get("call_name", call["call_id"]),
                    "success": ok,
                })
                # Crew Hub push: an offer row was just written at status 0.
                # Only on a plain add — "Add & Confirm" (confcrew) is not an offer.
                if ok and action == "addcrew":
                    gp_notify_offer(c["crew_id"], call)
            except Exception as e:
                results.append({
                    "crew":    c["name"],
                    "call":    call.get("call_name", call["call_id"]),
                    "success": False,
                    "error":   str(e),
                })
            import time; time.sleep(0.8)

    return jsonify({"results": results})


@app.route("/api/goat/send-sms", methods=["POST"])
@require_cohort("admin")
def api_goat_send_sms():
    """Add crew to calls and send SMS server-side using the existing SmartStaff session."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    body  = request.get_json(force=True)
    crew  = body.get("crew", [])
    calls = body.get("calls", [])
    calls = expand_linked_calls(ss, calls)

    import time
    results = []

    # Step 1: add crew
    for call in calls:
        for c in crew:
            url = f"{BASE_URL}/add-call.php?action=addcrew&id={call['call_id']}&userID={c['crew_id']}"
            try:
                ss.get(url, allow_redirects=True)
            except Exception:
                pass
            time.sleep(0.8)

    # Step 2: send SMS per call
    # SmartStaff's add-call.php sendsms handler splices crewSelectList into a
    # SQL IN(...) clause, so it must be a single comma-separated value, not
    # repeated query params. With repeated params, PHP keeps only the last
    # one and only the last crew member gets the SMS.
    crew_params = "crewSelectList=" + ",".join(c["crew_id"] for c in crew)
    for call in calls:
        url = f"{BASE_URL}/add-call.php?action=sendsms&id={call['call_id']}&bookingID={call['booking_id']}&{crew_params}"
        try:
            resp = ss.get(url, allow_redirects=True)
            ok = resp.status_code == 200
            results.append({
                "call":    call.get("call_name", call["call_id"]),
                "success": ok,
            })
            # Crew Hub push: SMS offer just went out to these crew for this call.
            if ok:
                for c in crew:
                    gp_notify_offer(c["crew_id"], call)
        except Exception as e:
            results.append({
                "call":    call.get("call_name", call["call_id"]),
                "success": False,
                "error":   str(e),
            })
        time.sleep(0.8)

    return jsonify({"results": results})


# ── UNAVAILABILITY ENDPOINTS ─────────────────────────────────────────────────

@app.route("/api/unavailability/<crew_id>", methods=["GET"])
@require_cohort("admin")
def api_unavailability_list(crew_id):
    """List a crew member's unavailability periods (id + real times + reason)."""
    if not get_ss_session():
        return jsonify({"error": "Not logged in"}), 401
    periods, err = fetch_unavailabilities(crew_id)
    if err:
        return jsonify({"error": err}), 502
    return jsonify({"unavailabilities": periods})


@app.route("/api/unavailability/add", methods=["POST"])
@require_cohort("admin")
def api_unavailability_add():
    """Create an unavailability for a crew member in SmartStaff.
    Body: {crew_id, start_date 'YYYY-MM-DD', start_hour, start_min,
           end_date, end_hour, end_min, reason}."""
    if not get_ss_session():
        return jsonify({"error": "Not logged in"}), 401
    b = request.get_json(force=True)
    required = ["crew_id", "start_date", "start_hour", "start_min",
                "end_date", "end_hour", "end_min", "reason"]
    missing = [k for k in required if b.get(k) in (None, "")]
    if missing:
        return jsonify({"error": f"Missing fields: {', '.join(missing)}"}), 400
    if not str(b.get("reason")).strip():
        return jsonify({"error": "A reason is required"}), 400

    ok, err = add_unavailability(
        b["crew_id"], b["start_date"], b["start_hour"], b["start_min"],
        b["end_date"], b["end_hour"], b["end_min"], str(b["reason"]).strip())
    if not ok:
        return jsonify({"error": err or "Failed to add unavailability"}), 502

    # Reflect immediately in the cache (best-effort)
    refresh_crew_unavail_cache(b["crew_id"])
    return jsonify({"success": True})


@app.route("/api/unavailability/delete", methods=["POST"])
@require_cohort("admin")
def api_unavailability_delete():
    """Delete an unavailability by calendars.id.
    Body: {crew_id, event_id}."""
    if not get_ss_session():
        return jsonify({"error": "Not logged in"}), 401
    b = request.get_json(force=True)
    crew_id  = b.get("crew_id")
    event_id = b.get("event_id")
    if crew_id in (None, "") or event_id in (None, ""):
        return jsonify({"error": "crew_id and event_id are required"}), 400

    ok, err = delete_unavailability(crew_id, event_id)
    if not ok:
        return jsonify({"error": err or "Failed to delete unavailability"}), 502

    refresh_crew_unavail_cache(crew_id)
    return jsonify({"success": True})



@app.route("/api/whoami")
def api_whoami():
    """Identity + cohort for the current session — lets the frontend render the
    correct cohort-aware navigation. Safe fields only."""
    if not session.get("sid") or not get_ss_session():
        return jsonify({"error": "Not logged in"}), 401
    ident = current_identity() or {}
    return jsonify({
        "name":        ident.get("name", ""),
        "ein":         ident.get("ein", ""),
        "cohort":      ident.get("cohort", "crew"),
        "can_elevate": bool(ident.get("can_elevate", False)),
        "elevated":    session.get("sid") in _pre_elevation,
        "ss_base":     BASE_URL,
    })


# ─── SELF VIEWS (any logged-in cohort — own data only) ────────────────────────

@app.route("/api/me/inductions")
def api_me_inductions():
    """The logged-in user's OWN induction status, with the same expiry logic
    the admin Induction Checker uses."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    try:
        resp = ss.get(f"{BASE_URL}/ajax/crew/my-inductions.php",
                      allow_redirects=True, timeout=15)
        data = resp.json()
    except Exception as e:
        return jsonify({"error": f"Could not load inductions: {e}"}), 502
    venue_status = _compute_induction_status((data or {}).get("inductions", {}))
    ident = current_identity() or {}
    return jsonify({
        "name":         ident.get("name", ""),
        "ein":          ident.get("ein", ""),
        "venue_status": venue_status,
        "venues":       sorted(venue_status.keys()),
    })


@app.route("/api/me/shifts")
def api_me_shifts():
    """The logged-in user's OWN shifts + unavailabilities for a window. Powers
    both My Schedule (the list) and My Utilization (summed confirmed hours)."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    start_str = request.args.get("start_date", datetime.now().strftime("%Y-%m-%d"))
    try:    days = min(120, max(1, int(request.args.get("days", 28))))
    except: days = 28
    try:    start_dt = datetime.strptime(start_str, "%Y-%m-%d")
    except: return jsonify({"error": "Invalid start_date"}), 400
    end_str = (start_dt + timedelta(days=days)).strftime("%Y-%m-%d")
    try:
        resp = ss.get(f"{BASE_URL}/ajax/crew/my-shifts.php?start={start_str}&end={end_str}",
                      allow_redirects=True, timeout=15)
        data = resp.json()
    except Exception as e:
        return jsonify({"error": f"Could not load shifts: {e}"}), 502
    shifts   = (data or {}).get("shifts", [])
    unavails = (data or {}).get("unavails", [])
    total_hours = 0.0
    day_hours = {}
    for sft in shifts:
        try:
            cs = datetime.fromisoformat(sft["start"])
            ce = datetime.fromisoformat(sft["end"])
        except Exception:
            continue
        total_hours += (ce - cs).total_seconds() / 3600
        cur = cs.replace(hour=0, minute=0, second=0, microsecond=0)
        while cur < ce:
            dkey = cur.strftime("%Y-%m-%d")
            seg_start = max(cs, cur)
            seg_end   = min(ce, cur + timedelta(days=1))
            day_hours[dkey] = day_hours.get(dkey, 0) + (seg_end - seg_start).total_seconds() / 3600
            cur += timedelta(days=1)
    ident = current_identity() or {}
    return jsonify({
        "name":        ident.get("name", ""),
        "ein":         ident.get("ein", ""),
        "window":      {"start": start_str, "end": end_str, "days": days},
        "shifts":      shifts,
        "unavails":    unavails,
        "total_hours": round(total_hours, 1),
        "day_hours":   day_hours,
    })


@app.route("/api/me/unavailability", methods=["GET"])
def api_me_unavailability_list():
    """List the logged-in user's OWN unavailability periods."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    periods, err = fetch_own_unavailabilities(ss)
    if err:
        return jsonify({"error": err}), 502
    return jsonify({"unavailabilities": periods})


@app.route("/api/me/unavailability/add", methods=["POST"])
def api_me_unavailability_add():
    """Add an unavailability to the logged-in user's OWN calendar."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    b = request.get_json(force=True)
    required = ["start_date", "start_hour", "start_min",
                "end_date", "end_hour", "end_min", "reason"]
    missing = [k for k in required if b.get(k) in (None, "")]
    if missing:
        return jsonify({"error": f"Missing fields: {', '.join(missing)}"}), 400
    if not str(b.get("reason")).strip():
        return jsonify({"error": "A reason is required"}), 400
    ok, err = add_own_unavailability(
        ss, b["start_date"], b["start_hour"], b["start_min"],
        b["end_date"], b["end_hour"], b["end_min"], str(b["reason"]).strip())
    if not ok:
        return jsonify({"error": err or "Failed to add unavailability"}), 502
    return jsonify({"success": True})


@app.route("/api/me/unavailability/delete", methods=["POST"])
def api_me_unavailability_delete():
    """Delete one of the logged-in user's OWN unavailabilities by id."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    b = request.get_json(force=True)
    event_id = b.get("event_id")
    if event_id in (None, ""):
        return jsonify({"error": "event_id is required"}), 400
    ok, err = delete_own_unavailability(ss, event_id)
    if not ok:
        return jsonify({"error": err or "Failed to delete unavailability"}), 502
    return jsonify({"success": True})


# ── IMPORT ENDPOINTS ── DO NOT REMOVE DECORATOR BELOW ────────────────────────
@app.route("/api/import/validate", methods=["POST"])
@require_cohort("admin")
def api_import_validate():
    """Validate an import payload and return preview data + duplicate check."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    try:
        payload = request.get_json(force=True)
    except Exception:
        return jsonify({"errors": ["Invalid JSON"]}), 400

    errors = validate_payload(payload)
    if errors:
        return jsonify({"valid": False, "errors": errors})

    est     = payload["estimate"]
    event   = payload["event"]
    customer = payload["customer"]
    lines   = payload.get("labour_lines", [])
    non_labour = extract_non_labour(payload)

    # Duplicate check
    existing = find_import_log_entry(est["estimate_id"])
    if existing:
        return jsonify({
            "valid":     False,
            "duplicate": True,
            "errors":    [
                f"This estimate ({est['quote_number']}) was already imported on "
                f"{existing.get('imported_at', 'unknown date')}. "
                f"SmartStaff Booking ID: {existing.get('smartstaff_booking_id', 'unknown')}. "
                f"After initial import, updates must be made manually in SmartStaff."
            ]
        })

    # Lookup lists for matching — one bulk endpoint, falling back to the
    # per-list HTML scrapes if it isn't deployed (same graceful-degradation
    # pattern as the other bulk reads).
    customer_map = []
    if USE_BULK_ENDPOINTS and USE_BULK_IMPORT_LOOKUPS:
        lookups, err = fetch_import_lookups(ss)
        if err is None and lookups is not None:
            customers    = lookups["customers"]
            venues       = lookups["venues"]
            contacts     = lookups["contacts"]
            customer_map = lookups["customer_map"]
        else:
            app.logger.warning(f"[import-lookups] endpoint failed ({err}); falling back to scrape")
            customers = ss_get_customers(ss)
            venues    = ss_get_venues(ss)
            contacts  = ss_get_contacts(ss)
    else:
        customers = ss_get_customers(ss)
        venues    = ss_get_venues(ss)
        contacts  = ss_get_contacts(ss)

    customer_match = fuzzy_match(customer["company_name"], customers)
    venue_match    = fuzzy_match(event.get("venue_name", ""), venues)

    # Contact matching, scoped to the matched customer when the map is present:
    # prefer the customer's own contacts (and their default), falling back to
    # matching against every contact. With no map / no customer match this is
    # identical to the pre-bulk behaviour.
    scoped_contacts, default_contact = _customer_contacts(
        customer_match["id"] if customer_match else None, contacts, customer_map)

    contact_match = _scoped_contact_match(
        customer.get("contact_name", ""), contacts,
        scoped_contacts, default_contact, use_default=True)

    # Onsite contact: if the export didn't supply one, fall back to the booking
    # contact name so the onsite field matches/displays the same person. Scope
    # to the same customer but don't auto-default — onsite may be a different
    # person than the customer's default contact.
    onsite_contact_name   = (event.get("onsite_contact") or "").strip() \
        or (customer.get("contact_name") or "").strip()
    onsite_contact_match  = _scoped_contact_match(
        onsite_contact_name, contacts,
        scoped_contacts, default_contact, use_default=False)

    # Earliest date for booking date field
    dates = [ll["date"] for ll in lines if ll.get("date")]
    earliest_date = min(dates) if dates else ""

    # Build call preview rows
    call_previews = []
    for ll in lines:
        call_previews.append({
            "line_id":        ll["line_id"],
            "call_name":      ll.get("call_name") or "",
            "crew_type":      ll.get("crew_type", ""),
            "date":           ll["date"],
            "start_time":     ll["start_time"],
            "duration_hours": ll["duration_hours"],
            "quantity":       ll["quantity"],
            "shift_notes":    ll.get("shift_notes") or "",
        })

    # Build non-labour preview rows (schema 1.1). Each becomes an "Other" call.
    non_labour_previews = []
    for nl in non_labour:
        qty  = nl.get("quantity")
        cost = nl.get("unit_cost_ex_gst")
        line_total = (qty * cost) if isinstance(qty, (int, float)) and isinstance(cost, (int, float)) else None
        non_labour_previews.append({
            "line_id":           nl.get("line_id", ""),
            "item_name":         nl_item_name(nl),
            "title":             nl.get("title", ""),
            "description":       nl.get("description", ""),
            "quantity":          qty,
            "unit_cost_ex_gst":  cost,
            "line_total_ex_gst": line_total,
            "notes":             nl_compose_notes(nl),
            "call_name":         NON_LABOUR_CALL_NAME,
        })

    # Build combined booking notes
    notes_parts = [
        event.get("event_notes", ""),
        event.get("access_notes", ""),
        event.get("operational_notes", ""),
    ]
    booking_notes = "\n\n".join(p for p in notes_parts if p.strip())

    return jsonify({
        "valid":    True,
        "duplicate": False,
        "errors":   [],
        "preview":  {
            "booking_name":    event["event_name"],
            "booking_date":    format_booking_date(earliest_date),
            "invoice_ref":     est["quote_number"],
            "booking_notes":   booking_notes,
            "quote_number":    est["quote_number"],
            "version":         est.get("version", 1),
            "call_count":      len(lines),
            "calls":           call_previews,
            "non_labour_count": len(non_labour_previews),
            "non_labour":      non_labour_previews,
        },
        "matching": {
            "customer_name":          customer["company_name"],
            "customer_matched":       customer_match is not None,
            "customer_id":            customer_match["id"] if customer_match else None,
            "venue_name":             event.get("venue_name", ""),
            "venue_matched":          venue_match is not None,
            "venue_id":               venue_match["id"] if venue_match else None,
            "contact_name":           customer.get("contact_name", ""),
            "contact_matched":        contact_match is not None,
            "contact_id":             contact_match["id"] if contact_match else None,
            "onsite_contact_name":    onsite_contact_name,
            "onsite_contact_matched": onsite_contact_match is not None,
            "onsite_contact_id":      onsite_contact_match["id"] if onsite_contact_match else None,
            "customers":              customers,
            "venues":                 venues,
            "contacts":               contacts,
        },
    })


@app.route("/api/import/start", methods=["POST"])
@require_cohort("admin")
def api_import_start():
    """Begin the SmartStaff booking/call creation process in a background thread."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    if _import_progress.get("running"):
        return jsonify({"error": "An import is already in progress"}), 409

    body = request.get_json(force=True)
    payload           = body.get("payload")
    customer_id       = body.get("customer_id")
    contact_id        = body.get("contact_id", "")
    onsite_contact_id = body.get("onsite_contact_id", "")
    venue_id          = body.get("venue_id")
    call_names        = body.get("call_names", {})  # {line_id: call_name} — operator-selected (may be partial)

    if not payload or not customer_id or not venue_id:
        return jsonify({"error": "Missing payload, customer_id, or venue_id"}), 400

    # Resolve final call name per line: operator selection → JSON field → missing
    # JSON call_name values are always accepted even if not in VALID_CALL_NAMES
    resolved_call_names = {}
    missing_names = []
    invalid_names = []
    for ll in payload.get("labour_lines", []):
        lid     = ll.get("line_id", "?")
        json_cn = ll.get("call_name") or ""
        op_cn   = call_names.get(lid) or ""
        cn      = op_cn or json_cn
        resolved_call_names[lid] = cn
        if not cn:
            missing_names.append(lid)
        elif cn not in VALID_CALL_NAMES and cn != json_cn:
            # Only reject operator-entered names not in VALID_CALL_NAMES
            # JSON-sourced call names pass through regardless
            invalid_names.append(f"{lid}: '{cn}'")

    if missing_names:
        return jsonify({"error": f"Call name not selected for lines: {', '.join(missing_names)}"}), 400
    if invalid_names:
        return jsonify({"error": f"Invalid call names: {', '.join(invalid_names)}"}), 400

    errors = validate_payload(payload)
    if errors:
        return jsonify({"error": "Payload failed validation", "errors": errors}), 400

    est   = payload["estimate"]
    event = payload["event"]
    lines = payload.get("labour_lines", [])
    non_labour = extract_non_labour(payload)

    dates = [ll["date"] for ll in lines if ll.get("date")]
    earliest_date = min(dates) if dates else ""

    notes_parts = [
        event.get("event_notes", ""),
        event.get("access_notes", ""),
        event.get("operational_notes", ""),
    ]
    booking_notes = "\n\n".join(p for p in notes_parts if p.strip())

    def run_import():
        import time
        _import_progress.clear()
        _import_progress.update({
            "running":    True,
            "step":       "Creating booking...",
            "done":       0,
            "total":      len(lines) + len(non_labour),
            "errors":     [],
            "call_log":   [],
            "booking_id": None,
            "started":    time.time(),
        })

        try:
            # Build the booking record (shared by both creation paths)
            booking_data = {
                "booking_name":     event["event_name"],
                "booking_date":     format_booking_date(earliest_date),
                "invoice_ref":      est["quote_number"],
                "notes":            booking_notes,
                "customer_id":      customer_id,
                "contact_id":       contact_id,
                "onsite_contact_id":    onsite_contact_id,
                "venue_id":         venue_id,
            }

            if USE_CREATE_BOOKING_ENDPOINT:
                # One server-side call creates the booking and every call.
                booking_payload, calls_payload, call_meta = _build_import_payload(
                    booking_data, lines, non_labour, resolved_call_names, earliest_date
                )
                _import_progress["step"] = f"Creating booking and {len(calls_payload)} calls..."
                result, err = ss_create_booking_bulk(ss, booking_payload, calls_payload)
                if err:
                    _import_progress["errors"].append(f"Booking creation failed: {err}")
                    _import_progress["running"] = False
                    return

                booking_id = str(result.get("booking_id"))
                _import_progress["booking_id"] = booking_id
                _import_progress["step"] = f"Booking #{booking_id} created. Recording calls..."

                # Map the endpoint's call_ids / call_errors back to per-line log
                # entries. call_ids holds the successful inserts in order;
                # call_errors holds the indices that failed.
                err_idx = {e.get("index"): e.get("detail") for e in result.get("call_errors", [])}
                id_iter = iter(result.get("call_ids", []))
                for idx, meta in enumerate(call_meta):
                    failed = idx in err_idx
                    _import_progress["call_log"].append({
                        "line_id":   meta["line_id"],
                        "call_name": meta["call_name"],
                        "date":      meta["date"],
                        "success":   not failed,
                        "call_id":   None if failed else next(id_iter, None),
                        "error":     err_idx.get(idx),
                    })
                    if failed:
                        _import_progress["errors"].append(f"{meta['label']}: {err_idx[idx]}")
                    _import_progress["done"] += 1

            else:
                # ââ Legacy scrape-and-POST path (form GET + POST per record) ââ
                booking_id, err = ss_create_booking(ss, booking_data)
                if err:
                    _import_progress["errors"].append(f"Booking creation failed: {err}")
                    _import_progress["running"] = False
                    return

                _import_progress["booking_id"] = booking_id
                _import_progress["step"] = f"Booking #{booking_id} created. Adding calls..."

                # create each call sequentially
                for i, ll in enumerate(lines):
                    resolved_cn = resolved_call_names[ll["line_id"]]
                    _import_progress["step"] = f"Creating call {i+1}/{len(lines)}: {resolved_cn}..."
                    call_data = {
                        "call_name":               resolved_cn,
                        "start_date":              format_ss_date(ll["date"]),
                        "start_time":              ll["start_time"] + ":00",
                        "duration_hours":          ll["duration_hours"],
                        "crew_required":           ll["quantity"],
                        "notes":                   ll.get("shift_notes") or "",
                        "public_holiday_same_day": ll.get("public_holiday_same_day", False),
                        "public_holiday_next_day": ll.get("public_holiday_next_day", False),
                    }
                    call_id, call_err = ss_create_call(ss, booking_id, call_data)
                    log_entry = {
                        "line_id":   ll["line_id"],
                        "call_name": resolved_cn,
                        "date":      ll["date"],
                        "success":   call_err is None,
                        "call_id":   call_id,
                        "error":     call_err,
                    }
                    _import_progress["call_log"].append(log_entry)
                    if call_err:
                        _import_progress["errors"].append(f"Line {ll['line_id']} ({resolved_cn}): {call_err}")
                    _import_progress["done"] += 1
                    time.sleep(1.2)  # respectful pacing, matches existing add/confirm behaviour

                # non-labour items as "Other" calls
                nl_start_date = format_ss_date(earliest_date) if earliest_date else format_ss_date(
                    datetime.now().strftime("%Y-%m-%d")
                )
                for j, nl in enumerate(non_labour):
                    item_name = nl_item_name(nl)
                    lid = nl.get("line_id", "?")
                    _import_progress["step"] = (
                        f"Creating non-labour item {j+1}/{len(non_labour)}: {item_name}..."
                    )
                    call_data = {
                        "call_name":      item_name,
                        "call_name_free": item_name,  # → call_name_hidden in ss_create_call
                        "start_date":     nl_start_date,
                        "start_time":     "00:00:00",
                        "duration_hours": 0,
                        "crew_required":  0,
                        "notes":          nl_compose_notes(nl),
                    }
                    call_id, call_err = ss_create_call(ss, booking_id, call_data)
                    log_entry = {
                        "line_id":   lid,
                        "call_name": f"Other: {item_name}",
                        "date":      earliest_date or "",
                        "success":   call_err is None,
                        "call_id":   call_id,
                        "error":     call_err,
                    }
                    _import_progress["call_log"].append(log_entry)
                    if call_err:
                        _import_progress["errors"].append(f"Non-labour {lid} ({item_name}): {call_err}")
                    _import_progress["done"] += 1
                    time.sleep(1.2)

            # Step 3: record in import log
            import_log = load_import_log()
            import_log.append({
                "estimate_id":          est["estimate_id"],
                "quote_number":         est["quote_number"],
                "version":              est.get("version", 1),
                "imported_at":          datetime.now().isoformat(),
                "smartstaff_booking_id": booking_id,
                "calls_created":        _import_progress["done"],
                "errors":               len(_import_progress["errors"]),
            })
            save_import_log(import_log)

            _import_progress["step"] = "Complete"
            _import_progress["elapsed"] = round(time.time() - _import_progress["started"], 1)

        except Exception as e:
            _import_progress["errors"].append(f"Unexpected error: {str(e)}")
        finally:
            _import_progress["running"] = False

    threading.Thread(target=run_import, daemon=True).start()
    return jsonify({"status": "Import started"})


@app.route("/api/import/progress")
@require_cohort("admin")
def api_import_progress():
    """Poll current import progress."""
    return jsonify(_import_progress)


@app.route("/api/import/log")
@require_cohort("admin")
def api_import_log():
    """Return the full import history log."""
    return jsonify({"log": load_import_log()})


@app.route("/api/booking/lookups")
@require_cohort("admin")
def api_booking_lookups():
    """Customer/venue/contact lists for the manual Booking form's dropdowns.
    Same source as the Estimate Import matcher, just without needing a payload."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    if USE_BULK_ENDPOINTS and USE_BULK_IMPORT_LOOKUPS:
        lookups, err = fetch_import_lookups(ss)
        if err is None and lookups is not None:
            return jsonify({
                "customers":    lookups["customers"],
                "venues":       lookups["venues"],
                "contacts":     lookups["contacts"],
                "customer_map": lookups.get("customer_map", []),
            })
        app.logger.warning(f"[booking-lookups] endpoint failed ({err}); falling back to scrape")

    return jsonify({
        "customers":    ss_get_customers(ss),
        "venues":       ss_get_venues(ss),
        "contacts":     ss_get_contacts(ss),
        "customer_map": [],
    })


@app.route("/api/booking/create", methods=["POST"])
@require_cohort("admin")
def api_booking_create():
    """Create a booking + calls from the manual Booking form. Feeds the same
    create-booking endpoint the Estimate Import uses (ss_create_booking_bulk).
    Expects {booking: {...}, calls: [...]} already in endpoint shape."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    body    = request.get_json(force=True) or {}
    booking = body.get("booking") or {}
    calls   = body.get("calls") or []

    # Friendly pre-checks (the endpoint re-validates + checks FK existence).
    if not str(booking.get("name", "")).strip():
        return jsonify({"error": "Booking name is required"}), 400
    if not booking.get("customer_id"):
        return jsonify({"error": "Customer is required"}), 400
    if not booking.get("venue_id"):
        return jsonify({"error": "Venue is required"}), 400
    if not booking.get("contact_id"):
        return jsonify({"error": "Contact is required"}), 400
    for i, c in enumerate(calls):
        if not str(c.get("call_name", "")).strip():
            return jsonify({"error": f"Call {i+1}: name is required"}), 400
        if not str(c.get("start_date", "")).strip():
            return jsonify({"error": f"Call {i+1}: date is required"}), 400

    booking.setdefault("status", 0)  # 0 = Active/open

    result, err = ss_create_booking_bulk(ss, booking, calls)
    if err:
        return jsonify({"error": err}), 502
    return jsonify(result)


@app.route("/api/booking/venue", methods=["POST"])
@require_cohort("admin")
def api_booking_create_venue():
    """Quick-add a venue from the manual Booking form. Returns {id, name}."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    body = request.get_json(force=True) or {}
    name = str(body.get("venue", "")).strip()
    if not name:
        return jsonify({"error": "Venue name is required"}), 400

    vid, err = ss_create_venue(ss, body)
    if err:
        return jsonify({"error": err}), 502
    return jsonify({"id": vid, "name": name})


@app.route("/api/booking/customer", methods=["POST"])
@require_cohort("admin")
def api_booking_create_customer():
    """Quick-add a customer from the manual Booking form. Returns {id, name}."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    body = request.get_json(force=True) or {}
    name = str(body.get("customer_name", "")).strip()
    if not name:
        return jsonify({"error": "Customer name is required"}), 400

    cid, err = ss_create_customer(ss, body)
    if err:
        return jsonify({"error": err}), 502
    return jsonify({"id": cid, "name": name})


@app.route("/api/booking/contact", methods=["POST"])
@require_cohort("admin")
def api_booking_create_contact():
    """Quick-add a contact (linked to a customer) from the manual Booking form.
    Returns {id, name}."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    body = request.get_json(force=True) or {}
    customer_id = str(body.get("customer_id", "")).strip()
    username    = str(body.get("username", "")).strip()
    if not customer_id.isdigit():
        return jsonify({"error": "Select a customer first"}), 400
    if not username:
        return jsonify({"error": "Username is required"}), 400

    cid, err = ss_create_contact(ss, customer_id, body)
    if err:
        return jsonify({"error": err}), 502

    name = " ".join(p for p in [
        str(body.get("firstname", "")).strip(),
        str(body.get("lastname", "")).strip(),
    ] if p) or username
    return jsonify({"id": cid, "name": name})


@app.route("/api/admin/crew-lookups")
@require_cohort("admin")
def api_admin_crew_lookups():
    """Crew-group options + next EIN for the Administration > Add User form."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    data, err = ss_crew_lookups(ss)
    if err:
        return jsonify({"error": err}), 502
    if isinstance(data, dict):
        data["temp_password"] = NEW_CREW_TEMP_PASSWORD   # single source -> form note
    return jsonify(data)

@app.route("/api/admin/crew-list")
@require_cohort("admin")
def api_admin_crew_list():
    """Crew list for the Administration tab: {id, name, ein, phone, active}.

    ?active=0 returns inactive crew; default (or ?active=1) returns active.
    id is the internal SmartStaff userId — the same one /crew/manage and
    aquire-id use, so the list rows can drive both view/edit and login-as.
    """
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    want_active = request.args.get("active", "1") != "0"
    crew, err = fetch_crew_bulk(ss, include_inactive=(not want_active))
    if err:
        return jsonify({"error": err}), 502
    rows = [
        {
            "id":     c["id"],
            "name":   c.get("name", ""),
            "ein":    c.get("ein") or c["id"],
            "phone":  c.get("phone", "") or "",
            "active": int(c.get("active", 1)),
        }
        for c in (crew or [])
        if int(c.get("active", 1)) == (1 if want_active else 0)
    ]
    rows.sort(key=lambda r: (r["name"] or "").lower())
    return jsonify({"crew": rows})
@app.route("/api/admin/add-user", methods=["POST"])
@require_cohort("admin")
def api_admin_add_user():
    """Create a new crew member (usergroupID 3) from the Administration tab.
    Username is the auto-assigned EIN; a temp password is applied so the record is
    portal-ready (changed on first login). Returns {id, ein, username}."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    form  = request.form
    first = str(form.get("firstname", "")).strip()
    last  = str(form.get("lastname", "")).strip()
    if not first or not last:
        return jsonify({"error": "First and last name are required"}), 400

    # Optional profile picture (multipart). Validated here; the SmartStaff side
    # turns it into crewimg_<id>.jpg.
    photo = request.files.get("profilepic")
    if photo and photo.filename:
        if not (photo.mimetype or "").startswith("image/"):
            return jsonify({"error": "Profile picture must be an image file"}), 400
        photo.stream.seek(0, os.SEEK_END)
        size = photo.stream.tell()
        photo.stream.seek(0)
        if size > 10 * 1024 * 1024:
            return jsonify({"error": "Profile picture must be under 10 MB"}), 400
    else:
        photo = None

    data = {
        "firstname":         first,
        "lastname":          last,
        "mobile":            form.get("mobile", ""),
        "email":             form.get("email", ""),
        "dob":               form.get("dob", ""),
        "address":           form.get("address", ""),
        "suburb":            form.get("suburb", ""),
        "state":             form.get("state", ""),
        "postcode":          form.get("postcode", ""),
        "emergency_contact": form.get("emergency_contact", ""),
        "emergency_phone":   form.get("emergency_phone", ""),
        "notes":             form.get("notes", ""),
        "groups":            form.getlist("groups"),
    }

    # The EIN is fetched server-side at submit (not trusted from the client), so
    # two operators adding at once can't collide on a stale prefill value.
    look, err = ss_crew_lookups(ss)
    if err:
        return jsonify({"error": f"Couldn't assign an EIN: {err}"}), 502
    try:
        ein = int(look.get("next_ein") or 0)
    except (TypeError, ValueError):
        ein = 0
    if ein <= 0:
        return jsonify({"error": "Couldn't determine the next EIN"}), 502

    uid, err = ss_create_crew(ss, data, ein, NEW_CREW_TEMP_PASSWORD, photo=photo)
    if err:
        return jsonify({"error": err}), 502
    return jsonify({"id": uid, "ein": ein, "username": str(ein),
                    "temp_password": NEW_CREW_TEMP_PASSWORD})


@app.route("/api/booking/<booking_id>", methods=["POST"])
@require_cohort("admin")
def api_booking_update(booking_id):
    """Edit a booking's detail fields from the view/edit dialog. Proxies
    update-booking.php (admin-only); never closes the booking. Expects
    {booking: {...}} already in endpoint shape.

    Shares the path with api_booking_details (GET); Flask dispatches by method,
    and the static /api/booking/create rule still wins over this dynamic one."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    body    = request.get_json(force=True) or {}
    booking = body.get("booking") or {}

    # Friendly pre-checks (the endpoint re-validates + checks FK existence).
    if not str(booking.get("name", "")).strip():
        return jsonify({"error": "Booking name is required"}), 400
    if not booking.get("customer_id"):
        return jsonify({"error": "Customer is required"}), 400
    if not booking.get("venue_id"):
        return jsonify({"error": "Venue is required"}), 400
    if not booking.get("contact_id"):
        return jsonify({"error": "Contact is required"}), 400

    result, err = ss_update_booking_bulk(ss, booking_id, booking)
    if err:
        return jsonify({"error": err}), 502
    return jsonify(result)


@app.route("/api/call/<booking_id>/<call_id>", methods=["POST"])
@require_cohort("admin")
def api_call_update(booking_id, call_id):
    """Edit a call's detail fields from the call dialog. Proxies update-call.php
    (admin-only); re-syncs assigned-crew calendars, never locks the call.
    Expects {call: {...}} already in endpoint shape. booking_id is taken for URL
    symmetry with the GET route; the endpoint derives bookingID from the call."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    body = request.get_json(force=True) or {}
    call = body.get("call") or {}

    if not str(call.get("call_name", "")).strip():
        return jsonify({"error": "Call name is required"}), 400
    if not str(call.get("start_date", "")).strip():
        return jsonify({"error": "Call date is required"}), 400

    result, err = ss_update_call_bulk(ss, call_id, call)
    if err:
        return jsonify({"error": err}), 502

    # If the edit changed the TIMING, the endpoint flagged confirmed/backup crew
    # (call_change_ack) and returned per-status push lists. Fan out a change push
    # so contacted crew are nudged. Fire-and-forget: the edit already succeeded,
    # so nothing here may block or fail the response. The push carries the NEW
    # start/end; the card renders the full delta from my-shifts / my-backups, so
    # it no-ops safely until the portal's /api/push/change webhook is live.
    if isinstance(result, dict) and result.get("timing_changed"):
        change_call = {
            "call_id":   call_id,
            "call_name": call.get("call_name", ""),   # submitted name
            "start_dt":  result.get("new_start", ""),
            "end_dt":    result.get("new_end", ""),
        }
        for uid in result.get("reconfirm_users", []) or []:
            gp_notify_change(uid, change_call, "reconfirm")
        for uid in result.get("standby_users", []) or []:
            gp_notify_change(uid, change_call, "standby")
        for uid in result.get("info_users", []) or []:
            gp_notify_change(uid, change_call, "info")

    return jsonify(result)


@app.route("/api/booking/<booking_id>/call", methods=["POST"])
@require_cohort("admin")
def api_booking_add_call(booking_id):
    """Add a single call to an existing booking.

    Creates the call through SmartStaff's OWN callsheet form (ss_create_call),
    so the result is byte-identical to a call created in SmartStaff itself —
    no new PHP endpoint, and no crew-side rows (calendars / call_crew_map are
    only written when crew are actually assigned, a separate workflow).

    Body: {call: {call_name, call_name_free?, start_date (YYYY-MM-DD),
                  start_time (HH:MM:SS)?, length?, required?, notes?}}
    Mirrors the {call:{...}} body shape of the call-edit route.
    """
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    body = request.get_json(force=True) or {}
    call = body.get("call") or {}

    name = str(call.get("call_name", "")).strip()
    date = str(call.get("start_date", "")).strip()
    if not name:
        return jsonify({"error": "Call name is required"}), 400
    if not date:
        return jsonify({"error": "Call date is required"}), 400

    # A blank time means "start of day" — matches SmartStaff's own default.
    start_time = str(call.get("start_time", "")).strip() or "00:00:00"

    # ss_create_call reads duration_hours / crew_required with [] (not .get),
    # so both must always be present; default them to 0.
    call_data = {
        "call_name":      name,
        "start_date":     format_ss_date(date),   # YYYY-MM-DD -> 'Month D, YYYY'
        "start_time":     start_time,
        "duration_hours": call.get("length", 0) or 0,
        "crew_required":  call.get("required", 0) or 0,
        "notes":          call.get("notes", "") or "",
    }
    # "Other" calls carry their display name in call_name_free
    # (-> call_name_hidden inside ss_create_call).
    free = str(call.get("call_name_free", "")).strip()
    if free:
        call_data["call_name_free"] = free

    call_id, err = ss_create_call(ss, booking_id, call_data)
    if err:
        return jsonify({"error": err}), 502
    return jsonify({"ok": True, "call_id": call_id})


@app.route("/api/calls/link", methods=["POST"])
@require_cohort("admin")
def api_calls_link():
    """Link a set of calls (Phase 2). Proxies link-calls.php (admin-only). Body:
    {call_ids:[>=2]} — all must be in the same booking and currently unlinked.
    Grouping only; never touches call_crew_map / calendars. The response cascade
    that makes linked calls answer as a unit lives in respond-to-call.php."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    body     = request.get_json(force=True) or {}
    call_ids = [c for c in (body.get("call_ids") or []) if str(c).strip()]
    if len(call_ids) < 2:
        return jsonify({"error": "Select at least two calls to link"}), 400
    result, err = ss_link_calls_bulk(ss, "link", call_ids)
    if err:
        return jsonify({"error": err}), 502
    return jsonify(result)


@app.route("/api/calls/unlink", methods=["POST"])
@require_cohort("admin")
def api_calls_unlink():
    """Unlink a set of calls (Phase 2). Proxies link-calls.php (admin-only). Body:
    {call_ids:[>=1]} — clears link_group; any group left with a single call is
    dissolved by the endpoint."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    body     = request.get_json(force=True) or {}
    call_ids = [c for c in (body.get("call_ids") or []) if str(c).strip()]
    if not call_ids:
        return jsonify({"error": "No calls to unlink"}), 400
    result, err = ss_link_calls_bulk(ss, "unlink", call_ids)
    if err:
        return jsonify({"error": err}), 502
    return jsonify(result)


@app.route("/api/call/<booking_id>/<call_id>/crew/<user_id>/status", methods=["POST"])
@require_cohort("admin")
def api_call_crew_status(booking_id, call_id, user_id):
    """Set one crew member's status on a call from the call dialog. Proxies
    update-crew-status.php (admin-only): writes call_crew_map.status and, on
    status 5 (confirmed) only, re-syncs that crew member's calendar via
    SmartStaff's own addToCalendar — byte-identical to a native confirm.
    Decline/no-show/pending/unconfirmed leave the calendar untouched, so a
    declined entry stays visible. booking_id is taken for URL symmetry with the
    other call routes; the endpoint derives bookingID from the call."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    body   = request.get_json(force=True) or {}
    status = body.get("status")

    if status is None or str(status).strip() == "":
        return jsonify({"error": "status is required"}), 400

    result, err = ss_update_crew_status(ss, call_id, user_id, status)
    if err:
        return jsonify({"error": err}), 502

    # A backup (status 7) just promoted to confirmed (5): push "you're booked".
    # Fire-and-forget; the promotion already succeeded above, so this must never
    # block or fail the response. Fetch the call's name/venue for the message.
    if isinstance(result, dict) and result.get("promoted"):
        call = {"call_id": call_id}
        try:
            bdata, berr = fetch_booking_bulk(ss, booking_id)
            if berr is None and isinstance(bdata, dict):
                call["booking_name"] = bdata.get("name", "")
                venue = bdata.get("venue") or {}
                call["venue"] = venue.get("name", "")
                for c in (bdata.get("calls") or []):
                    if str(c.get("call_id")) == str(call_id):
                        call["call_name"] = c.get("call_name", "")
                        break
        except Exception:
            pass
        gp_notify_promotion(user_id, call)

    return jsonify(result)

def ss_remove_crew_from_call(ss, call_id, user_id):
    """Remove a crew member from a call ENTIRELY (not a status change) via
    SmartStaff's native add-call.php?action=remove -- the same request the admin
    UI fires. Deletes the call_crew_map row and clears the crew member's calendar
    entry for the call. crewSelectList takes the crew member's userID (single
    value here; SmartStaff accepts a comma-separated list for multiples). Returns
    ({"ok": True}, None) or (None, error)."""
    url = f"{BASE_URL}/add-call.php"
    try:
        resp = ss.get(url, params={"action": "remove", "id": int(call_id),
                                   "crewSelectList": str(user_id)},
                      allow_redirects=True, timeout=60)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        return None, f"HTTP {resp.status_code}"
    return {"ok": True}, None

@app.route("/api/call/<booking_id>/<call_id>/crew/<user_id>", methods=["DELETE"])
@require_cohort("admin")
def api_call_crew_remove(booking_id, call_id, user_id):
    """Remove a crew member from a call entirely (NOT a decline). For fixing
    wrong-person assignments. Proxies SmartStaff's native remove, which also
    clears the calendar entry. booking_id is taken for URL symmetry with the
    other call routes."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    result, err = ss_remove_crew_from_call(ss, call_id, user_id)
    if err:
        return jsonify({"error": err}), 502
    return jsonify(result)

def ss_set_call_boss(ss, call_id, user_id):
    """Set one crew member as the call boss via SmartStaff's native
    add-call.php?action=makeboss -- the same request the Set Boss button on the
    callsheet fires. The native handler clears is_call_boss on EVERY row for the
    call and then sets it on this one, so single-boss-per-call is structurally
    guaranteed and we never have to break a tie. It touches nothing else: not
    status, not paygrade, not the calendar. There is no native UNSET path.
    Returns ({"ok": True}, None) or (None, error)."""
    url = f"{BASE_URL}/add-call.php"
    try:
        resp = ss.get(url, params={"action": "makeboss", "id": int(call_id),
                                   "crewID": str(user_id)},
                      allow_redirects=True, timeout=60)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        return None, f"HTTP {resp.status_code}"
    return {"ok": True}, None

@app.route("/api/call/<booking_id>/<call_id>/boss/<user_id>", methods=["POST"])
@require_cohort("admin")
def api_call_set_boss(booking_id, call_id, user_id):
    """Designate one crew member as the call boss. Proxies SmartStaff's native
    makeboss, which clears the flag on all other rows first. booking_id is taken
    for URL symmetry with the other call routes. This is what rung 1 of the crew
    contact hierarchy reads, so it changes what crew see in Crew Hub."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    result, err = ss_set_call_boss(ss, call_id, user_id)
    if err:
        return jsonify({"error": err}), 502
    return jsonify(result)

def ss_get_call_times(ss, call_id):
    """Booked crew with their CURRENT actual times / paygrade / late / note, plus
    the paygrade option list, via get-call-times.php (admin-only). Used to prefill
    the call-dialog times grid. Returns (data, error)."""
    url = f"{BASE_URL}/ajax/crew/get-call-times.php"
    try:
        resp = ss.get(url, params={"id": int(call_id)}, timeout=60)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        detail = ""
        try:
            detail = resp.json().get("error", "")
        except Exception:
            detail = (resp.text or "")[:200]
        return None, f"HTTP {resp.status_code}: {detail}"
    try:
        data = resp.json()
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return None, data["error"]
    return data, None


def ss_update_call_times(ss, call_id, rows):
    """Write per-crew ACTUAL TIMES for a call via update-call-times.php (admin-only).
    Each row writes only the keys it carries: on/break/break_night/off, the paygrade
    + its four derived rates, late and goat_note. The endpoint never touches status,
    user_entered_times, times_filled or call_locked. Returns (data, error).

    rows : list of {user_id, on, off, break, break_night, callpaygradeID, late, note}
    """
    url = f"{BASE_URL}/ajax/crew/update-call-times.php"
    try:
        resp = ss.post(url, params={"id": int(call_id)}, json={"rows": rows}, timeout=60)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        detail = ""
        try:
            detail = resp.json().get("error", "")
        except Exception:
            detail = (resp.text or "")[:200]
        return None, f"HTTP {resp.status_code}: {detail}"
    try:
        data = resp.json()
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return None, data["error"]
    return data, None


@app.route("/api/call/<booking_id>/<call_id>/times")
@require_cohort("admin")
def api_call_times(booking_id, call_id):
    """Booked crew with current actual-times/paygrade/late/note + paygrade options,
    to prefill the call-dialog times grid. Proxies get-call-times.php (admin-only).
    booking_id is taken for URL symmetry with the other call routes; the endpoint
    derives bookingID from the call. Distinct path from /api/call/<b>/<c>, so no
    collision with api_call_details (GET) or api_call_update (POST)."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    data, err = ss_get_call_times(ss, call_id)
    if err:
        return jsonify({"error": err}), 502
    return jsonify(data)


@app.route("/api/call/<booking_id>/<call_id>/times", methods=["POST"])
@require_cohort("admin")
def api_call_times_save(booking_id, call_id):
    """Write per-crew actual times from the call-dialog times grid. Proxies
    update-call-times.php (admin-only). Body {rows:[...]}. booking_id is for URL
    symmetry; the endpoint derives bookingID from the call."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    body = request.get_json(force=True) or {}
    rows = body.get("rows")
    if not isinstance(rows, list):
        return jsonify({"error": "Body must be {rows:[...]}"}), 400
    result, err = ss_update_call_times(ss, call_id, rows)
    if err:
        return jsonify({"error": err}), 502
    return jsonify(result)


def ss_get_bookings_bulk(ss, limit=50, offset=0, q=""):
    """Chronological list of all bookings (most recent first) via
    get-bookings-bulk.php (admin-only), for the All Bookings tab. Booking-level
    rows; the front-end fetches a booking's calls on expand via get-booking.php.
    Returns (data, error)."""
    url = f"{BASE_URL}/ajax/crew/get-bookings-bulk.php"
    params = {"limit": int(limit), "offset": int(offset)}
    if q:
        params["q"] = q
    try:
        resp = ss.get(url, params=params, allow_redirects=True, timeout=30)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        detail = ""
        try:
            detail = resp.json().get("error", "")
        except Exception:
            detail = (resp.text or "")[:200]
        return None, f"HTTP {resp.status_code}: {detail}"
    try:
        data = resp.json()
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return None, data["error"]
    return data, None


@app.route("/api/bookings/all")
@require_cohort("admin")
def api_bookings_all():
    """Chronological list of all bookings (past + future), most recent first, for
    the All Bookings tab. Proxies get-bookings-bulk.php (admin-only). Query params:
    limit (default 50, max 100), offset (default 0), q (optional booking-name
    search). The front-end fetches a booking's calls on expand via
    /api/booking/<id>."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    try:
        limit = int(request.args.get("limit", 50))
    except (TypeError, ValueError):
        limit = 50
    try:
        offset = int(request.args.get("offset", 0))
    except (TypeError, ValueError):
        offset = 0
    q = (request.args.get("q") or "").strip()
    data, err = ss_get_bookings_bulk(ss, limit=limit, offset=offset, q=q)
    if err:
        return jsonify({"error": err}), 502
    return jsonify(data)

# ── Timesheet sheet links (booking -> the Google Sheet THE GOAT generated) ──────
# Machine-local, gitignored — same idea as config.json / google_token.json. Lets the
# live importer find the sheet later. Name-search + paste-URL are the fallbacks when
# this file doesn't have the booking (different machine / pre-3.14 sheet).
TIMESHEET_LINKS_FILE = os.path.join(BASE_DIR, "timesheet_links.json")


def _load_timesheet_links():
    try:
        with open(TIMESHEET_LINKS_FILE) as f:
            return json.load(f)
    except (IOError, ValueError):
        return {}


def _save_timesheet_link(booking_id, spreadsheet_id, url):
    links = _load_timesheet_links()
    links[str(booking_id)] = {"spreadsheet_id": spreadsheet_id, "url": url,
                              "created_at": int(time.time())}
    try:
        with open(TIMESHEET_LINKS_FILE, "w") as f:
            json.dump(links, f, indent=2)
    except IOError:
        pass  # a convenience cache; never fatal


def _lookup_timesheet_link(booking_id):
    return _load_timesheet_links().get(str(booking_id))


def _gsheet_token_path():
    cfg = load_config()
    tf = (cfg.get("google_oauth_token_file") or "google_token.json").strip()
    return tf if os.path.isabs(tf) else os.path.join(BASE_DIR, tf)


def _find_sheet_by_name(booking_id):
    """Recover the spreadsheet id from Drive by the '#<booking_id>' tag generation
    embeds in the sheet name, when no local link exists. Returns id or None."""
    try:
        from googleapiclient.discovery import build
        from timesheet_gsheet import _user_creds
        drive = build("drive", "v3", credentials=_user_creds(_gsheet_token_path()),
                      cache_discovery=False)
        q = ("name contains '#%d' and trashed = false and "
             "mimeType = 'application/vnd.google-apps.spreadsheet'") % int(booking_id)
        files = drive.files().list(
            q=q, fields="files(id,name,modifiedTime)",
            orderBy="modifiedTime desc", pageSize=5,
            supportsAllDrives=True, includeItemsFromAllDrives=True,
        ).execute().get("files", [])
        return files[0]["id"] if files else None
    except Exception:
        return None


# ── Shared import-preview builder (both .xlsx upload and live Google Sheet) ──────
def _call_sched_dt(c):
    """A call's scheduled start as a datetime (start_date unix + start_time HH:MM)."""
    sd = c.get("start_date")
    st = c.get("start_time") or "00:00:00"
    if not sd:
        return None
    try:
        d = datetime.fromtimestamp(int(sd))
    except Exception:
        return None
    mt = re.match(r"(\d{1,2}):(\d{2})", str(st))
    hh = int(mt.group(1)) if mt else 0
    mm = int(mt.group(2)) if mt else 0
    return datetime(d.year, d.month, d.day, hh, mm)


def _build_import_preview(ss, booking_id, parsed):
    """Shared matcher / preview-shaper for BOTH import sources. `parsed` is the
    {tabs, skipped_tabs} structure produced by timesheet_import.parse_timesheet_workbook
    (file upload) OR timesheet_gsheet_read.read_timesheet (live sheet). Each tab may
    carry an explicit `call_id` (generated sheets) -> maps EXACTLY; otherwise falls
    back to EIN-overlap + nearest-time (hand-made sheets). Read-only.

    Returns (preview_dict, None) or (None, error_str). Output keys are unchanged from
    the original inline route, plus per-call `map_by` and a top-level `foreign_tabs`
    (the round-trip guard: a stamped Call ID that isn't a call on this booking)."""
    booking, err = fetch_booking_bulk(ss, booking_id)
    if err:
        return None, "Couldn't load booking: %s" % err
    calls = booking.get("calls", []) if isinstance(booking, dict) else []

    # Pre-fetch every call's roster once (get-call-times.php): EIN -> crew + paygrade.
    rosters, call_index = {}, []
    for c in calls:
        cid = c.get("call_id")
        cd = _call_sched_dt(c)
        ein_map = {}
        ct, cterr = ss_get_call_times(ss, cid)
        if cterr is None and isinstance(ct, dict):
            for cm in ct.get("crew", []):
                try:
                    e = int(cm.get("ein"))
                except (TypeError, ValueError):
                    e = None
                if e is not None:
                    nm = ((cm.get("lastname") or "") + ", " + (cm.get("firstname") or "")).strip(", ")
                    cpg = cm.get("callpaygradeID") or 0
                    upg = cm.get("user_paygradeID") or 0
                    pg = cpg if (cpg and cpg > 0) else upg
                    ein_map[e] = {"user_id": cm.get("user_id"), "name": nm, "pg": pg}
        rosters[cid] = ein_map
        call_index.append({
            "call_id":   cid,
            "call_name": c.get("call_name"),
            "dt":        cd,
            "iso":       cd.isoformat() if cd else None,
            "eins":      set(ein_map.keys()),
        })
    by_id = {ci["call_id"]: ci for ci in call_index}

    def _norm_id(v):
        try:
            return int(v)
        except (TypeError, ValueError):
            return v

    out_calls, unmapped, foreign = [], [], []

    for tab in parsed.get("tabs", []):
        if not tab.get("rows"):
            continue  # empty / master template tab

        tab_eins = set(r["ein"] for r in tab["rows"] if r.get("ein") is not None)

        explicit = tab.get("call_id")
        best, map_by, best_overlap, best_delta = None, None, -1, None

        if explicit is not None:
            # Exact map by the stamped Call ID (B1). int/str tolerant.
            ci = by_id.get(explicit)
            if ci is None:
                ci = by_id.get(_norm_id(explicit))
            if ci is None:
                # round-trip guard: this tab belongs to a different booking
                foreign.append({"tab_name": tab["tab_name"], "call_id": explicit,
                                "crew_count": len(tab["rows"])})
                continue
            best, map_by = ci, "call_id"
            best_overlap = len(tab_eins & ci["eins"])   # informational only
            best_delta = None
        else:
            # Legacy: most shared EINs, tie-break / fallback on closest start time.
            map_by = "ein_overlap"
            tdt = None
            if tab.get("call_time"):
                try:
                    tdt = datetime.fromisoformat(tab["call_time"])
                except Exception:
                    tdt = None

            def _delta(ci):
                if tdt is None or ci["dt"] is None:
                    return None
                return abs(int((ci["dt"] - tdt).total_seconds() // 60))

            for ci in call_index:
                overlap = len(tab_eins & ci["eins"])
                delta = _delta(ci)
                take = False
                if overlap > best_overlap:
                    take = True
                elif overlap == best_overlap and overlap > 0:
                    if delta is not None and (best_delta is None or delta < best_delta):
                        take = True
                if take:
                    best, best_overlap, best_delta = ci, overlap, delta

            if best is None or best_overlap <= 0:
                nearest, nd = None, None
                for ci in call_index:
                    d = _delta(ci)
                    if d is None:
                        continue
                    if nearest is None or d < nd:
                        nearest, nd = ci, d
                if nearest is None:
                    unmapped.append({"tab_name": tab["tab_name"],
                                     "call_time": tab.get("call_time"),
                                     "crew_count": len(tab["rows"])})
                    continue
                best, best_overlap, best_delta = nearest, 0, nd

        roster_ein = rosters.get(best["call_id"], {})
        matched_eins = set()
        rows_matched, rows_skipped, rows_unmatched = [], [], []

        for r in tab["rows"]:
            ein = r.get("ein")
            nm = ((r.get("lastname") or "") + ", " + (r.get("firstname") or "")).strip(", ")
            if r.get("no_show") or not r.get("on"):
                rows_skipped.append({"ein": ein, "name": nm,
                                     "reason": ("No Show" if r.get("no_show") else "no times in sheet")})
                continue
            if ein is not None and ein in roster_ein:
                matched_eins.add(ein)
                m = roster_ein[ein]
                mrow = {
                    "user_id":     m["user_id"],
                    "ein":         ein,
                    "name":        m["name"],
                    "on":          r.get("on"),
                    "off":         r.get("off"),
                    "break":       r.get("break"),
                    "break_night": r.get("break_night"),
                    "late":        r.get("late"),
                    "note":        r.get("note"),
                }
                if m.get("pg"):
                    mrow["callpaygradeID"] = m["pg"]
                rows_matched.append(mrow)
            else:
                rows_unmatched.append({"ein": ein, "name": nm})

        roster_only = [{"user_id": v["user_id"], "ein": k, "name": v["name"]}
                       for k, v in roster_ein.items() if k not in matched_eins]

        out_calls.append({
            "tab_name":      tab["tab_name"],
            "tab_call_time": tab.get("call_time"),
            "call_id":       best["call_id"],
            "call_name":     best["call_name"],
            "call_time":     best["iso"],
            "delta_min":     best_delta,
            "overlap":       best_overlap,
            "tab_ein_count": len(tab_eins),
            "map_by":        map_by,
            "matched":       rows_matched,
            "skipped":       rows_skipped,
            "unmatched":     rows_unmatched,
            "roster_only":   roster_only,
        })

    return ({
        "booking_id":      booking_id,
        "booking_name":    booking.get("name") if isinstance(booking, dict) else None,
        "calls":           out_calls,
        "unmapped_tabs":   unmapped,
        "foreign_tabs":    foreign,
        "skipped_tabs":    parsed.get("skipped_tabs", []),
        "available_calls": [{"call_id": ci["call_id"], "call_name": ci["call_name"],
                             "call_time": ci["iso"]} for ci in call_index],
    }, None)

@app.route("/api/booking/<booking_id>/import-times/preview", methods=["POST"])
@require_cohort("admin")
def api_import_times_preview(booking_id):
    """Parse an uploaded timesheet workbook (.xlsx) and build a per-call preview
    against this booking. Read-only. Generated sheets exported to .xlsx still carry
    the A1/B1 Call ID, so they map exactly; hand-made sheets fall back to EIN-overlap."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    up = request.files.get("file")
    if up is None:
        return jsonify({"error": "No file uploaded (expected a multipart 'file' part)"}), 400

    try:
        from timesheet_import import parse_timesheet_workbook
        parsed = parse_timesheet_workbook(up.read())
    except Exception as e:
        return jsonify({"error": "Couldn't read workbook: %s" % e}), 400

    preview, err = _build_import_preview(ss, booking_id, parsed)
    if err:
        return jsonify({"error": err}), 502
    return jsonify(preview)


@app.route("/api/booking/<booking_id>/import-times/preview-live", methods=["GET"])
@require_cohort("admin")
def api_import_times_preview_live(booking_id):
    """Read the LIVE Google Sheet THE GOAT generated for this booking and build the
    same per-call preview (read-only). No file upload — tabs map to calls by the Call
    ID stamped in B1. Finds the sheet via the saved link, else by Drive name-search.
    Trigger is an Operations member clicking Import; the click is the 'finished'
    signal, so there's no submitted-flag to check."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    link = _lookup_timesheet_link(booking_id)
    ssid = (link or {}).get("spreadsheet_id") or _find_sheet_by_name(booking_id)
    if not ssid:
        return jsonify({
            "error": "no_linked_sheet",
            "message": "No generated Google Sheet is linked to this booking on this "
                       "machine. Generate one first, or use file upload / paste the "
                       "sheet URL.",
        }), 404

    try:
        from timesheet_gsheet_read import read_timesheet
        parsed = read_timesheet(ssid, _gsheet_token_path())
    except Exception as e:
        return jsonify({"error": "Couldn't read the Google Sheet: %s" % e}), 502

    preview, err = _build_import_preview(ss, booking_id, parsed)
    if err:
        return jsonify({"error": err}), 502

    preview["source"] = {"spreadsheet_id": ssid, "url": (link or {}).get("url")}
    return jsonify(preview)


def _gather_timesheet_calls(ss, booking_id):
    """(booking_name, gen_calls, error) for the timesheet generators. gen_calls is
    one dict per call with its CONFIRMED crew (last, first, EIN, phone). EIN + split
    names come from get-call-times (status 5 = confirmed); phone from the booking
    roster, joined on user id."""
    booking, err = fetch_booking_bulk(ss, booking_id)
    if err:
        return None, None, err
    booking_name = (booking.get("name") if isinstance(booking, dict) else None) or ("Booking " + str(booking_id))
    bcalls = booking.get("calls", []) if isinstance(booking, dict) else []

    def _call_sched_dt(c):
        sd = c.get("start_date")
        st = c.get("start_time") or "00:00:00"
        if not sd:
            return None
        try:
            d = datetime.fromtimestamp(int(sd))
        except Exception:
            return None
        mt = re.match(r"(\d{1,2}):(\d{2})", str(st))
        hh = int(mt.group(1)) if mt else 0
        mm = int(mt.group(2)) if mt else 0
        return datetime(d.year, d.month, d.day, hh, mm)

    gen_calls = []
    for c in bcalls:
        cid = c.get("call_id")
        phone_by_uid = {}
        for cr in c.get("crew", []):
            phone_by_uid[cr.get("id")] = (cr.get("mobile") or cr.get("phone") or "")
        crew_out = []
        ct, cterr = ss_get_call_times(ss, cid)
        if cterr is None and isinstance(ct, dict):
            for m in ct.get("crew", []):
                try:
                    st = int(m.get("status") or 0)
                except (TypeError, ValueError):
                    st = 0
                if st != 5:
                    continue  # confirmed only
                uid = m.get("user_id")
                crew_out.append({
                    "lastname":  m.get("lastname") or "",
                    "firstname": m.get("firstname") or "",
                    "ein":       m.get("ein"),
                    "phone":     phone_by_uid.get(uid, ""),
                })
        gen_calls.append({
            "call_id":   cid,
            "call_name": c.get("call_name") or ("Call " + str(cid)),
            "call_time": _call_sched_dt(c),
            "crew":      crew_out,
        })
    return booking_name, gen_calls, None


@app.route("/api/booking/<booking_id>/generate-timesheet", methods=["GET"])
@require_cohort("admin")
def api_generate_timesheet(booking_id):
    """Build a pre-filled crew-master workbook and return it as a download (offline
    path). One Master-cloned tab per call, stamped with Call ID and GIG Call Time,
    pre-filled with that call's CONFIRMED crew."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    booking_name, gen_calls, err = _gather_timesheet_calls(ss, booking_id)
    if err:
        return jsonify({"error": "Couldn't load booking: %s" % err}), 502

    if not os.path.exists(TIMESHEET_TEMPLATE_FILE):
        return jsonify({"error": "Timesheet template not found on this machine (crew_master_template.xlsx)"}), 500

    try:
        from timesheet_generate import generate_timesheet_workbook
        xlsx = generate_timesheet_workbook(TIMESHEET_TEMPLATE_FILE, gen_calls)
    except Exception as e:
        return jsonify({"error": "Generation failed: %s" % e}), 500

    safe = re.sub(r"[^A-Za-z0-9._-]+", "_", booking_name).strip("_") or "booking"
    fname = "Timesheet_%s.xlsx" % safe
    return Response(
        xlsx,
        mimetype="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        headers={"Content-Disposition": 'attachment; filename="%s"' % fname},
    )


@app.route("/api/booking/<booking_id>/generate-gsheet", methods=["GET"])
@require_cohort("admin")
def api_generate_gsheet(booking_id):
    """Generate a native Google Sheet timesheet (online path): copy the crew master
    sheet, duplicate the Master tab per call with CONFIRMED crew pre-filled and the
    Call ID stamped, share it to the configured Ops email, and return its URL.
    Config (config.json): crew_master_template_id, gsheet_share_email,
    gsheet_dest_folder_id (optional — Drive/Shared-Drive folder the sheet is created
    in; empty = the authorising account's My Drive root), google_oauth_token_file
    (defaults to google_token.json)."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    cfg = load_config()
    template_id = (cfg.get("crew_master_template_id") or "").strip()
    share_email = (cfg.get("gsheet_share_email") or "").strip()
    dest_folder = (cfg.get("gsheet_dest_folder_id") or "").strip()
    token_file  = (cfg.get("google_oauth_token_file") or "google_token.json").strip()
    token_path  = token_file if os.path.isabs(token_file) else os.path.join(BASE_DIR, token_file)

    if not template_id:
        return jsonify({"error": "crew_master_template_id not set in config.json"}), 500
    if not os.path.exists(token_path):
        return jsonify({"error": "Google not authorized yet — run 'python3 gsheet_authorize.py' in your gigpower folder, then try again"}), 500

    booking_name, gen_calls, err = _gather_timesheet_calls(ss, booking_id)
    if err:
        return jsonify({"error": "Couldn't load booking: %s" % err}), 502

    try:
        from timesheet_gsheet import generate_timesheet_gsheet
        result = generate_timesheet_gsheet(token_path, template_id, share_email,
                                           booking_name, gen_calls, booking_id, dest_folder)
    except Exception as e:
        return jsonify({"error": "Google Sheet generation failed: %s" % e}), 500

    if isinstance(result, dict) and result.get("spreadsheet_id"):
        _save_timesheet_link(booking_id, result["spreadsheet_id"], result.get("url"))

    return jsonify(result)


def ss_get_crew(ss, crew_id):
    """Fetch one crew member's editable fields via get-crew.php. Returns (data, error)."""
    url = f"{BASE_URL}/ajax/crew/get-crew.php"
    try:
        resp = ss.get(url, params={"id": int(crew_id)}, timeout=60)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        detail = ""
        try:
            detail = resp.json().get("error", "")
        except Exception:
            detail = (resp.text or "")[:200]
        return None, f"HTTP {resp.status_code}: {detail}"
    try:
        data = resp.json()
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return None, data["error"]
    return data, None


def ss_get_crew_shifts(ss, crew_id):
    """Every shift (call assignment) ever made to one crew member, with status,
    newest first, via get-crew-shifts.php. Returns (data, error)."""
    url = f"{BASE_URL}/ajax/crew/get-crew-shifts.php"
    try:
        resp = ss.get(url, params={"id": int(crew_id)}, timeout=60)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        detail = ""
        try:
            detail = resp.json().get("error", "")
        except Exception:
            detail = (resp.text or "")[:200]
        return None, f"HTTP {resp.status_code}: {detail}"
    try:
        data = resp.json()
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return None, data["error"]
    return data, None


def ss_update_crew(ss, crew_id, fields):
    """Update one crew member's record via update-crew.php (form-encoded, so the
    endpoint's $_POST reads it). Returns (data, error)."""
    url = f"{BASE_URL}/ajax/crew/update-crew.php"
    payload = dict(fields or {})
    payload["id"] = int(crew_id)
    try:
        resp = ss.post(url, data=payload, timeout=60)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        detail = ""
        try:
            detail = resp.json().get("error", "")
        except Exception:
            detail = (resp.text or "")[:200]
        return None, f"HTTP {resp.status_code}: {detail}"
    try:
        data = resp.json()
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return None, data["error"]
    return data, None


@app.route("/api/admin/crew/<crew_id>")
@require_cohort("admin")
def api_admin_get_crew(crew_id):
    """One crew member's editable fields, to pre-fill the admin edit form."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    data, err = ss_get_crew(ss, crew_id)
    if err:
        return jsonify({"error": err}), 502
    return jsonify(data)


@app.route("/api/admin/crew/<crew_id>", methods=["POST"])
@require_cohort("admin")
def api_admin_update_crew(crew_id):
    """Update one crew member's record from the admin edit form."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    allowed = ("firstname", "lastname", "mobile", "phone", "dob", "address", "suburb", "state",
               "postcode", "email", "emergency_contact", "emergency_phone",
               "notes", "active", "rating", "groups", "password")
    body = request.get_json(silent=True) or {}
    fields = dict((k, body[k]) for k in allowed if k in body and body[k] is not None)
    data, err = ss_update_crew(ss, crew_id, fields)
    if err:
        return jsonify({"error": err}), 502
    return jsonify(data)


# ── Manage Venues ────────────────────────────────────────────────────────────
# Authored-endpoint pattern, mirroring Manage Crew: get-venue.php (read one,
# admin-gated, includes inactive venues) + update-venue.php (partial write,
# success gated on mysql_error()). The browse list reuses the existing
# ss_get_venues scrape ({id, name}); the click opens the full record.

def ss_get_venue(ss, venue_id):
    """Fetch one venue's editable fields via get-venue.php. Returns (data, error)."""
    url = f"{BASE_URL}/ajax/crew/get-venue.php"
    try:
        resp = ss.get(url, params={"id": int(venue_id)}, timeout=60)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        detail = ""
        try:
            detail = resp.json().get("error", "")
        except Exception:
            detail = (resp.text or "")[:200]
        return None, f"HTTP {resp.status_code}: {detail}"
    try:
        data = resp.json()
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return None, data["error"]
    return data, None


def ss_update_venue(ss, venue_id, fields):
    """Update one venue's record via update-venue.php (form-encoded, so the
    endpoint's $_POST reads it). Returns (data, error)."""
    url = f"{BASE_URL}/ajax/crew/update-venue.php"
    payload = dict(fields or {})
    payload["id"] = int(venue_id)
    try:
        resp = ss.post(url, data=payload, timeout=60)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        detail = ""
        try:
            detail = resp.json().get("error", "")
        except Exception:
            detail = (resp.text or "")[:200]
        return None, f"HTTP {resp.status_code}: {detail}"
    try:
        data = resp.json()
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return None, data["error"]
    return data, None


def ss_list_venues(ss):
    """All venues {id, name, active} via list-venues.php (admin call, no scrape).
    Returns (list, error)."""
    url = f"{BASE_URL}/ajax/crew/list-venues.php"
    try:
        resp = ss.get(url, timeout=60)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        detail = ""
        try:
            detail = resp.json().get("error", "")
        except Exception:
            detail = (resp.text or "")[:200]
        return None, f"HTTP {resp.status_code}: {detail}"
    try:
        data = resp.json()
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return None, data["error"]
    return (data.get("venues") or []), None


@app.route("/api/admin/venue-list")
@require_cohort("admin")
def api_admin_venue_list():
    """Venue list for the Manage Venues UI: {id, name, active}, filtered by
    ?active= (default active-only, ?active=0 for inactive), name-sorted."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    want_active = request.args.get("active", "1") != "0"
    venues, err = ss_list_venues(ss)
    if err:
        return jsonify({"error": err}), 502
    rows = [v for v in venues if int(v.get("active", 1)) == (1 if want_active else 0)]
    rows.sort(key=lambda v: (v.get("name") or "").lower())
    return jsonify({"venues": rows})


@app.route("/api/admin/venue/<venue_id>")
@require_cohort("admin")
def api_admin_get_venue(venue_id):
    """One venue's editable fields, to pre-fill the admin edit form."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    data, err = ss_get_venue(ss, venue_id)
    if err:
        return jsonify({"error": err}), 502
    return jsonify(data)


@app.route("/api/admin/venue/<venue_id>", methods=["POST"])
@require_cohort("admin")
def api_admin_update_venue(venue_id):
    """Update one venue from the admin edit form."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    allowed = ("venue", "address", "suburb", "state", "postcode", "active", "has_induction")
    body = request.get_json(silent=True) or {}
    fields = dict((k, body[k]) for k in allowed if k in body and body[k] is not None)
    if "venue" in fields and not str(fields["venue"]).strip():
        return jsonify({"error": "venue name required"}), 400
    # Normalise the INT flag fields to '1'/'0' strings so update-venue.php's
    # ($_POST[...] === '1') check works regardless of how the client sends them
    # (bool, int, or string).
    for flag in ("active", "has_induction"):
        if flag in fields:
            v = fields[flag]
            fields[flag] = "1" if (v is True or str(v).strip().lower() in ("1", "true", "on")) else "0"
    data, err = ss_update_venue(ss, venue_id, fields)
    if err:
        return jsonify({"error": err}), 502
    return jsonify(data)


def ss_delete_venue(ss, venue_id):
    """Guarded hard delete of one venue via delete-venue.php. Like
    ss_delete_customer this returns (data, status): the PHP endpoint's HTTP status
    is forwarded verbatim so the 409 guard message (bookings / crew inductions /
    induction certificate rows still linked) reaches the UI as a block, not a
    generic 502."""
    url = f"{BASE_URL}/ajax/crew/delete-venue.php"
    try:
        resp = ss.post(url, data={"id": int(venue_id)}, timeout=60)
    except Exception as e:
        return {"error": f"request failed: {e}"}, 502
    try:
        data = resp.json()
    except Exception:
        detail = (resp.text or "")[:200]
        return {"error": detail or f"HTTP {resp.status_code}"}, (resp.status_code if resp.status_code >= 400 else 502)
    return data, resp.status_code


@app.route("/api/admin/venue/<venue_id>/delete", methods=["POST"])
@require_cohort("admin")
def api_admin_delete_venue(venue_id):
    """Guarded hard delete of one venue. Forwards delete-venue.php's JSON + status
    straight through, so a 409 block (still referenced by bookings, crew inductions
    or induction certificate rows) reaches the UI with its reason list intact
    instead of collapsing to a 502."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    data, status = ss_delete_venue(ss, venue_id)
    return jsonify(data), status


# ── Manage Customers ─────────────────────────────────────────────────────────
# Same authored-endpoint pattern as venues. customers is a standalone table
# (no relationships): list-customers.php (browse), get-customer.php (read one,
# incl. inactive), update-customer.php (partial write). customers.active is INT.

def ss_list_customers(ss):
    """All customers {id, name, active} via list-customers.php. Returns (list, error)."""
    url = f"{BASE_URL}/ajax/crew/list-customers.php"
    try:
        resp = ss.get(url, timeout=60)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        detail = ""
        try:
            detail = resp.json().get("error", "")
        except Exception:
            detail = (resp.text or "")[:200]
        return None, f"HTTP {resp.status_code}: {detail}"
    try:
        data = resp.json()
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return None, data["error"]
    return (data.get("customers") or []), None


def ss_get_customer(ss, customer_id):
    """Fetch one customer's editable fields via get-customer.php. Returns (data, error)."""
    url = f"{BASE_URL}/ajax/crew/get-customer.php"
    try:
        resp = ss.get(url, params={"id": int(customer_id)}, timeout=60)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        detail = ""
        try:
            detail = resp.json().get("error", "")
        except Exception:
            detail = (resp.text or "")[:200]
        return None, f"HTTP {resp.status_code}: {detail}"
    try:
        data = resp.json()
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return None, data["error"]
    return data, None


def ss_update_customer(ss, customer_id, fields):
    """Update one customer's record via update-customer.php (form-encoded).
    Returns (data, error)."""
    url = f"{BASE_URL}/ajax/crew/update-customer.php"
    payload = dict(fields or {})
    payload["id"] = int(customer_id)
    try:
        resp = ss.post(url, data=payload, timeout=60)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        detail = ""
        try:
            detail = resp.json().get("error", "")
        except Exception:
            detail = (resp.text or "")[:200]
        return None, f"HTTP {resp.status_code}: {detail}"
    try:
        data = resp.json()
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return None, data["error"]
    return data, None


def ss_delete_customer(ss, customer_id):
    """Guarded hard delete of one customer via delete-customer.php. Unlike the
    other helpers this returns (data, status): the PHP endpoint's HTTP status is
    forwarded verbatim so the 409 guard message (bookings still linked) reaches
    the UI as a block, not a generic 502."""
    url = f"{BASE_URL}/ajax/crew/delete-customer.php"
    try:
        resp = ss.post(url, data={"id": int(customer_id)}, timeout=60)
    except Exception as e:
        return {"error": f"request failed: {e}"}, 502
    try:
        data = resp.json()
    except Exception:
        detail = (resp.text or "")[:200]
        return {"error": detail or f"HTTP {resp.status_code}"}, (resp.status_code if resp.status_code >= 400 else 502)
    return data, resp.status_code


@app.route("/api/admin/customer-list")
@require_cohort("admin")
def api_admin_customer_list():
    """Customer list for the Manage Customers UI: {id, name, active}, filtered by
    ?active= (default active-only, ?active=0 for inactive), name-sorted."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    want_active = request.args.get("active", "1") != "0"
    customers, err = ss_list_customers(ss)
    if err:
        return jsonify({"error": err}), 502
    rows = [c for c in customers if int(c.get("active", 1)) == (1 if want_active else 0)]
    rows.sort(key=lambda c: (c.get("name") or "").lower())
    return jsonify({"customers": rows})


@app.route("/api/admin/customer/<customer_id>")
@require_cohort("admin")
def api_admin_get_customer(customer_id):
    """One customer's editable fields, to pre-fill the admin edit form."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    data, err = ss_get_customer(ss, customer_id)
    if err:
        return jsonify({"error": err}), 502
    return jsonify(data)


@app.route("/api/admin/customer/<customer_id>", methods=["POST"])
@require_cohort("admin")
def api_admin_update_customer(customer_id):
    """Update one customer from the admin edit form."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    allowed = ("customer_name", "phone", "email", "address", "suburb", "state", "postcode", "active")
    body = request.get_json(silent=True) or {}
    fields = dict((k, body[k]) for k in allowed if k in body and body[k] is not None)
    if "customer_name" in fields and not str(fields["customer_name"]).strip():
        return jsonify({"error": "customer name required"}), 400
    # Normalise active to '1'/'0' so update-customer.php's ($_POST['active'] === '1')
    # check works regardless of how the client sends it.
    if "active" in fields:
        v = fields["active"]
        fields["active"] = "1" if (v is True or str(v).strip().lower() in ("1", "true", "on")) else "0"
    data, err = ss_update_customer(ss, customer_id, fields)
    if err:
        return jsonify({"error": err}), 502
    return jsonify(data)


@app.route("/api/admin/customer/<customer_id>/delete", methods=["POST"])
@require_cohort("admin")
def api_admin_delete_customer(customer_id):
    """Guarded hard delete of one customer. Forwards delete-customer.php's JSON +
    status straight through, so a 409 block (bookings still linked) reaches the UI
    with its count message intact instead of collapsing to a 502."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    data, status = ss_delete_customer(ss, customer_id)
    return jsonify(data), status


# ── Manage Contacts ──────────────────────────────────────────────────────────
# Contacts are users in usergroup 4, linked to customers via customer_map.
# list-contacts.php (scoped to usergroup 4, with default customer), get-contact.php
# (one contact + default customer), update-contact.php (fields + password + the
# editable customer_map default link). The customer picker reuses customer-list.

def ss_list_contacts(ss):
    """All contacts {id, name, active, customer_id, customer_name} via
    list-contacts.php. Returns (list, error)."""
    url = f"{BASE_URL}/ajax/crew/list-contacts.php"
    try:
        resp = ss.get(url, timeout=60)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        detail = ""
        try:
            detail = resp.json().get("error", "")
        except Exception:
            detail = (resp.text or "")[:200]
        return None, f"HTTP {resp.status_code}: {detail}"
    try:
        data = resp.json()
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return None, data["error"]
    return (data.get("contacts") or []), None


def ss_get_contact(ss, contact_id):
    """Fetch one contact's editable fields + default customer via get-contact.php.
    Returns (data, error)."""
    url = f"{BASE_URL}/ajax/crew/get-contact.php"
    try:
        resp = ss.get(url, params={"id": int(contact_id)}, timeout=60)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        detail = ""
        try:
            detail = resp.json().get("error", "")
        except Exception:
            detail = (resp.text or "")[:200]
        return None, f"HTTP {resp.status_code}: {detail}"
    try:
        data = resp.json()
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return None, data["error"]
    return data, None


def ss_update_contact(ss, contact_id, fields):
    """Update one contact's record via update-contact.php (form-encoded).
    Returns (data, error)."""
    url = f"{BASE_URL}/ajax/crew/update-contact.php"
    payload = dict(fields or {})
    payload["id"] = int(contact_id)
    try:
        resp = ss.post(url, data=payload, timeout=60)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        detail = ""
        try:
            detail = resp.json().get("error", "")
        except Exception:
            detail = (resp.text or "")[:200]
        return None, f"HTTP {resp.status_code}: {detail}"
    try:
        data = resp.json()
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and "error" in data:
        return None, data["error"]
    return data, None


@app.route("/api/admin/contact-list")
@require_cohort("admin")
def api_admin_contact_list():
    """Contact list for the Manage Contacts UI: {id, name, active, customer_id,
    customer_name}, filtered by ?active= (default active-only), name-sorted."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    want_active = request.args.get("active", "1") != "0"
    contacts, err = ss_list_contacts(ss)
    if err:
        return jsonify({"error": err}), 502
    rows = [c for c in contacts if int(c.get("active", 1)) == (1 if want_active else 0)]
    rows.sort(key=lambda c: (c.get("name") or "").lower())
    return jsonify({"contacts": rows})


@app.route("/api/admin/contact/<contact_id>")
@require_cohort("admin")
def api_admin_get_contact(contact_id):
    """One contact's editable fields + default customer, to pre-fill the edit form."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    data, err = ss_get_contact(ss, contact_id)
    if err:
        return jsonify({"error": err}), 502
    return jsonify(data)


@app.route("/api/admin/contact/<contact_id>", methods=["POST"])
@require_cohort("admin")
def api_admin_update_contact(contact_id):
    """Update one contact from the admin edit form (incl. the customer link)."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    allowed = ("username", "firstname", "lastname", "mobile", "phone", "email",
               "active", "notes", "password", "customer_id")
    body = request.get_json(silent=True) or {}
    fields = dict((k, body[k]) for k in allowed if k in body and body[k] is not None)
    if "username" in fields and not str(fields["username"]).strip():
        return jsonify({"error": "username required"}), 400
    # Normalise active to '1'/'0' (users.active is VARCHAR).
    if "active" in fields:
        v = fields["active"]
        fields["active"] = "1" if (v is True or str(v).strip().lower() in ("1", "true", "on")) else "0"
    data, err = ss_update_contact(ss, contact_id, fields)
    if err:
        return jsonify({"error": err}), 502
    return jsonify(data)


def ss_delete_contact(ss, contact_id):
    """Guarded hard delete of one contact via delete-contact.php. Like the
    customer/venue delete helpers this returns (data, status): the PHP endpoint's
    HTTP status is forwarded verbatim so the 409 guard message (bookings still
    linked — 'Set inactive instead?') reaches the UI as a block, not a 502.
    delete-contact.php is usergroup-4-guarded, so a non-contact id yields 403/404
    from PHP and that status flows straight through too."""
    url = f"{BASE_URL}/ajax/crew/delete-contact.php"
    try:
        resp = ss.post(url, data={"id": int(contact_id)}, timeout=60)
    except Exception as e:
        return {"error": f"request failed: {e}"}, 502
    try:
        data = resp.json()
    except Exception:
        detail = (resp.text or "")[:200]
        return {"error": detail or f"HTTP {resp.status_code}"}, (resp.status_code if resp.status_code >= 400 else 502)
    return data, resp.status_code


@app.route("/api/admin/contact/<contact_id>/delete", methods=["POST"])
@require_cohort("admin")
def api_admin_delete_contact(contact_id):
    """Guarded hard delete of one contact (users, usergroup 4). Forwards
    delete-contact.php's JSON + status straight through, so a 409 block (bookings
    still linked) reaches the UI with its 'Set inactive instead?' nudge intact.
    Deactivate (active='0' via the update route) remains the everyday removal."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    data, status = ss_delete_contact(ss, contact_id)
    return jsonify(data), status


@app.route("/api/admin/crew/<crew_id>/inductions")
@require_cohort("admin")
def api_admin_crew_inductions(crew_id):
    """One crew member's full induction status (on-behalf, via impersonation)."""
    venues, err = fetch_crew_inductions(crew_id)
    if err:
        return jsonify({"error": err}), 502
    return jsonify({"venues": venues})


@app.route("/api/admin/crew/<crew_id>/induction-cert")
@require_cohort("admin")
def api_admin_crew_induction_cert(crew_id):
    """Stream one induction certificate PDF inline, on the crew member's behalf.
    get-induction-cert.php self-scopes to the acquired session user and confirms a
    crew_venue_induction row for (acting crew_id + file), so the impersonated GET
    returns exactly this crew member's own certificate. Mirrors the licences'
    /api/licence/<id>/file View link, but via impersonation (not the admin
    session) because the induction streamer gates on goat_acting_user_id()."""
    fname = (request.args.get("file") or "").strip()
    # Whitelist: basename only, {crew_id}_{time}.pdf shape — no path traversal.
    if not re.match(r'^\d+_\d+\.pdf$', fname):
        return jsonify({"error": "bad filename"}), 400
    with _unavail_write_lock:                       # serialise impersonation ops
        ss, err = _in_impersonated_session(crew_id)
        if err:
            return jsonify({"error": err}), 502
        try:
            resp = ss.get(f"{BASE_URL}/ajax/crew/get-induction-cert.php",
                          params={"file": fname}, timeout=60, allow_redirects=True)
            if resp.status_code != 200:
                try:
                    return jsonify(resp.json()), resp.status_code
                except Exception:
                    return jsonify({"error": f"HTTP {resp.status_code}"}), resp.status_code
            ctype = (resp.headers.get("Content-Type") or "application/pdf").split(";")[0].strip()
            return Response(resp.content, mimetype=ctype, headers={
                "Content-Disposition": resp.headers.get("Content-Disposition", "inline"),
                "X-Content-Type-Options": "nosniff",
            })
        except Exception as e:
            return jsonify({"error": f"request failed: {e}"}), 502
        finally:
            _release(ss)


@app.route("/api/admin/crew/<crew_id>/shifts")
@require_cohort("admin")
def api_admin_crew_shifts(crew_id):
    """Every shift ever assigned to one crew member, with status, newest first."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    data, err = ss_get_crew_shifts(ss, crew_id)
    if err:
        return jsonify({"error": err}), 502
    return jsonify(data)


@app.route("/api/admin/crew/<crew_id>/inductions", methods=["POST"])
@require_cohort("admin")
def api_admin_add_crew_induction(crew_id):
    """Record an induction certificate on a crew member's behalf (fan-out)."""
    venue_ids     = (request.form.get("venue_ids") or "").strip()
    complete_date = (request.form.get("complete_date") or "").strip()
    cert          = request.files.get("certificate")
    if not venue_ids:
        return jsonify({"error": "Select at least one venue"}), 400
    if not complete_date:
        return jsonify({"error": "Completion date required"}), 400
    if not cert:
        return jsonify({"error": "Certificate PDF required"}), 400
    out, err = add_crew_induction(crew_id, venue_ids, complete_date, cert)
    if err:
        return jsonify({"error": err}), 502
    return jsonify(out)


# ── Manage Crew: Licences ─────────────────────────────────────────────────────
# Admin licence CRUD for the Manage Crew -> Licences tab. Each route proxies to
# one smartstaff/admin-*-license*.php endpoint on the admin session, exactly like
# the Manage Venues / Manage Crew routes above. All are admin-gated both here
# (@require_cohort) and at the PHP boundary. Licence reads/writes never touch
# induction rows — that exclusion is enforced in every endpoint. The add path
# reuses ss_push_licence() (the convert-B helper posting to admin-add-license.php).

def ss_list_licences(ss, user_id):
    """One user's non-induction licences via admin-list-licenses.php (raw rows;
    the status pill is derived here in app.py). Returns (list, error)."""
    url = f"{BASE_URL}/ajax/crew/admin-list-licenses.php"
    try:
        resp = ss.get(url, params={"user": int(user_id)}, timeout=60)
    except Exception as e:
        return None, f"request failed: {e}"
    if resp.status_code != 200:
        detail = ""
        try:
            detail = resp.json().get("error", "")
        except Exception:
            detail = (resp.text or "")[:200]
        return None, f"HTTP {resp.status_code}: {detail}"
    try:
        data = resp.json()
    except Exception as e:
        return None, f"bad JSON: {e}"
    if isinstance(data, dict) and data.get("error"):
        return None, data["error"]
    return (data.get("licences") or []), None


def ss_edit_licence(ss, licence_id, ltype, date_certified, date_expiry, pdf_file):
    """Edit / renew one licence via admin-edit-license.php. `pdf_file` is an
    uploaded FileStorage or None (None = keep the existing file). Returns
    (result_dict, error). A skipped/exists case can't happen on edit."""
    data = {
        "id":             str(int(licence_id)),
        "type":           ltype,
        "date_certified": date_certified or "",
        "date_expiry":    date_expiry or "",
    }
    files = None
    if pdf_file:
        files = {"licence_pdf": (pdf_file.filename or "licence.pdf",
                                 pdf_file.read(), "application/pdf")}
    try:
        resp = ss.post(f"{BASE_URL}/ajax/crew/admin-edit-license.php",
                       data=data, files=files, timeout=60)
    except Exception as e:
        return None, f"request failed: {e}"
    try:
        out = json.loads(resp.text or "{}")
    except Exception:
        return None, f"HTTP {resp.status_code}: {(resp.text or '')[:200]}"
    if not (isinstance(out, dict) and out.get("ok")):
        return None, (isinstance(out, dict) and out.get("error")) or f"HTTP {resp.status_code}"
    return out, None


def ss_delete_licence(ss, licence_id):
    """Delete one licence via admin-delete-license.php. Returns (result, error)."""
    try:
        resp = ss.post(f"{BASE_URL}/ajax/crew/admin-delete-license.php",
                       data={"id": str(int(licence_id))}, timeout=60)
    except Exception as e:
        return None, f"request failed: {e}"
    try:
        out = json.loads(resp.text or "{}")
    except Exception:
        return None, f"HTTP {resp.status_code}: {(resp.text or '')[:200]}"
    if not (isinstance(out, dict) and out.get("ok")):
        return None, (isinstance(out, dict) and out.get("error")) or f"HTTP {resp.status_code}"
    return out, None


@app.route("/api/user/<int:user_id>/licences")
@require_cohort("admin")
def api_admin_list_licences(user_id):
    """One crew member's licences, each with a derived compliance status, sorted
    attention-first (expired -> expiring_soon -> unknown -> valid -> na)."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    licences, err = ss_list_licences(ss, user_id)
    if err:
        return jsonify({"error": err}), 502
    return jsonify({"licences": _decorate_licences(licences)})


@app.route("/api/user/<int:user_id>/licences", methods=["POST"])
@require_cohort("admin")
def api_admin_add_licence(user_id):
    """Add one licence (multipart: type, optional dates, optional licence_pdf).
    Idempotent per (user, type) at the endpoint — a re-add returns skipped."""
    ltype = _canonical_licence_type(request.form.get("type"))
    if not ltype:
        return jsonify({"error": "Invalid licence type"}), 400
    date_cert = _licence_date_ymd(request.form.get("date_certified"))
    date_exp  = _licence_date_ymd(request.form.get("date_expiry"))
    pdf       = request.files.get("licence_pdf")
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    pdf_bytes = pdf.read() if pdf else None
    out, err = ss_push_licence(ss, user_id, ltype, date_cert, date_exp, pdf_bytes)
    if err:
        return jsonify({"error": err}), 502
    return jsonify(out)


@app.route("/api/licence/<int:licence_id>/edit", methods=["POST"])
@require_cohort("admin")
def api_admin_edit_licence(licence_id):
    """Edit / renew one licence (multipart; blank licence_pdf keeps the current
    file). This is the renewal path — new dates + new PDF replace the line."""
    ltype = _canonical_licence_type(request.form.get("type"))
    if not ltype:
        return jsonify({"error": "Invalid licence type"}), 400
    date_cert = _licence_date_ymd(request.form.get("date_certified"))
    date_exp  = _licence_date_ymd(request.form.get("date_expiry"))
    pdf       = request.files.get("licence_pdf")
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    out, err = ss_edit_licence(ss, licence_id, ltype, date_cert, date_exp, pdf)
    if err:
        return jsonify({"error": err}), 502
    return jsonify(out)


@app.route("/api/licence/<int:licence_id>/delete", methods=["POST"])
@require_cohort("admin")
def api_admin_delete_licence(licence_id):
    """Delete one licence (row + its file(s))."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    out, err = ss_delete_licence(ss, licence_id)
    if err:
        return jsonify({"error": err}), 502
    return jsonify(out)


@app.route("/api/licence/<int:licence_id>/file")
@require_cohort("admin")
def api_admin_licence_file(licence_id):
    """Stream one licence's PDF (or legacy image) inline, straight through from
    admin-get-license-file.php with the upstream content-type."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401
    try:
        resp = ss.get(f"{BASE_URL}/ajax/crew/admin-get-license-file.php",
                      params={"id": int(licence_id)}, timeout=60)
    except Exception as e:
        return jsonify({"error": f"request failed: {e}"}), 502
    if resp.status_code != 200:
        try:
            return jsonify(resp.json()), resp.status_code
        except Exception:
            return jsonify({"error": f"HTTP {resp.status_code}"}), resp.status_code
    ctype = (resp.headers.get("Content-Type") or "application/octet-stream").split(";")[0].strip()
    return Response(resp.content, mimetype=ctype, headers={
        "Content-Disposition": resp.headers.get("Content-Disposition", "inline"),
        "X-Content-Type-Options": "nosniff",
    })


if __name__ == "__main__":
    app.run(host="127.0.0.1", port=5001, debug=False, threaded=True)