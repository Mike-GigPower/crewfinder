"""
System health measurement for THE GOAT
======================================

Records one row per outbound HTTP request so the System sub-tab (Today ->
System) can show where the time goes. DESIGN-system-health-v0_3.md is the
reasoning; this module is the measurement.

MEASURE ONLY. Nothing here changes how the GOAT talks to any back end. Every
wrapper returns the call's result unchanged and re-raises its exception
unchanged (§3.2). If recording itself fails, the failure is swallowed and
counted, and the request is unaffected.

Two seams feed this module (§3.1), both in app.py:
  * SmartStaff  -- inside the existing ss.get / ss.post patch in
                   create_ss_session (~1697). That patch already exists, so
                   all 158 SmartStaff call sites are covered without touching
                   one of them, including the two helpers _ss_post_json and
                   _accounting_call, which call through the session.
  * Everything else on requests -- the `http` alias shim (~25).

Not measured in v1: the Anthropic SDK (2 sites) and Google's httplib2 stack
(14 sites). Neither goes through `requests`, so neither seam can see them.
The Estimator's two calls (pricing_rules.py, public_holidays.py) each do
their own lazy `import requests as http` in their own module namespace, so
they are also unmeasured in v1.

PRIVACY (§3.2): no query strings, no request bodies, no response bodies, in
memory or in the log. `endpoint_of` keeps the URL path and throws the query
away before the row is built.
"""

import collections
import contextvars
import json
import logging
import logging.handlers
import os
import threading
import time
from datetime import datetime

# ─── ORIGIN ───────────────────────────────────────────────────────────────────
# 'user' (a click caused this) or 'background' (the 15-minute refresh and
# anything else that runs on its own). A new thread does NOT inherit a
# ContextVar, so every background hand-off in app.py submits through
# contextvars.copy_context().run -- see §3.4 and the handoff.
ORIGIN = contextvars.ContextVar('goat_origin', default='user')

# ─── BACK ENDS ────────────────────────────────────────────────────────────────
SMARTSTAFF       = 'smartstaff'
SMARTSTAFF_TEST  = 'smartstaff_test'
SUPABASE         = 'supabase'
CREWHUB          = 'crewhub'
OTHER            = 'other'

# The back ends that share one PHP session lock, and so carry a Busy count.
_LOCKED_BACKENDS = (SMARTSTAFF, SMARTSTAFF_TEST)

MEASURED     = (SMARTSTAFF, SMARTSTAFF_TEST, SUPABASE, CREWHUB, OTHER)
NOT_MEASURED = ('anthropic', 'google')

# ─── THRESHOLDS (design §2.4) ─────────────────────────────────────────────────
# Milliseconds. "proposed, tune after a week" -- the design's own words, so
# these are expected to move once Rich's and Monty's logs come back.
#
# Green  : p95 under `green_p95` AND no errors.
# Amber  : p95 under `amber_p95`, or 1-2 errors.
# Red    : p95 at or over `amber_p95`, or 3+ errors, or ANY timeout, or any
#          'check failed' re-login.
# Grey   : no requests in the window.
#
# The check-failed-re-login rule is scoped to the SmartStaff back ends: a
# re-login is a fact about the SmartStaff session, and letting it colour
# Supabase or the Crew Hub red would be meaningless. The System tab's own dot
# takes the worst back-end colour, so the design's intent still holds.
THRESHOLDS = {
    SMARTSTAFF:      {'green_p95': 2000, 'amber_p95': 5000},
    SMARTSTAFF_TEST: {'green_p95': 2000, 'amber_p95': 5000},
    SUPABASE:        {'green_p95': 1500, 'amber_p95': 4000},
    CREWHUB:         {'green_p95': 1500, 'amber_p95': 4000},
    OTHER:           {'green_p95': 1500, 'amber_p95': 4000},
    'errors_amber':  1,   # 1-2 errors -> amber
    'errors_red':    3,   # 3 or more  -> red
    'slow_log_ms':   2000,  # §4: log any request over this
}

# ─── STORE (§3.2) ─────────────────────────────────────────────────────────────
# One deque behind one lock, modelled on _licence_catalogue_cache /
# public_holidays._state: module-level state, a single threading.Lock, and
# monotonic-free epoch stamps so the rows can be windowed and uploaded as-is.
_ROWS_MAX     = 5000
_EVENTS_MAX   = 500

