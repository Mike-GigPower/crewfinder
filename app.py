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
import math
import threading
import functools
from datetime import datetime, timedelta
from flask import Flask, jsonify, request, render_template, session, redirect, url_for
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

APP_VERSION    = "3.5.3"
VERSION_URL    = "https://raw.githubusercontent.com/Mike-GigPower/crewfinder/main/version.json"

# ─── BULK ENDPOINTS (SmartStaff /ajax/crew/*) ─────────────────────────────────
# When True, the app will try the new bulk SmartStaff endpoints first and fall
# back to HTML scraping on any failure. Safe to leave True even before the
# endpoints are deployed — the fallback handles 404s transparently.
# Set to False in config.json (key: "use_bulk_endpoints": false) to force the
# legacy scraper path for A/B comparison.
USE_BULK_ENDPOINTS = True
USE_BULK_UNAVAILS_ENDPOINT = True
USE_BULK_BOOKED_CREW_ENDPOINT = True
try:
    if os.path.exists(CONFIG_FILE):
        _cfg = json.load(open(CONFIG_FILE))
        if "use_bulk_endpoints" in _cfg:
            USE_BULK_ENDPOINTS = bool(_cfg["use_bulk_endpoints"])
        if "use_bulk_unavails_endpoint" in _cfg:
            USE_BULK_UNAVAILS_ENDPOINT = bool(_cfg["use_bulk_unavails_endpoint"])
        if "use_bulk_booked_crew_endpoint" in _cfg:
            USE_BULK_BOOKED_CREW_ENDPOINT = bool(_cfg["use_bulk_booked_crew_endpoint"])
except Exception:
    pass


_ss_sessions     = {}  # per-user SmartStaff sessions keyed by app session id
_ss_identity     = {}  # sid -> {user_id, ein, name, usergroupID, cohort}
_ss_creds        = {}  # sid -> {username, password} for per-session reauth
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

def venue_to_coords(venue_str):
    """Free-text SmartStaff venue tag -> (lat, lon, label) via the known-venue
    postcode table. The tag may be the full induction name ("Festival Hall")
    or the short code ("Forum", "JCA", "MCA"). Returns None if unrecognised."""
    vl = (venue_str or "").strip().lower()
    if not vl:
        return None
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

    return inductions

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
            "inductions": c.get("inductions", {}) or {},
            "ein":        c.get("ein") or c.get("id"),  # prefer endpoint EIN; fall back to userID
            "postcode":   str(c.get("postcode") or "").strip(),
            "notes":      c.get("notes") or "",          # users.notes — for the name-hover card
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
        saved_username=cfg.get("username", "")
    )

@app.route("/logout")
def logout():
    sid = session.pop("sid", None)
    if sid:
        _ss_sessions.pop(sid, None)
        _ss_identity.pop(sid, None)
        _ss_creds.pop(sid, None)
    return redirect(url_for("login"))

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
            result[0] = scrape_calls(ss, f"{BASE_URL}/dash")
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

