#!/bin/bash
# THE GOAT — the one documented way to check the repo. See CLAUDE.md "Checks".
#
#   ./run_tests.sh                 syntax checks, then every test_*.py
#   ./run_tests.sh test_a.py ...   syntax checks, then only those files
#   ./run_tests.sh -v ...          also list each passing file and its time
#   ./run_tests.sh --no-syntax ... skip the syntax checks
#
# Expected result on main: everything passes. A test that is known to fail is
# marked in the test file itself (@unittest.expectedFailure, with a comment
# saying why), not remembered as a count — so any failure here is new.
#
# Syntax checks:
#   - py_compile on every tracked top-level *.py (app.py included);
#   - node --check on each inline <script> in templates/index.html, and on
#     the vendored static/*.js. Skipped, with a note, if node isn't installed.
#
# Tests: each test_*.py runs in its OWN process. Some are unittest files, some
# are scripts that sys.exit() at import (so `pytest` over the whole repo
# crashes), and a few are pytest-style with no main. Files with
# `unittest.main` or `sys.exit` run directly; the rest run under pytest.
#
# Exit status: 0 if everything passed, 1 otherwise. No network, no SmartStaff,
# no Supabase: every test stubs them.

set -u
cd "$(dirname "$0")" || exit 2

if [ -x venv/bin/python ]; then PY=venv/bin/python; else PY=python3; fi
VERBOSE=0
SYNTAX=1
FILES=()
for a in "$@"; do
  case "$a" in
    -v|--verbose) VERBOSE=1 ;;
    --no-syntax)  SYNTAX=0 ;;
    -h|--help)    sed -n '2,24p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *)            FILES+=("$a") ;;
  esac
done
if [ ${#FILES[@]} -eq 0 ]; then
  for f in test_*.py; do FILES+=("$f"); done
fi

TMP=$(mktemp -d "${TMPDIR:-/tmp}/goat-tests.XXXXXX")
trap 'rm -rf "$TMP"' EXIT
FAILED=()
PASSED=0

fail() {  # name, log file
  FAILED+=("$1")
  echo "FAIL  $1"
  sed 's/^/      | /' "$2" | tail -n 40
}

# ── Syntax ───────────────────────────────────────────────────────────────────
if [ "$SYNTAX" = 1 ]; then
  if git ls-files -z -- '*.py' ':!:*/*' | xargs -0 "$PY" -m py_compile >"$TMP/py.log" 2>&1; then
    PASSED=$((PASSED + 1)); [ "$VERBOSE" = 1 ] && echo "ok    py_compile (top-level *.py)"
  else
    fail "py_compile" "$TMP/py.log"
  fi

  if command -v node >/dev/null 2>&1; then
    # Every inline <script> (no src) in index.html, each checked on its own.
    "$PY" - "$TMP" <<'PYEOF'
import re, sys
src = open("templates/index.html", encoding="utf-8").read()
for i, body in enumerate(re.findall(r"<script>(.*?)</script>", src, re.S)):
    open("%s/inline-%d.js" % (sys.argv[1], i), "w", encoding="utf-8").write(body)
PYEOF
    ok=1
    : >"$TMP/js.log"
    for js in "$TMP"/inline-*.js static/*.js; do
      [ -e "$js" ] || continue
      node --check "$js" >>"$TMP/js.log" 2>&1 || { ok=0; echo "  in $js" >>"$TMP/js.log"; }
    done
    if [ "$ok" = 1 ]; then
      PASSED=$((PASSED + 1)); [ "$VERBOSE" = 1 ] && echo "ok    node --check (index.html inline scripts, static/*.js)"
    else
      fail "node --check" "$TMP/js.log"
    fi
  else
    echo "note  node not installed: JS syntax check skipped (JS tests skip themselves too)"
  fi
fi

# ── Tests ────────────────────────────────────────────────────────────────────
for f in "${FILES[@]}"; do
  if [ ! -f "$f" ]; then echo "FAIL  $f (no such file)"; FAILED+=("$f"); continue; fi
  start=$(date +%s)
  if grep -q "unittest.main\|sys.exit" "$f"; then
    "$PY" "$f" >"$TMP/t.log" 2>&1
  else
    "$PY" -m pytest -q -p no:cacheprovider "$f" >"$TMP/t.log" 2>&1
  fi
  rc=$?
  secs=$(( $(date +%s) - start ))
  if [ $rc -eq 0 ]; then
    PASSED=$((PASSED + 1)); [ "$VERBOSE" = 1 ] && echo "ok    $f (${secs}s)"
  else
    fail "$f" "$TMP/t.log"
  fi
done

echo
if [ ${#FAILED[@]} -eq 0 ]; then
  echo "All $PASSED checks passed."
  exit 0
fi
echo "${#FAILED[@]} failed, $PASSED passed: ${FAILED[*]}"
exit 1
