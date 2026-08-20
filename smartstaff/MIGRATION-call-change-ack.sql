-- ─────────────────────────────────────────────────────────────────────────
-- MIGRATION — Call-change acknowledgement (re-confirm after a timing edit)
--
-- When a call is edited AFTER crew have been contacted, and the edit changes
-- the TIMING (start_date / start_time / est_length), confirmed (status 5) and
-- backup (status 7) crew must be told — and confirmed crew asked to re-confirm.
-- This table is the flag: ONE row per (call, crew) with an OUTSTANDING timing
-- change. The row's PRESENCE is the flag; it also carries the "was" timing so
-- the crew card / ops badge can show the delta ("was 6:00am -> now 8:00am").
--
-- The prev_* columns MIRROR calls.start_date / start_time / est_length. Confirm
-- the three types against `DESCRIBE calls;` BEFORE running — they must match
-- exactly (est_length compared as a double downstream, so DECIMAL(5,2) here).
--
-- The UNIQUE (callID, userID) key is load-bearing: it makes "capture each
-- person's last-agreed time ONCE, don't overwrite on a re-edit" fall out of a
-- plain INSERT IGNORE (update-call.php) — a second edit's insert is silently
-- ignored for anyone still flagged, so their "was" stays the time THEY last
-- signed off on. An accept/decline DELETEs the row (respond-to-change.php), so
-- a later edit re-flags them against the newer time.
--
-- This is a CREATE (not an ALTER on the busy call_crew_map), so the schema step
-- touches no existing rows. MyISAM to match the rest of the schema.
--
-- Run on TEST (smartst_test) first, verify with DESCRIBE, then PROD.
-- ─────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `call_change_ack` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `callID`          INT          NOT NULL,
  `userID`          INT          NOT NULL,
  `prev_start_date` INT          NOT NULL,  -- unix date, mirrors calls.start_date
  `prev_start_time` VARCHAR(8)   NOT NULL,  -- "HH:MM:SS", mirrors calls.start_time
  `prev_est_length` DECIMAL(5,2) NOT NULL,  -- hours, mirrors calls.est_length
  `changed_at`      INT          NOT NULL,  -- unix ts of the edit
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_call_user` (`callID`, `userID`)
) ENGINE=MyISAM;

-- ── Rollback (if ever needed) ────────────────────────────────────────────
-- DROP TABLE IF EXISTS `call_change_ack`;
