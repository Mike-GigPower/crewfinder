-- ─────────────────────────────────────────────────────────────────────────
-- MIGRATION — Link button for Crew Boss calls (Release 2, slice 1)
-- BRIEF-default-link-self-submit-off B1; DESIGN-default-link-self-submit-off
-- v0.6 §4.3 (the marker, J7) and §4.4 (`auto`).
--
-- 1. call_no_crew_boss — "this call really has no Crew Boss". One row per
--    call. It silences the amber "Not linked" warning and probe P3 for that
--    call, and the Link button never offers it. It does NOT change the tier
--    and does NOT switch self-submit on (J1). Linking the call by hand
--    deletes it ("linking wins"); unlinking a call the button would offer
--    writes it (J7).
--
-- 2. call_supervision.auto — 1 = the link was made by the Link button
--    (auto-link-supervision.php), 0 = made by hand in the per-call editor
--    (call-supervision.php). created_by is the operator either way. Existing
--    rows read 0, which is right: every one of them was made by hand.
--
-- Engine and collation match call_supervision and calls on test
-- (MyISAM / latin1_swedish_ci, read 9 Nov 2026). Types match calls.id (int(11)).
-- set_at is a unix timestamp like call_supervision.created; set_by is a
-- SmartStaff userID like created_by.
--
-- Size: call_supervision held 3 rows on test (9 Nov 2026). The ALTER rebuilds a
-- table that small instantly; CC confirms from prod's row count whether prod
-- needs an out-of-hours slot.
--
-- Re-runnable: CREATE TABLE IF NOT EXISTS and ADD COLUMN IF NOT EXISTS
-- (MariaDB; the box runs 11.4.13-MariaDB).
--
-- Run on TEST (smartst_test) first, verify, then PROD (smartst_smartstaff).
-- Select the database in phpMyAdmin's left panel before pasting.
-- ─────────────────────────────────────────────────────────────────────────

-- 1. The marker.
CREATE TABLE IF NOT EXISTS `call_no_crew_boss` (
  `callID`  INT(11) NOT NULL,
  `set_by`  INT(11) NOT NULL,
  `set_at`  INT(11) NOT NULL,
  PRIMARY KEY (`callID`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- 2. Which links the button made.
ALTER TABLE `call_supervision`
  ADD COLUMN IF NOT EXISTS `auto` TINYINT(1) NOT NULL DEFAULT 0;

-- ── Verification (run after; replace nothing — the schema is written out) ──
--
-- On TEST. On PROD use smartst_smartstaff in place of smartst_test.
--
-- Both tables MyISAM / latin1_swedish_ci, and call_no_crew_boss present:
--
-- SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
-- FROM information_schema.TABLES
-- WHERE TABLE_SCHEMA = 'smartst_test'
--   AND TABLE_NAME IN ('call_supervision', 'call_no_crew_boss', 'calls');
--
-- The new column, NOT NULL, default 0:
--
-- SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
-- FROM information_schema.COLUMNS
-- WHERE TABLE_SCHEMA = 'smartst_test'
--   AND TABLE_NAME = 'call_supervision'
-- ORDER BY ORDINAL_POSITION;
--
-- Every existing link reads auto = 0, and the row count is unchanged
-- (3 on test before the migration):
--
-- SELECT `auto`, COUNT(*) AS total_rows
-- FROM smartst_test.call_supervision
-- GROUP BY `auto`;
--
-- The marker table is empty, with PRIMARY (callID):
--
-- SELECT COUNT(*) AS total_rows FROM smartst_test.call_no_crew_boss;
-- SHOW INDEX FROM smartst_test.call_no_crew_boss;

-- ── Rollback ─────────────────────────────────────────────────────────────
-- Deploy the PHP that no longer reads these BEFORE rolling back the schema:
-- the new endpoints, call-supervision.php, supervision-graph.php,
-- get-booking.php and get-bookings-bulk.php all read them.
--
-- ALTER TABLE `call_supervision` DROP COLUMN IF EXISTS `auto`;
-- DROP TABLE IF EXISTS `call_no_crew_boss`;
