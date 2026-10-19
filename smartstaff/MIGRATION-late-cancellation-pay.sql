-- ─────────────────────────────────────────────────────────────────────────
-- MIGRATION — Late cancellation: pay crew and charge the customer
-- (slice A of BRIEF-late-cancellation-pay.md; decisions D13–D21)
--
-- calls.cancel_pay                 the "Pay confirmed crew?" answer at cancel time
-- calls.cancel_times_confirmed_at  done-tick for the ops Times outstanding lane;
--                                  set by update-call-times.php when Rich saves
--                                  every paid-cancellation row. Exists because
--                                  pre-filled times look "keyed".
-- call_crew_map.cancel_pay         the same answer, per stood-down row
--
-- cancel_charge keeps its column and its meaning: "Charge the customer?".
--
-- The paid-cancellation test is DERIVED, never stored:
--   status = 9 AND prev_status = 5 AND (cancel_pay = 1 OR cancel_charge = 1)
-- (goat_ccm_cancel_paid_sql() in cohort.php; widened copy in
-- classes/class.accounting.php.)
--
-- Quiet window, export first, TEST then PROD. MyISAM locks each table while
-- it rebuilds. Run as the app's own DB user in phpMyAdmin.
--
-- ORDER: this BEFORE any slice B PHP and BEFORE the class.accounting.php
-- patch. The accounting patch names call_crew_map.cancel_pay outright; on a
-- database without it, locking and invoicing break for EVERY call.
-- ─────────────────────────────────────────────────────────────────────────

-- 0. PRE-CHECK — read-only. Paste the result back; it sizes step 3.

SELECT COUNT(*)                    AS rows_that_will_match,
       COUNT(DISTINCT m.callID)    AS calls,
       SUM(c.call_locked = 1)      AS rows_on_locked_calls,
       SUM(m.`on` <> '00:00:00' OR m.`off` <> '00:00:00') AS rows_with_times
  FROM call_crew_map m
  JOIN calls c ON c.id = m.callID
 WHERE m.status = 9 AND m.prev_status = 5 AND m.cancel_charge = 1;

-- 1. The columns.

ALTER TABLE calls
  ADD COLUMN cancel_pay                 TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN cancel_times_confirmed_at  INT        NULL DEFAULT NULL;

ALTER TABLE call_crew_map
  ADD COLUMN cancel_pay  TINYINT(1) NOT NULL DEFAULT 0;

-- 2. Verify — paste both outputs back.

SHOW COLUMNS FROM calls         LIKE 'cancel_%';
SHOW COLUMNS FROM call_crew_map LIKE 'cancel_%';

-- 3. BACKFILL — decided by Mike, 19 Oct 2026. Run AFTER step 1, same window.
--
-- The paid test is cancel_pay = 1 OR cancel_charge = 1, so every row stood
-- down from Confirmed on a call cancelled AS CHARGEABLE since 5.24.0 starts
-- passing it the moment step 1 runs. Their times are 00:00:00, and without
-- this they would appear on the ops Times outstanding lane as
-- "Cancelled – paid" with nothing pre-filled. Ticking them done takes them
-- off the lane. cancel_charge keeps its recorded meaning.
--
-- Every cancelled call is ticked, chargeable or not (as in
-- NOTE-late-cancellation-first-calls-2026-10-19): a tick on a call that was
-- never paid or charged is inert, and it settles 38608 along with the rest.
--
-- It does NOT stop the class.accounting.php patch picking those rows up if
-- such a call is locked later (zero hours, presumably). Step 0's
-- rows_on_locked_calls shows how many are already past that point.
--
-- 39023, 39024, 39025 ARE EXCLUDED. They are the first real late
-- cancellations. oneoff-late-cancel-pay.php handles them after slice B is
-- deployed, and they must stay on the lane until Rich checks their times.
-- They are PROD ids: on TEST this exclusion matches nothing, which is fine.

UPDATE calls
   SET cancel_times_confirmed_at = cancelled_at
 WHERE cancelled_at > 0
   AND cancel_times_confirmed_at IS NULL
   AND id NOT IN (39023, 39024, 39025);

-- 4. Verify the backfill. Expect 0 rows except 39023–39025 (if they are
--    cancelled as chargeable).

SELECT id, call_name, cancel_pay, cancel_charge, cancel_times_confirmed_at
  FROM calls
 WHERE cancelled_at IS NOT NULL
   AND (cancel_pay = 1 OR cancel_charge = 1)
   AND cancel_times_confirmed_at IS NULL;