@app.route("/api/availability", methods=["POST"])
@require_cohort("admin")
def api_availability():
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

    data            = request.json
    required_groups = data.get("required_groups", [])
    min_rating      = int(data.get("min_rating", 3))
    radius_km       = data.get("radius_km")     # None => geo filter off (back-compat)
    origin          = data.get("origin")        # {"mode":"venue"} or {"mode":"postcode","postcode":"3000"}

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

        if reasons:
            skipped.append({**crew, "reason": " | ".join(reasons)})
            continue

        # Geo filter — only for crew that already pass rating/groups.
        if geo_active:
            cpc    = crew.get("postcode") or cache.get(cid, {}).get("postcode", "")
            ccoord = postcode_to_coords(cpc)
            if not ccoord:
                location_unknown.append({**crew, "reason": "No/unknown postcode"})
                continue
            dist = haversine_km(origin_coords[0], origin_coords[1], ccoord["lat"], ccoord["lon"])
            crew["distance_km"] = round(dist, 1)
            if dist > float(radius_km):
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
        "targets":     [{"call_id": t["call_id"], "call_num": t["call_num"],
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
    return jsonify({"groups": [
        "W.B.", "PhD.", "CI Card", "JOAT", "Audio", "Backline",
        "Fork", "Lights", "Set/Stg", "Spot", "Truck", "Wardrobe",
        "EWP", "MCEC", "MCG", "MOPT"
    ]})

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
        total_hours = 0.0
        for s in ws:
            cs = max(datetime.fromisoformat(s["start"]), start_dt)
            ce = min(datetime.fromisoformat(s["end"]),   end_dt)
            total_hours += (ce - cs).total_seconds() / 3600
            cur = cs.replace(hour=0, minute=0, second=0, microsecond=0)
            while cur < ce:
                ds = cur.strftime("%Y-%m-%d")
                day_hours[ds] = day_hours.get(ds, 0) + (min(ce, cur + timedelta(days=1)) - max(cs, cur)).total_seconds() / 3600
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

    # Leadership can't scrape the admin /bookings pages, so it reads the call
    # list from the DB-backed bulk endpoint. Admin keeps the proven scrape.
    if current_cohort() in LEADERSHIP_COHORTS:
        calls = fetch_calls_bulk(ss, days=days)
    else:
        calls = scrape_schedule(ss, days=days)

    # Group by booking_id
    bookings = {}
    for c in calls:
        bid = c["booking_id"]
        if bid not in bookings:
            bookings[bid] = {
                "booking_id":   bid,
                "booking_name": c["booking_name"],
                "venue":        c["venue"],
                "contact":      c["contact"],
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
    """Scrape crew already booked for a call with their confirmation status."""
    ss = get_ss_session()
    if not ss:
        return jsonify({"error": "Not logged in"}), 401

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

You have access to live SmartStaff data through your tools. Use them proactively — if someone asks about availability, actually check it. If they ask about inductions, pull the data. When someone asks for crew near a place — "riggers within 20km of the Forum", "who's available near Geelong" — call search_availability with radius_km and near (a 4-digit postcode or a venue name). Crew too far away come back in skipped with their distance, and anyone with no known postcode lands in location_unknown — mention them, don't pretend they're not there.

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
                calls = scrape_calls(ss, f"{BASE_URL}/dash")
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
            for cid, info in list(cache.items())[:100]:
                inductions = info.get("inductions", {})
                for venue_name, ind in inductions.items():
                    if venue_filter and venue_filter not in venue_name.lower():
                        continue
                    status = ind.get("status", "")
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
    confirm = body.get("confirm", False)
    action  = "confcrew" if confirm else "addcrew"

    results = []
    for call in calls:
        for c in crew:
            url = f"{BASE_URL}/add-call.php?action={action}&id={call['call_id']}&userID={c['crew_id']}"
            try:
                resp = ss.get(url, allow_redirects=True)
                results.append({
                    "crew":    c["name"],
                    "call":    call.get("call_name", call["call_id"]),
                    "success": resp.status_code == 200,
                })
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
            results.append({
                "call":    call.get("call_name", call["call_id"]),
                "success": resp.status_code == 200,
            })
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
        "name":   ident.get("name", ""),
        "ein":    ident.get("ein", ""),
        "cohort": ident.get("cohort", "crew"),
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

    # Scrape SmartStaff lists for matching
    customers = ss_get_customers(ss)
    venues    = ss_get_venues(ss)
    contacts  = ss_get_contacts(ss)

    customer_match        = fuzzy_match(customer["company_name"], customers)
    venue_match           = fuzzy_match(event.get("venue_name", ""), venues)
    contact_match         = fuzzy_match(customer.get("contact_name", ""), contacts)
    # Onsite contact: if the export didn't supply one, fall back to the booking
    # contact name so the onsite field matches/displays the same person.
    onsite_contact_name   = (event.get("onsite_contact") or "").strip() \
        or (customer.get("contact_name") or "").strip()
    onsite_contact_match  = fuzzy_match(onsite_contact_name, contacts)

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
            # Step 1: create booking
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
            booking_id, err = ss_create_booking(ss, booking_data)
            if err:
                _import_progress["errors"].append(f"Booking creation failed: {err}")
                _import_progress["running"] = False
                return

            _import_progress["booking_id"] = booking_id
            _import_progress["step"] = f"Booking #{booking_id} created. Adding calls..."

            # Step 2: create each call sequentially
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

            # Step 2b: create each non-labour item as an "Other" call.
            # IMPORTANT: SmartStaff pre-fills the add-call form client-side, but
            # does NOT default these fields server-side — a POST with blank
            # start_date/length is rejected and the form re-renders (no redirect),
            # which reads as a failure. So we send the same values a hand-entered
            # "Other" call ends up with: booking's earliest date, 00:00, length 0.
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
                    # SmartStaff uses call_name_hidden as the actual displayed call name.
                    # Post the item name for both fields — the select value doesn't
                    # matter since call_name_hidden always takes precedence.
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


if __name__ == "__main__":
    app.run(host="127.0.0.1", port=5001, debug=False, threaded=True)