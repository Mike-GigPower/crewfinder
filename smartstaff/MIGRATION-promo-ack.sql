-- ─────────────────────────────────────────────────────────────────────────
-- MIGRATION — Promotion acknowledgement flag
--
-- A row means: this crew member was promoted from Backup (7) to Confirmed (5)
-- by ops. Pending  = acked_at IS NULL. Answered = acked_at stamped, with
-- acked_src recording which path cleared it:
--
--   'crew' : they answered in the Crew Hub (Accept, or Decline which also
--            flips 5 -> 6 and removes the calendar row)
--   'ops'  : Rich or Monty dismissed the pill because they had already
--            spoken to the person by phone
--
-- Rows are KEPT after acknowledgement, not deleted — that retains the audit
-- trail (promoted when, answered when, by which path) at no cost.
--
-- UNIQUE (callID, userID) mirrors call_change_ack, but the write path is
-- DELETE-then-INSERT, NOT INSERT IGNORE. See update-crew-status.php §2.
--
-- Run on TEST (smartst_test) first, verify, then PROD (smartst_smartstaff).
-- ─────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `call_promo_ack` (
  `id`          INT         NOT NULL AUTO_INCREMENT,
  `callID`      INT         NOT NULL,
  `userID`      INT         NOT NULL,
  `promoted_at` INT         NOT NULL,
  `acked_at`    INT             NULL DEFAULT NULL,
  `acked_src`   VARCHAR(10)     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_call_user` (`callID`, `userID`),
  KEY `idx_pending` (`acked_at`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- ── Verification (expect an empty table, correct structure) ──────────────
-- SHOW CREATE TABLE `call_promo_ack`;
-- SELECT COUNT(*) FROM `call_promo_ack`;   -- 0
--
-- ── Rollback ─────────────────────────────────────────────────────────────
-- DROP TABLE `call_promo_ack`;
-- (The PHP must be rolled back first — the reads reference the table.)
