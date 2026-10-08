"""VIC public holidays, read from the Estimator's Supabase table.

One list, kept in one place: the Estimator maintains public.public_holidays and
THE GOAT reads it with the publishable key (anon has a read policy). Nothing
here writes to the Estimator.

Lazy by design: importing this module, or configure(), does no network work.
The first load_holidays() call does the fetch, so a slow or down Estimator can
never delay THE GOAT opening.

Never raises to a caller. The fallbacks, in order:
  1. in-memory copy, good for 12 hours (force=True skips it)
  2. a fresh fetch, which also rewrites the disk copy
  3. the last good disk copy                 -> meta["stale"] = True
  4. nothing at all -> empty set, meta["unavailable"] = True, and every caller
     falls back to weekends-only maths

Times are naive Melbourne wall clock, the same convention as _ops_lead_bucket
in app.py: no tz conversion anywhere in here.
"""
import json
import os
import threading
import time
from datetime import date, datetime, timedelta

MEMORY_TTL_SECS = 12 * 3600
RETRY_AFTER_FAIL_SECS = 300   # a down Estimator costs one 10 s timeout per 5 min, not one per request
FETCH_TIMEOUT_SECS = 10
YEAR_LOADED_MIN_ROWS = 10     # a year with only Australia Day is NOT loaded, not "a quiet year"

_cfg = {"url": "", "key": "", "cache_file": ""}
_lock = threading.Lock()
_state = {
    "rows": None,        # [{"date": date, "name": str}] sorted, or None before the first load
    "meta": None,
    "loaded_at": 0.0,    # monotonic; when rows were last set
    "fail_at": 0.0,      # monotonic; last failed fetch, for the retry backoff
}


def configure(url, key, cache_file):
    """Set the source and the disk-cache path. No I/O. A missing url or key
    is allowed: loads then return the disk copy or 'unavailable'."""
    with _lock:
        _cfg["url"] = (url or "").strip().rstrip("/")
        _cfg["key"] = (key or "").strip()
        _cfg["cache_file"] = cache_file or ""
        _state.update(rows=None, meta=None, loaded_at=0.0, fail_at=0.0)


# ─── fetch / disk ────────────────────────────────────────────────────────────

def _parse_rows(raw):
    """[{holiday_date, name}] or [{date, name}] -> sorted [{date, name}].
    Bad rows are skipped; returns None if raw is not a list."""
    if not isinstance(raw, list):
        return None
    out = {}
    for r in raw:
        if not isinstance(r, dict):
            continue
        ds = r.get("holiday_date") or r.get("date")
        try:
            d = date.fromisoformat(str(ds)[:10])
        except Exception:
            continue
        out[d] = str(r.get("name") or "").strip()
    return [{"date": d, "name": out[d]} for d in sorted(out)]


def _fetch_remote():
    """(rows, error). The key is only ever sent as a header; errors carry the
    HTTP status or exception type, never the request, so it cannot leak."""
    if not _cfg["url"] or not _cfg["key"]:
        return None, "not configured"
    try:
        import requests as http   # lazy: the maths and the tests need no network stack
        r = http.get(
            _cfg["url"] + "/rest/v1/public_holidays",
            params={"select": "holiday_date,name", "state_code": "eq.VIC",
                    "is_active": "eq.true", "order": "holiday_date"},
            headers={"apikey": _cfg["key"]},
            timeout=FETCH_TIMEOUT_SECS)
    except Exception as e:
        return None, f"request failed: {type(e).__name__}"
    if r.status_code != 200:
        return None, f"HTTP {r.status_code}"
    try:
        rows = _parse_rows(r.json())
    except Exception:
        return None, "bad JSON"
    if rows is None:
        return None, "unexpected response shape"
    # An empty list is what a broken read policy looks like (RLS returns [] not
    # 401). Treat it as a failure so it can't overwrite a good disk copy.
    if not rows:
        return None, "no rows returned"
    return rows, None


def _read_disk():
    """(rows, saved_at_iso) or (None, None)."""
    path = _cfg["cache_file"]
    if not path:
        return None, None
    try:
        with open(path) as f:
            d = json.load(f)
        rows = _parse_rows(d.get("holidays"))
        if not rows:
            return None, None
        return rows, d.get("saved_at")
    except Exception:
        return None, None