_rows      = collections.deque(maxlen=_ROWS_MAX)
_checks    = collections.deque(maxlen=_EVENTS_MAX)
_relogins  = collections.deque(maxlen=_EVENTS_MAX)
_bg_runs   = collections.deque(maxlen=_EVENTS_MAX)
_store_lock = threading.Lock()

# Recording failures are swallowed (§3.2) but counted, so summary() can say so
# rather than lying quietly.
_internal = {'errors': 0, 'first_error': None}

# Set ONLY by inject_demo_rows (dev-only, refused in a frozen build) so the busy
# lamps have something to show when the synthetic rows have no live traffic
# behind them. None means "report the real counter".
_demo = {'inflight': None}

# ─── SMARTSTAFF IN-FLIGHT COUNTER (§3.3) ──────────────────────────────────────
_inflight      = 0
_inflight_lock = threading.Lock()


def _enter():
    """Increment the in-flight count and return the value from BEFORE the
    increment -- i.e. how many SmartStaff requests were already open when this
    one was dispatched. That is the number the design calls Busy."""
    global _inflight
    with _inflight_lock:
        before = _inflight
        _inflight += 1
        return before


def _exit():
    global _inflight
    with _inflight_lock:
        if _inflight > 0:
            _inflight -= 1


# ─── HOSTS ────────────────────────────────────────────────────────────────────
# Filled by init() from app.py's own constants. No second copy of a hostname
# lives here: if app.py's config points BASE_URL at the test instance, this
# module follows it.
_hosts = {
    'smartstaff_prod': '',   # PROD_BASE_URL's host
    'smartstaff_base': '',   # BASE_URL's host (prod, or test when overridden)
    'supabase':        '',   # the main project's host
    'crewhub':         '',   # crew.gigpower.com
}


def _host_of(url):
    """Host part of a URL, lowercased, no scheme, no port, no path, no query.
    Deliberately hand-rolled rather than urlsplit: this runs on every request
    and must never raise."""
    s = str(url or '')
    i = s.find('://')
    if i != -1:
        s = s[i + 3:]
    for sep in ('/', '?', '#'):
        j = s.find(sep)
        if j != -1:
            s = s[:j]
    if '@' in s:
        s = s.rsplit('@', 1)[1]
    if ':' in s:
        s = s.split(':', 1)[0]
    return s.strip().lower()


def classify(url):
    """Back end for a URL, from the HOST ONLY (never the path, never the query).

    'smartstaff_test' is any other host under smartstaffsolutions.com, so a
    source run pointed at test.smartstaffsolutions.com is reported separately
    instead of being mixed into the prod numbers.

    The Estimator is a SECOND Supabase project. Design §2.1 files it under
    "Other", so only the main project's host is 'supabase'; any other
    *.supabase.co falls through to 'other'.
    """
    try:
        host = _host_of(url)
        if not host:
            return OTHER
        prod = _hosts.get('smartstaff_prod') or ''
        base = _hosts.get('smartstaff_base') or ''
        if prod and host == prod:
            return SMARTSTAFF
        if base and host == base:
            return SMARTSTAFF if host == prod else SMARTSTAFF_TEST
        if host.endswith('smartstaffsolutions.com'):
            return SMARTSTAFF if host == prod else SMARTSTAFF_TEST
        sb = _hosts.get('supabase') or ''
        if sb and host == sb:
            return SUPABASE
        ch = _hosts.get('crewhub') or ''
        if ch and host == ch:
            return CREWHUB
        return OTHER
    except Exception:
        return OTHER


def endpoint_of(url):
    """The URL's PATH only -- no scheme, no host, no query string, no fragment.
    This is the privacy boundary (§3.2): the query never enters a row, so it
    can never reach memory or the log."""
    try:
        s = str(url or '')
        i = s.find('://')
        if i != -1:
            s = s[i + 3:]
            j = s.find('/')
            s = s[j:] if j != -1 else '/'
        for sep in ('?', '#'):
            k = s.find(sep)
            if k != -1:
                s = s[:k]
        return s or '/'
    except Exception:
        return '/'


# ─── ROW HELPERS ──────────────────────────────────────────────────────────────

def _now_iso(epoch=None):
    """ISO local timestamp, 24-hour, to milliseconds."""
    dt = datetime.fromtimestamp(epoch) if epoch is not None else datetime.now()
    return dt.isoformat(timespec='milliseconds')


