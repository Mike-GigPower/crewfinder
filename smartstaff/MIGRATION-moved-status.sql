-- Status 14 "Moved" — Mike, 25 Nov 2026 (claude/PLAN-added-crew-on-the-day.md, slice 1)
--
-- A crew member the Crew Boss moved to another call on the check-in sheet is
-- set to 14 on the call they were moved OFF, when THE GOAT's Import from
-- check-in confirms them on the new one. Never counted against them, never
-- billed, never blocks a booking; setting 5 again is the undo.
--
-- call_crew_map.status is int(11) NOT NULL DEFAULT 0 (checked on test 25 Nov),
-- so 14 needs NO column change. The ONLY database change is trigger
-- ccm_responded_at_upd's re-offer arm: a 14 -> 0 re-offer must restart the
-- offer clock exactly as 5..13 -> 0 do. Same method as MIGRATION-leave-standby
-- (status 13): the new body is generated FROM THE LIVE BYTES with one REPLACE,
-- checked by length and hash before and after, with a rollback file written
-- first.
--
-- Run in cPanel Terminal with -u and -p (a bare `mysql` is refused).
-- TEST first, verify, then PROD. Never copy a generated file between
-- environments: the DEFINER differs.

-- ── 1. CHECK (phpMyAdmin or CLI) — read only ───────────────────────────────
SET @db := 'smartst_test';        -- 'smartst_smartstaff' on prod
SELECT LENGTH(ACTION_STATEMENT) AS len,
       MD5(ACTION_STATEMENT)    AS md5,
       DEFINER                  AS definer,
       LENGTH(REPLACE(ACTION_STATEMENT,
              'IN (5,6,7,8,9,10,11,12,13) AND NEW.status = 0',
              'IN (5,6,7,8,9,10,11,12,13,14) AND NEW.status = 0')) AS new_len
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = @db AND TRIGGER_NAME = 'ccm_responded_at_upd';
-- EXPECT on TEST (read 25 Nov 2026 23:43):
--   len 394, md5 15bc20aa40500486608697da36d160cb,
--   definer smartst_testu@localhost, new_len 397
-- PROD: definer smartst_smartst@localhost; len should be 394 and new_len 397.
--   new_len 394 = the REPLACE matched nothing: STOP.

-- ── 2. ROLLBACK FILE FIRST (cPanel Terminal) — the unmodified body ─────────
--  TEST:
-- mysql -u smartst_testu -p -N -r -D smartst_test <<'SQL' > ~/trg14-test-rollback.sql
-- SELECT CONCAT('DELIMITER $$\n',
--   'CREATE OR REPLACE DEFINER=`smartst_testu`@`localhost` TRIGGER ccm_responded_at_upd\n',
--   '  BEFORE UPDATE ON call_crew_map FOR EACH ROW\n',
--   ACTION_STATEMENT, '$$\nDELIMITER ;\n')
-- FROM information_schema.TRIGGERS
-- WHERE TRIGGER_SCHEMA = 'smartst_test' AND TRIGGER_NAME = 'ccm_responded_at_upd';
-- SQL
--  PROD: same with -u smartst_smartst, -D smartst_smartstaff, DEFINER `smartst_smartst`,
--        TRIGGER_SCHEMA 'smartst_smartstaff', file ~/trg14-prod-rollback.sql

-- ── 3. GENERATE THE NEW TRIGGER (cPanel Terminal) ──────────────────────────
--  TEST:
-- mysql -u smartst_testu -p -N -r -D smartst_test <<'SQL' > ~/trg14-test-new.sql
-- SELECT CONCAT('DELIMITER $$\n',
--   'CREATE OR REPLACE DEFINER=`smartst_testu`@`localhost` TRIGGER ccm_responded_at_upd\n',
--   '  BEFORE UPDATE ON call_crew_map FOR EACH ROW\n',
--   REPLACE(ACTION_STATEMENT,
--           'IN (5,6,7,8,9,10,11,12,13) AND NEW.status = 0',
--           'IN (5,6,7,8,9,10,11,12,13,14) AND NEW.status = 0'),
--   '$$\nDELIMITER ;\n')
-- FROM information_schema.TRIGGERS
-- WHERE TRIGGER_SCHEMA = 'smartst_test' AND TRIGGER_NAME = 'ccm_responded_at_upd';
-- SQL
--  PROD: as above with the prod user, database, DEFINER and file ~/trg14-prod-new.sql

-- ── 4. APPLY, then re-run step 1 ───────────────────────────────────────────
-- mysql -u smartst_testu -p -D smartst_test < ~/trg14-test-new.sql
-- EXPECT step 1 afterwards: len 397, and new_len 397 (nothing left to replace).
-- ROLLBACK: mysql -u smartst_testu -p -D smartst_test < ~/trg14-test-rollback.sql
