-- ─────────────────────────────────────────────────────────────────────────
-- MIGRATION — Call-change answer history (audit trail for re-confirms)
--
-- call_change_ack is the PENDING flag: its row is DELETED when the change is
-- answered, and that delete-on-answer design is load-bearing (UNIQUE key +
-- INSERT IGNORE in update-call.php; "row exists = pending" in six readers).
-- So the audit trail lives HERE instead, in a separate APPEND-ONLY table.
-- call_change_ack itself is NOT altered.
--
-- One row per ANSWER. Written by:
--   respond-to-change.php  — answer 'accept' / 'decline', answer_src 'crew'
--   dismiss-change-ack.php — answer 'dismiss',           answer_src 'ops'
-- The prev_* / changed_at columns are copied from the call_change_ack row
-- just before it is deleted, so each history row says which change was
-- answered ("was 08:30 for 4h, changed 15:39, declined 16:05").
--
-- Rows are never updated or deleted by the app.
--
-- Run on TEST (smartst_test) first, verify, then PROD (smartst_smartstaff).
-- Run this BEFORE uploading the PHP. (If the PHP lands first, the history
-- INSERT fails silently and the answer itself still succeeds — harmless,
-- just unlogged.)
-- ─────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `call_change_log` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `callID`          INT          NOT NULL,
  `userID`          INT          NOT NULL,
  `prev_start_date` INT          NOT NULL,  -- copied from call_change_ack
  `prev_start_time` VARCHAR(8)   NOT NULL,  -- copied from call_change_ack
  `prev_est_length` DECIMAL(5,2) NOT NULL,  -- copied from call_change_ack
  `changed_at`      INT          NOT NULL,  -- unix ts of the timing edit
  `answer`          VARCHAR(10)  NOT NULL,  -- 'accept' | 'decline' | 'dismiss'
  `answer_src`      VARCHAR(10)  NOT NULL,  -- 'crew' | 'ops'
  `prior_status`    TINYINT      NOT NULL,  -- call_crew_map.status before
  `result_status`   TINYINT      NOT NULL,  -- call_crew_map.status after
  `answered_at`     INT          NOT NULL,  -- unix ts of the answer
  PRIMARY KEY (`id`),
  KEY `idx_call` (`callID`),
  KEY `idx_user` (`userID`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- ── Verification (expect correct structure, empty table) ─────────────────
-- SHOW CREATE TABLE `call_change_log`;
-- SELECT COUNT(*) FROM `call_change_log`;   -- 0
--
-- ── Rollback ─────────────────────────────────────────────────────────────
-- Roll the two PHP files back first, then:
-- DROP TABLE `call_change_log`;