def _note_internal_error(exc=None):
    """A recording failure. Counted, never raised, never allowed near the
    request. summary() reports the count so a silently broken ring buffer
    cannot masquerade as a healthy one."""
    try:
        with _store_lock:
            _internal['errors'] += 1
            if _internal['first_error'] is None and exc is not None:
                _internal['first_error'] = type(exc).__name__
    except Exception:
        pass


def _public(row):
    """The row as it leaves this module: exactly the §3.2 fields, with the
    private windowing key removed."""
    return dict((k, v) for k, v in row.items() if not k.startswith('_'))


def _body_already_loaded(resp, kw):
    """True when requests has already read the body, so len(resp.content) is
    free. Never force a streamed body (§3.2 / brief A6)."""
    try:
        if kw.get('stream'):
            return False
        # requests sets _content to False until the body is read.
        return getattr(resp, '_content', False) is not False
    except Exception:
        return False


def _server_ms_of(resp, kw):
    """SmartStaff's own `timing_ms`, when the endpoint reports it.

    Only read from a JSON body under 64 KB that is already loaded (§3.2 /
    brief A6). Four endpoints return it today; health-ping.php is the fifth.
    Anything unexpected leaves it None rather than guessing."""
    try:
        if not _body_already_loaded(resp, kw):
            return None
        ctype = (resp.headers.get('Content-Type') or '').lower()
        if 'json' not in ctype:
            return None
        if len(resp.content) >= 65536:
            return None
        try:
            data = resp.json()
        except Exception:
            return None
        if not isinstance(data, dict):
            return None
        val = data.get('timing_ms')
        if isinstance(val, bool) or not isinstance(val, (int, float)):
            return None
        return round(float(val), 1)
    except Exception:
        return None


def _append(row):
    with _store_lock:
        _rows.append(row)


# ─── THE ONE MEASURED CALL (brief A6) ─────────────────────────────────────────

def timed(method_fn, method_name, url, **kw):
    """Call `method_fn(url, **kw)`, record it, and change nothing about it.

    Both seams call this and only this. The result is returned untouched; an
    exception is re-raised with a bare `raise`, so the class, the message and
    the traceback are exactly what the call site would have seen. That matters
    beyond tidiness: three sites catch `http.exceptions.Timeout` by name
    (app.py:7164, 9624, 27296) and must keep matching.
    """
    backend  = OTHER
    inflight = None
    entered  = False
    epoch    = time.time()

    try:
        backend = classify(url)
        if backend in _LOCKED_BACKENDS:
            inflight = _enter()
            entered = True
    except Exception as e:
        _note_internal_error(e)

    t0   = time.perf_counter()
    resp = None
    exc  = None
    try:
        resp = method_fn(url, **kw)
        return resp
    except Exception as e:
        exc = e
        raise
    finally:
        try:
            if entered:
                _exit()
        except Exception as e:
            _note_internal_error(e)
        try:
            total_ms = round((time.perf_counter() - t0) * 1000.0, 1)
            row = {
                '_epoch':           epoch,
                'ts':               _now_iso(epoch),
                'backend':          backend,
                'endpoint':         endpoint_of(url),
                'method':           str(method_name or '').upper(),
                'total_ms':         total_ms,
                'server_ms':        None,
                'inflight_at_send': inflight,
                'origin':           ORIGIN.get(),
                'status':           None,
                'ok':               False,
                'error':            None,
                'bytes':            None,
            }
            if exc is not None:
                row['error'] = type(exc).__name__
            else:
                status = getattr(resp, 'status_code', None)
                row['status'] = status
                row['ok'] = bool(status is not None and status < 400)
                row['server_ms'] = _server_ms_of(resp, kw)
                if _body_already_loaded(resp, kw):
                    try:
                        row['bytes'] = len(resp.content)
                    except Exception:
                        row['bytes'] = None
            _append(row)
            _maybe_log_row(row)
            _maybe_write_summary(epoch)
        except Exception as e:
            _note_internal_error(e)


# ─── SESSION EVENTS (§3.5) ────────────────────────────────────────────────────

