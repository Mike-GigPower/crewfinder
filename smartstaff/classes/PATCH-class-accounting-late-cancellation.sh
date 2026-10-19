#!/bin/bash
#
# PATCH — classes/class.accounting.php: let paid-cancellation rows reach pay
# and invoice (BRIEF-late-cancellation-pay, FINDINGS slice 0 §7).
#
# Run by Mike in cPanel Terminal, TEST first, then PROD. Read-only until the
# final step; aborts without writing if the file is not exactly as the 19 Oct
# 2026 probe found it.
#
#   bash PATCH-class-accounting-late-cancellation.sh [path/to/class.accounting.php]
#
# Default path is PROD. Pass the test site's copy explicitly on test.
#
# PREREQUISITE: MIGRATION-late-cancellation-pay.sql has run on the database
# this file talks to. The widened clause names call_crew_map.cancel_pay; on a
# database without it, locking and invoicing break for EVERY call. This
# script cannot check that from the shell — it asks.
#
# WHAT IT CHANGES — three lines, nothing else:
#   406  generateAccountingData, invoice candidate query
#   440  generateAccountingData, per-call line query
#   641  generateCallData, the accounting-row query on call lock
# each replacing   call_crew_map.status=5
# with             (call_crew_map.status=5 OR (call_crew_map.status=9 AND
#                   call_crew_map.prev_status=5 AND (call_crew_map.cancel_pay=1
#                   OR call_crew_map.cancel_charge=1)))
#
# That is the same test as goat_ccm_cancel_paid_sql() in smartstaff/cohort.php.
# This class does not include cohort.php, so it is the one deliberate copy; if
# the rule changes, change both.
#
# AFTERWARDS: copy the patched file back into the repo as
# smartstaff/classes/class.accounting.php, so it is version-controlled from
# now on (classes/ already holds class.sss.php the same way).

set -euo pipefail

F="${1:-/home/smartst/public_html/classes/class.accounting.php}"
NEEDLE='call_crew_map.status=5'
WIDE='(call_crew_map.status=5 OR (call_crew_map.status=9 AND call_crew_map.prev_status=5 AND (call_crew_map.cancel_pay=1 OR call_crew_map.cancel_charge=1)))'
LINES="406 440 641"

[ -f "$F" ] || { echo "ABORT: $F not found"; exit 1; }

echo "File:   $F"
echo "sha256: $(sha256sum "$F" | cut -d' ' -f1)  (before)"
echo

# 1. Not already patched.
if grep -q 'cancel_pay' "$F"; then
	echo "ABORT: file already mentions cancel_pay — patched already? Nothing written."
	exit 1
fi

# 2. The needle appears on EXACTLY the three probed lines, once each, and
#    nowhere else. Anything different means the file moved since the probe.
FOUND="$( { grep -n -F "$NEEDLE" "$F" || true; } | cut -d: -f1 | tr '\n' ' ' | sed 's/ $//')"
if [ "$FOUND" != "406 440 641" ]; then
	echo "ABORT: expected '$NEEDLE' on lines 406 440 641 only; found on: ${FOUND:-none}"
	grep -n -F "$NEEDLE" "$F" || true
	echo "Nothing written. Paste the above back to CC."
	exit 1
fi
for L in $LINES; do
	N="$(sed -n "${L}p" "$F" | grep -o -F "$NEEDLE" | wc -l | tr -d ' ')"
	if [ "$N" != "1" ]; then
		echo "ABORT: line $L contains the needle $N times, expected 1. Nothing written."
		exit 1
	fi
done

echo "Lines to change:"
for L in $LINES; do printf '  %4s  %s\n' "$L" "$(sed -n "${L}p" "$F" | sed 's/^[[:space:]]*//')"; done
echo

# 3. Migration confirmation.
read -r -p "Has MIGRATION-late-cancellation-pay.sql run on THIS environment's database? (type yes) " OK
[ "$OK" = "yes" ] || { echo "ABORT: run the migration first. Nothing written."; exit 1; }

# 4. Backup, then patch the three lines only.
BAK="$F.bak-late-cancel-$(date +%Y%m%d-%H%M%S)"
cp -p "$F" "$BAK"
echo "Backup: $BAK"

NEEDLE="$NEEDLE" WIDE="$WIDE" perl -i -pe '
	BEGIN { $n = $ENV{NEEDLE}; $w = $ENV{WIDE}; }
	s/\Q$n\E/$w/ if $. == 406 || $. == 440 || $. == 641;
' "$F"

# 5. Verify: three widened clauses, no bare needle left, nothing else moved.
W="$(grep -c -F "$WIDE" "$F" || true)"
if [ "$W" != "3" ]; then
	echo "FAILED: expected 3 widened clauses, found $W. Restoring backup."
	cp -p "$BAK" "$F"
	exit 1
fi
CHANGED="$(diff "$BAK" "$F" | grep -c '^[<>]' || true)"
if [ "$CHANGED" != "6" ]; then
	echo "FAILED: expected 3 lines changed (6 diff lines), got $CHANGED. Restoring backup."
	cp -p "$BAK" "$F"
	exit 1
fi

echo
diff "$BAK" "$F" || true
echo
php -l "$F"
echo "sha256: $(sha256sum "$F" | cut -d' ' -f1)  (after)"
echo
echo "Next: lock a test call with a paid cancellation AND an ordinary call, then"
echo "read back their accounting and invoice_lines rows. The ordinary call proves"
echo "nothing else moved. To undo:  cp -p '$BAK' '$F'"
