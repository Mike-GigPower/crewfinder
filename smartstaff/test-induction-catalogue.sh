#!/usr/bin/env bash
#
# Stage-A verification for save-/delete-induction-catalogue.php.
# Runs the A3 checklist against smartst_test (then, once green, prod).
#
# These endpoints are admin-gated by SmartStaff SESSION (goat_user_cohort()),
# so you must supply a logged-in admin session cookie. Grab it from the browser
# devtools (Application > Cookies) after logging into SmartStaff as an admin.
#
# Usage:
#   BASE='https://smartst_test.example.com/ajax/crew' \
#   COOKIE='PHPSESSID=xxxxxxxx' \
#   CROWN_ID=<catalogue id of the Crown row> \
#   OTHER_ID=<catalogue id of any other existing row> \
#   ./test-induction-catalogue.sh
#
# CROWN venue_id is assumed to be 2 per the spec's covers-conflict check.

set -u

: "${BASE:?set BASE to the ajax/crew base URL}"
: "${COOKIE:?set COOKIE to an admin PHPSESSID}"
: "${CROWN_ID:?set CROWN_ID to Crown's catalogue id}"
: "${OTHER_ID:?set OTHER_ID to another catalogue row id}"

CROWN_VENUE="${CROWN_VENUE:-2}"

SAVE="$BASE/save-induction-catalogue.php"
DEL="$BASE/delete-induction-catalogue.php"
GET="$BASE/get-induction-catalogue.php"

# curl helper: prints "HTTP <code>\n<body>"
req() {
	curl -sS -w $'\nHTTP %{http_code}\n' -b "$COOKIE" "$@"
}

echo "=================================================================="
echo "1. INSERT a throwaway row, then confirm it appears in the catalogue"
echo "=================================================================="
INS=$(req -X POST "$SAVE" \
	--data-urlencode 'code=ZZTEST' \
	--data-urlencode 'title=Throwaway Test Induction' \
	--data-urlencode 'validity_months=24' \
	--data-urlencode 'warn_days=' \
	--data-urlencode 'show_in_checker=0' \
	--data-urlencode 'published=0' \
	--data-urlencode 'match_keywords=zztest, throwaway')
echo "$INS"
NEW_ID=$(printf '%s' "$INS" | sed -n 's/.*"id":[[:space:]]*\([0-9][0-9]*\).*/\1/p' | head -1)
echo ">> new id = ${NEW_ID:-<none>}"
echo
echo "-- catalogue should now list ZZTEST:"
req "$GET" | grep -o 'ZZTEST' && echo ">> OK: ZZTEST present" || echo ">> FAIL: ZZTEST missing"
echo

echo "=================================================================="
echo "2. PREVIEW Crown at validity_months=12 -> expect non-zero to_expired"
echo "=================================================================="
req -X POST "$SAVE" \
	--data-urlencode "id=$CROWN_ID" \
	--data-urlencode 'preview=1' \
	--data-urlencode 'validity_months=12'
echo

echo "=================================================================="
echo "3. COVERS CONFLICT: assign Crown's venue ($CROWN_VENUE) to OTHER row -> 409"
echo "=================================================================="
req -X POST "$SAVE" \
	--data-urlencode "id=$OTHER_ID" \
	--data-urlencode "venue_ids=$CROWN_VENUE"
echo

echo "=================================================================="
echo "4a. DELETE the throwaway (no completions) -> ok"
echo "=================================================================="
if [ -n "${NEW_ID:-}" ]; then
	req -X POST "$DEL" --data-urlencode "id=$NEW_ID"
else
	echo ">> SKIP: no NEW_ID captured from step 1"
fi
echo

echo "=================================================================="
echo "4b. DELETE Crown (has completions) -> expect 409 in use"
echo "=================================================================="
req -X POST "$DEL" --data-urlencode "id=$CROWN_ID"
echo

echo "Done. Review each HTTP code + body against the spec's expectations."