def record_session_check(total_ms, ok, error=None):
    """One is_ss_session_valid() call -- the GET /dash that runs before every
    route. Design §2.2 makes these the hypothesis test, so they are counted
    separately from ordinary requests."""
    try:
        with _store_lock:
            _checks.append({
                '_epoch':   time.time(),
                'ts':       _now_iso(),
                'total_ms': None if total_ms is None else round(float(total_ms), 1),
                'ok':       bool(ok),
                'error':    error,
            })
    except Exception as e:
        _note_internal_error(e)


def record_relogin(reason):
    """One silent full re-login. `reason` is 'expired' (the check answered and
    said invalid) or 'check_failed:<ExceptionClass>' (the check itself raised).
    Telling these apart is the whole point of §2.2: 'check failed' re-logins
    appearing when people say it is slow confirms the hypothesis."""
    try:
        row = {'_epoch': time.time(), 'ts': _now_iso(), 'reason': str(reason or 'unknown')}
        with _store_lock:
            _relogins.append(row)
        _log_line('relogin', _public(row))
    except Exception as e:
        _note_internal_error(e)


def record_background_run(name, started_ts, duration_ms, request_count):
    """One completed background job (the 15-minute cache refresh)."""
    try:
        row = {
            '_epoch':        time.time(),
            'ts':            _now_iso(),
            'name':          str(name or ''),
            'started':       _now_iso(started_ts) if started_ts else None,
            'duration_ms':   None if duration_ms is None else round(float(duration_ms), 1),
            'request_count': int(request_count or 0),
        }
        with _store_lock:
            _bg_runs.append(row)
        _log_line('background', _public(row))
    except Exception as e:
        _note_internal_error(e)


def background_request_count(since_epoch):
    """Rows recorded since `since_epoch` whose origin is 'background'. Used by
    the refresh worker to report how many requests its run made."""
    try:
        with _store_lock:
            return sum(1 for r in _rows
                       if r.get('_epoch', 0) >= since_epoch
                       and r.get('origin') == 'background')
    except Exception as e:
        _note_internal_error(e)
        return 0


# ─── STATISTICS ───────────────────────────────────────────────────────────────

def _pct(values, p):
    """Percentile by sorted index with linear interpolation. No dependency --
    the brief rules out numpy, and this is a handful of thousands of floats."""
    if not values:
        return None
    s = sorted(values)
    if len(s) == 1:
        return round(s[0], 1)
    k = (len(s) - 1) * float(p)
    lo = int(k)
    hi = min(lo + 1, len(s) - 1)
    if lo == hi:
        return round(s[lo], 1)
    return round(s[lo] + (s[hi] - s[lo]) * (k - lo), 1)


def _is_timeout(error):
    """A timeout is any exception class whose name contains 'Timeout' (brief
    A9). That covers requests' ReadTimeout, ConnectTimeout and Timeout, plus
    socket.timeout's class name, without naming classes this module would then
    have to keep in step with."""
    return bool(error) and 'timeout' in str(error).lower()


def _window_stats(rows, backend):
    """count / median / p95 / errors / timeouts / median Busy for one back end,
    plus the min / mean / max the gauges need (addendum "Definitions")."""
    sel = [r for r in rows if r.get('backend') == backend]
    totals = [r['total_ms'] for r in sel if isinstance(r.get('total_ms'), (int, float))]
    errors = [r for r in sel if not r.get('ok')]
    timeouts = [r for r in errors if _is_timeout(r.get('error'))]
    busy = [r['inflight_at_send'] for r in sel
            if isinstance(r.get('inflight_at_send'), int)]
    out = {
        'count':     len(sel),
        'median_ms': _pct(totals, 0.5),
        'p95_ms':    _pct(totals, 0.95),
        'min_ms':    round(min(totals), 1) if totals else None,
        'max_ms':    round(max(totals), 1) if totals else None,
        'mean_ms':   round(sum(totals) / float(len(totals)), 1) if totals else None,
        'errors':    len(errors),
        'timeouts':  len(timeouts),
    }
    out['inflight_median'] = _pct(busy, 0.5) if backend in _LOCKED_BACKENDS else None
    return out


def _current(rows_all, backend, now):
    """(current_ms, current_idle) — the addendum's "Current".

    The median of the last 60 seconds. With nothing in that minute, fall back to
    the most recent known value and flag it idle, so the needle holds its last
    reading greyed out rather than snapping to zero. Never measured at all gives
    (None, False): the needle sits at rest and the card shows an em dash.
    """
    recent = [r['total_ms'] for r in rows_all
              if r.get('backend') == backend
              and r.get('_epoch', 0) >= now - 60
              and isinstance(r.get('total_ms'), (int, float))]
    if recent:
        return _pct(recent, 0.5), False
    for r in reversed(rows_all):
        if r.get('backend') == backend and isinstance(r.get('total_ms'), (int, float)):
            return round(r['total_ms'], 1), True
    return None, False