def _write_disk(rows, saved_at):
    """Temp file + replace, so a crash mid-write can't destroy the last good
    copy that the stale fallback depends on."""
    path = _cfg["cache_file"]
    if not path:
        return
    tmp = path + ".tmp"
    try:
        with open(tmp, "w") as f:
            json.dump({"saved_at": saved_at,
                       "holidays": [{"date": r["date"].isoformat(), "name": r["name"]}
                                    for r in rows]}, f, indent=1)
        os.replace(tmp, path)
    except Exception:
        try:
            os.remove(tmp)
        except Exception:
            pass


# ─── public API ──────────────────────────────────────────────────────────────

def load_holiday_rows(force=False):
    """(rows, meta). rows = sorted [{"date": date, "name": str}].
    meta = {source, fetched_at, stale, unavailable, error}. Never raises."""
    try:
        with _lock:
            now = time.monotonic()
            have = _state["rows"] is not None
            fresh = have and (now - _state["loaded_at"]) < MEMORY_TTL_SECS \
                and not _state["meta"].get("stale")
            backing_off = (now - _state["fail_at"]) < RETRY_AFTER_FAIL_SECS \
                and _state["fail_at"] > 0
            if have and not force and (fresh or backing_off):
                return list(_state["rows"]), dict(_state["meta"])
            if not have and not force and backing_off:
                return [], _unavailable_meta(_state["meta"] and _state["meta"].get("error"))

            rows, err = _fetch_remote()
            if rows is not None:
                fetched_at = datetime.now().isoformat(timespec="seconds")
                _write_disk(rows, fetched_at)
                meta = {"source": "Estimator", "fetched_at": fetched_at,
                        "stale": False, "unavailable": False, "error": None}
                _state.update(rows=rows, meta=meta, loaded_at=now, fail_at=0.0)
                return list(rows), dict(meta)

            _state["fail_at"] = now
            disk, saved_at = _read_disk()
            if disk:
                meta = {"source": "Estimator", "fetched_at": saved_at,
                        "stale": True, "unavailable": False, "error": err}
                _state.update(rows=disk, meta=meta, loaded_at=now)
                return list(disk), dict(meta)
            meta = _unavailable_meta(err)
            _state.update(rows=None, meta=meta)
            return [], dict(meta)
    except Exception as e:
        return [], _unavailable_meta(f"internal: {type(e).__name__}")


def _unavailable_meta(err):
    return {"source": "Estimator", "fetched_at": None, "stale": False,
            "unavailable": True, "error": err}


def load_holidays(force=False):
    """(set[date], meta). Empty set + meta['unavailable'] means weekends only."""
    rows, meta = load_holiday_rows(force=force)
    return {r["date"] for r in rows}, meta


def is_working_day(d, holidays=None):
    """Mon-Fri and not a VIC public holiday. Pass holidays to skip the load
    (tests, or a caller doing many lookups)."""
    if isinstance(d, datetime):
        d = d.date()
    if d.weekday() >= 5:
        return False
    if holidays is None:
        holidays, _ = load_holidays()
    return d not in holidays


def working_hours(now, start, holidays=None):
    """Working hours between now and start (naive Melbourne wall clock).

    Walks one calendar day at a time, adding only the part of each working day
    inside [now, start). 0 if start <= now. A 14-day lead is at most 15 steps."""
    if start <= now:
        return 0.0
    if holidays is None:
        holidays, _ = load_holidays()
    total = 0.0
    day = now.date()
    end_day = start.date()
    while day <= end_day:
        day_start = datetime.combine(day, datetime.min.time())
        day_end = day_start + timedelta(days=1)
        if is_working_day(day, holidays):
            lo = max(now, day_start)
            hi = min(start, day_end)
            if hi > lo:
                total += (hi - lo).total_seconds()
        day += timedelta(days=1)
    return total / 3600.0


def covered_years(holidays=None):
    """Years with at least YEAR_LOADED_MIN_ROWS holiday rows."""
    if holidays is None:
        holidays, _ = load_holidays()
    counts = {}
    for d in holidays:
        counts[d.year] = counts.get(d.year, 0) + 1
    return {y for y, n in counts.items() if n >= YEAR_LOADED_MIN_ROWS}