def _series_60m(rows, backend, now, minutes=60):
    """One bucket per WALL-CLOCK minute for the last hour, oldest first.

    An empty minute keeps its slot with count 0 and null values, so the trace
    can BREAK there (never plot zero) while hover still has a time to report.

    Buckets are aligned to HH:MM:00, NOT to "now minus N x 60 s". 5.123.0 did
    the latter, which moved all 60 bucket edges on every 5-second poll: a
    request at 15:44:30 was reported as minute '15:45' at 15:45:10 and as
    '15:44' at 15:45:40, so minutes that had already finished kept changing
    between refreshes and a request could be filed under a minute that had not
    happened yet. Flooring the epoch to the minute makes a finished minute
    final -- the only slot that still moves is the current one, which is
    genuinely still filling.
    """
    # Epoch minute of `now`, and of the oldest slot we keep.
    now_min   = int(now // 60)
    first_min = now_min - (minutes - 1)

    buckets = [[] for _ in range(minutes)]
    for r in rows:
        if r.get('backend') != backend:
            continue
        if not isinstance(r.get('total_ms'), (int, float)):
            continue
        idx = int(r.get('_epoch', now) // 60) - first_min
        if 0 <= idx < minutes:
            buckets[idx].append(r['total_ms'])
    out = []
    for i, b in enumerate(buckets):
        stamp = (first_min + i) * 60
        out.append({
            't':         datetime.fromtimestamp(stamp).strftime('%H:%M'),
            'median_ms': _pct(b, 0.5) if b else None,
            'max_ms':    round(max(b), 1) if b else None,
            'count':     len(b),
        })
    return out


def _scale_max(backend, max_ms):
    """Gauge full scale: twice the red cut-off, or the observed peak rounded up
    to the next 500 ms, whichever is larger. A bad spell then pins the needle
    near the top of the dial instead of running off the end of it."""
    th = THRESHOLDS.get(backend) or THRESHOLDS[OTHER]
    floor_scale = 2 * th['amber_p95']
    if isinstance(max_ms, (int, float)) and max_ms > 0:
        stepped = int(((max_ms + 499.0) // 500) * 500)
        return max(floor_scale, stepped)
    return floor_scale


def _status_for(backend, stats, check_failed_relogins):
    """green / amber / red / grey for one back end, per §2.4."""
    if not stats['count']:
        return 'grey'
    th = THRESHOLDS.get(backend) or THRESHOLDS[OTHER]
    errors   = stats['errors']
    timeouts = stats['timeouts']
    p95      = stats['p95_ms'] or 0

    if timeouts:
        return 'red'
    if errors >= THRESHOLDS['errors_red']:
        return 'red'
    if p95 >= th['amber_p95']:
        return 'red'
    if backend in _LOCKED_BACKENDS and check_failed_relogins:
        return 'red'
    if errors >= THRESHOLDS['errors_amber']:
        return 'amber'
    if p95 >= th['green_p95']:
        return 'amber'
    return 'green'


def _sparkline(rows, backend, now, minutes=15):
    """One median per minute over the last `minutes`, oldest first. None for a
    minute with no requests, so the UI can break the polyline rather than draw
    a line through zero."""
    buckets = [[] for _ in range(minutes)]
    for r in rows:
        if r.get('backend') != backend:
            continue
        age = now - r.get('_epoch', now)
        idx = minutes - 1 - int(age // 60)
        if 0 <= idx < minutes and isinstance(r.get('total_ms'), (int, float)):
            buckets[idx].append(r['total_ms'])
    return [_pct(b, 0.5) if b else None for b in buckets]


def summary(now=None):
    """Everything the System tab draws, from memory only. Never raises: a
    failure here would blank the tab, which is worse than a partial answer."""
    try:
        now = float(now) if now is not None else time.time()
        with _store_lock:
            rows     = list(_rows)
            checks   = list(_checks)
            relogins = list(_relogins)
            bg_runs  = list(_bg_runs)
            internal = dict(_internal)

        def within(seq, secs):
            cut = now - secs
            return [x for x in seq if x.get('_epoch', 0) >= cut]

        rows15, rows60 = within(rows, 900), within(rows, 3600)

        # Re-login reasons, needed before the back-end dots (§2.4).
        def relogin_breakdown(seq):
            out = {'total': len(seq), 'expired': 0, 'check_failed': 0, 'by_reason': {}}
            for r in seq:
                reason = str(r.get('reason') or 'unknown')
                out['by_reason'][reason] = out['by_reason'].get(reason, 0) + 1
                if reason == 'expired':
                    out['expired'] += 1
                elif reason.startswith('check_failed'):
                    out['check_failed'] += 1
            return out

        rl15, rl60 = relogin_breakdown(within(relogins, 900)), relogin_breakdown(within(relogins, 3600))

        backends = {}
        for b in MEASURED:
            s15 = _window_stats(rows15, b)
            s60 = _window_stats(rows60, b)
            cur_ms, cur_idle = _current(rows, b, now)
            backends[b] = {
                '15m':          s15,
                '60m':          s60,
                'status':       _status_for(b, s15, rl15['check_failed']),
                'sparkline':    _sparkline(rows15, b, now, 15),
                # Addendum: the gauges, strip and trace.
                'current_ms':   cur_ms,
                'current_idle': cur_idle,
                'min_ms':       s15['min_ms'],
                'mean_ms':      s15['mean_ms'],
                'p95_ms':       s15['p95_ms'],
                'max_ms':       s15['max_ms'],
                'series_60m':   _series_60m(rows60, b, now, 60),
                'scale_max_ms': _scale_max(b, s15['max_ms']),
            }

        def check_stats(seq):
            totals = [c['total_ms'] for c in seq if isinstance(c.get('total_ms'), (int, float))]
            errs = [c for c in seq if not c.get('ok')]
            return {
                'count':     len(seq),
                'median_ms': _pct(totals, 0.5),
                'p95_ms':    _pct(totals, 0.95),
                'errors':    len(errs),
                'timeouts':  len([c for c in errs if _is_timeout(c.get('error'))]),
            }

        slowest = sorted(
            [r for r in rows60 if isinstance(r.get('total_ms'), (int, float))],
            key=lambda r: r['total_ms'], reverse=True)[:20]

        last_bg = bg_runs[-1] if bg_runs else None

        # Addendum G3: the trace shades the minutes a background refresh ran.
        bg60 = []
        for r in within(bg_runs, 3600):
            dur = r.get('duration_ms')
            started = r.get('_epoch', now) - ((dur or 0) / 1000.0)
            bg60.append({
                'start':       datetime.fromtimestamp(started).strftime('%H:%M:%S'),
                'duration_ms': dur,
            })

        return {
            'now':          _now_iso(now),
            'backends':     backends,
            'measured':     list(MEASURED),
            'not_measured': list(NOT_MEASURED),
            # Addendum G4: the live count, not a median. The demo override is
            # only ever set by inject_demo_rows, which the route refuses in a
            # frozen build — so a release always reports the real counter.
            'inflight_now':        (_demo['inflight'] if _demo['inflight'] is not None
                                    else current_inflight()),
            'background_runs_60m': bg60,
            'session': {
                '15m':      check_stats(within(checks, 900)),
                '60m':      check_stats(within(checks, 3600)),
                'relogins': {'15m': rl15, '60m': rl60},
            },
            'background': {
                'last':      _public(last_bg) if last_bg else None,
                'count_60m': len(within(bg_runs, 3600)),
            },
            'slowest':    [_public(r) for r in slowest],
            'thresholds': dict((k, v) for k, v in THRESHOLDS.items()),
            'log_ok':     bool(_log['ok']),
            'log_path':   _log['path'],
            'log_error':  _log['error'],
            'recording_errors': internal['errors'],
            'recording_first_error': internal['first_error'],
        }
    except Exception as e:
        _note_internal_error(e)
        return {
            'now': _now_iso(), 'backends': {}, 'measured': list(MEASURED),
            'not_measured': list(NOT_MEASURED), 'session': {}, 'background': {},
            'slowest': [], 'thresholds': {},
            'inflight_now': 0, 'background_runs_60m': [],
            'log_ok': bool(_log['ok']), 'log_path': _log['path'], 'log_error': _log['error'],
            'summary_error': type(e).__name__,
        }


# ─── FILE LOG (Part C / design §4) ────────────────────────────────────────────
# The first logging configuration in the app. It attaches ONLY to a dedicated
# 'goat.health' logger with propagate = False, so Flask's logger, werkzeug's
# suppressor in menubar.py and pypdf's in visa_extract.py are all untouched.
# No basicConfig, and the root logger is never configured.
_log = {
    'logger':      None,
    'path':        '',
    'dir':         '',
    'ok':          False,
    'error':       None,
    'last_summary': 0.0,
}
_log_lock = threading.Lock()

SUMMARY_EVERY_SECONDS = 900   # §4: a summary line every 15 minutes


def init(log_dir):
    """Set up the file log. Safe to call twice: the second call is a no-op if a
    handler is already attached. If the folder cannot be created or written,
    carry on in memory only and report it through summary() as log_ok false."""
    with _log_lock:
        if _log['logger'] is not None and _log['ok']:
            return True
        _log['dir'] = str(log_dir or '')
        _log['path'] = os.path.join(_log['dir'], 'health.log')
        try:
            os.makedirs(_log['dir'], exist_ok=True)
            logger = logging.getLogger('goat.health')
            logger.setLevel(logging.INFO)
            logger.propagate = False          # never reaches Flask or the root logger
            if not logger.handlers:
                handler = logging.handlers.RotatingFileHandler(
                    _log['path'], maxBytes=1000000, backupCount=5, encoding='utf-8')
                handler.setFormatter(logging.Formatter('%(message)s'))
                logger.addHandler(handler)
            _log['logger'] = logger
            _log['ok'] = True
            _log['error'] = None
            # Prove it is writable now rather than discovering it on the first
            # slow request.
            logger.info(json.dumps({'ts': _now_iso(), 'kind': 'init',
                                    'note': 'health log opened'}, sort_keys=True))
        except Exception as e:
            _log['logger'] = None
            _log['ok'] = False
            _log['error'] = '%s: %s' % (type(e).__name__, e)
            return False
        return True


def _log_line(kind, payload):
    """One JSON object per line. Never raises."""
    try:
        logger = _log['logger']
        if logger is None or not _log['ok']:
            return
        rec = {'ts': payload.get('ts') or _now_iso(), 'kind': kind}
        for k, v in payload.items():
            if k != 'ts':
                rec[k] = v
        logger.info(json.dumps(rec, sort_keys=True, default=str))
    except Exception as e:
        _note_internal_error(e)


def _maybe_log_row(row):
    """§4: write a request row only when it errored, timed out, or took over 2 s."""
    try:
        if (not row.get('ok')) or row.get('error') \
           or (isinstance(row.get('total_ms'), (int, float))
               and row['total_ms'] > THRESHOLDS['slow_log_ms']):
            _log_line('request', _public(row))
    except Exception as e:
        _note_internal_error(e)


def _maybe_write_summary(now):
    """§4 / brief C: write the 15-minute summary line opportunistically, on the
    back of a recorded row. No new timer thread."""
    try:
        with _log_lock:
            if not _log['ok']:
                return
            last = _log['last_summary']
            if last and (now - last) < SUMMARY_EVERY_SECONDS:
                return
            if not last:
                # First row after startup: start the clock, don't emit a
                # summary of nothing.
                _log['last_summary'] = now
                return
            _log['last_summary'] = now
        snap = summary(now)
        line = {}
        for b, d in (snap.get('backends') or {}).items():
            s = d.get('15m') or {}
            if not s.get('count'):
                continue
            line[b] = {'count': s['count'], 'median_ms': s['median_ms'],
                       'p95_ms': s['p95_ms'], 'errors': s['errors'],
                       'timeouts': s['timeouts']}
        _log_line('summary', {'ts': _now_iso(now), 'backends': line})
    except Exception as e:
        _note_internal_error(e)


def configure_hosts(smartstaff_prod='', smartstaff_base='', supabase='', crewhub=''):
    """Take the hosts from app.py's own constants rather than keeping a second
    copy here (brief A2). Called from app.py once the constants exist."""
    try:
        _hosts['smartstaff_prod'] = _host_of(smartstaff_prod)
        _hosts['smartstaff_base'] = _host_of(smartstaff_base)
        _hosts['supabase']        = _host_of(supabase)
        _hosts['crewhub']         = _host_of(crewhub)
        return dict(_hosts)
    except Exception as e:
        _note_internal_error(e)
        return dict(_hosts)


def log_dir():
    return _log['dir']


def current_inflight():
    """The live SmartStaff in-flight count."""
    with _inflight_lock:
        return _inflight


def last_row_for(endpoint):
    """The most recent row whose endpoint matches, or None.

    Used by POST /api/health/ping so the Test-now results are the SAME numbers
    the seam recorded, rather than a second, slightly different measurement
    taken in the route. Matching on the path only keeps it inside the privacy
    boundary."""
    try:
        with _store_lock:
            for r in reversed(_rows):
                if r.get('endpoint') == endpoint:
                    return _public(r)
        return None
    except Exception as e:
        _note_internal_error(e)
        return None


def inject_demo_rows(now=None):
    """Synthetic rows so the graphics can be checked against a bad spell without
    waiting for a real one (addendum Verification 2).

    DEV ONLY, AND INERT IN THE BUNDLE. The route that calls this refuses unless
    `getattr(sys, 'frozen', False)` is False, so in a PyInstaller build the flag
    does nothing at all. It writes into the same ring buffer as real traffic, so
    a demo run leaves rows behind — restart the app from source to clear them.
    """
    now = float(now) if now is not None else time.time()
    import random
    rnd = random.Random(20261120)
    plan = [
        (SMARTSTAFF, 60, 180, 9000),
        (SUPABASE,   60, 120, 2500),
        (CREWHUB,    60,  90, 1200),
        (OTHER,      25, 110, 1500),
    ]
    added = 0
    for backend, per_hour, fast, slow in plan:
        for i in range(per_hour):
            age = rnd.uniform(0, 3540)
            # A deliberate bad spell 8-20 minutes ago, so the peak, the red
            # bands and the trace's peak dot all have something to show.
            in_spell = 480 <= age <= 1200
            if in_spell:
                total = rnd.uniform(slow * 0.55, slow)
            else:
                total = rnd.uniform(fast, fast * 3.2)
            err = None
            ok = True
            status = 200
            if in_spell and rnd.random() < 0.10:
                err, ok, status = 'ReadTimeout', False, None
            epoch = now - age
            row = {
                '_epoch': epoch, 'ts': _now_iso(epoch), 'backend': backend,
                'endpoint': '/demo/synthetic', 'method': 'GET',
                'total_ms': round(total, 1),
                'server_ms': round(total * 0.7, 1) if backend in _LOCKED_BACKENDS else None,
                'inflight_at_send': (rnd.randint(0, 7) if in_spell else rnd.randint(0, 2))
                                    if backend in _LOCKED_BACKENDS else None,
                'origin': 'background' if in_spell and rnd.random() < 0.4 else 'user',
                'status': status, 'ok': ok, 'error': err, 'bytes': rnd.randint(200, 9000),
            }
            _append(row)
            added += 1
    # A couple of re-logins and a background run, so G4 and the trace shading
    # are not empty either.
    with _store_lock:
        for reason, age in (('expired', 1500), ('check_failed:ReadTimeout', 900)):
            _relogins.append({'_epoch': now - age, 'ts': _now_iso(now - age), 'reason': reason})
        for age, dur in ((1200, 41000), (300, 38000)):
            _bg_runs.append({
                '_epoch': now - age, 'ts': _now_iso(now - age),
                'name': 'crew_cache_refresh',
                'started': _now_iso(now - age - dur / 1000.0),
                'duration_ms': dur, 'request_count': 212,
            })
        for age in (30, 90, 150):
            _checks.append({'_epoch': now - age, 'ts': _now_iso(now - age),
                            'total_ms': 180.0, 'ok': True, 'error': None})
    # Light the busy lamps: the synthetic rows are historical, so the live
    # in-flight counter is 0 and G4 would otherwise have nothing to show.
    _demo['inflight'] = 5
    return added


def clear_demo():
    """Drop the demo in-flight override and go back to the real counter."""
    _demo['inflight'] = None


def reset_for_tests():
    """Clear every store. For the throwaway verification script only -- nothing
    in the app calls this."""
    global _inflight
    with _store_lock:
        _rows.clear(); _checks.clear(); _relogins.clear(); _bg_runs.clear()
        _internal['errors'] = 0
        _internal['first_error'] = None
    with _inflight_lock:
        _inflight = 0
