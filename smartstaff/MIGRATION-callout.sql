-- ─────────────────────────────────────────────────────────────────────────
-- MIGRATION — Backup call-out (DESIGN-backup-callout-v0_2 §4.2)
--
-- call_callout: one row per call-out ops open on a call. Open while
-- closed_at IS NULL; at most one open per call (enforced by open-callout.php,
-- not by a key — MyISAM, and closed rows are kept). close_reason records
-- which path closed it:
--
--   'filled'  : the call filled — an accept (respond-to-call.php), ops setting
--               someone Confirmed (update-crew-status.php), or the sweep's
--               full-call catch-all (callout-sweep.php)
--   'ops'     : Close call-out in THE GOAT (close-callout.php)
--   'started' : the call started with the call-out still open (callout-sweep.php)
--   'empty'   : opened, but every reset raced with another change, so no
--               one was called out (open-callout.php closes it at once)
--
-- call_callout_member: one row per call_crew_map row a call-out reset from
-- Backup (7) to offered (1) — the called call, plus each downstream call
-- reset with it. These rows are what let the close put non-responders back
-- on Backup: every row reset to 1 by a call-out has one, and nothing else
-- does. outcome: confirmed | backup | declined | reverted (NULL = unanswered).
--
-- Why not reuse call_crew_map.prev_status: it already means "status before
-- cancellation", and uncancel-call.php and invoicing read it.
--
-- Rows are KEPT after close, like call_promo_ack — the audit trail of who
-- was asked, who won and who was reverted.
--
-- Run on TEST (smartst_test) first, verify, then PROD (smartst_smartstaff).
-- Nothing reads these tables until the call-out PHP ships.
-- ─────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `call_callout` (
  `id`           INT         NOT NULL AUTO_INCREMENT,
  `callID`       INT         NOT NULL,
  `opened_by`    INT         NOT NULL,
  `opened_at`    INT         NOT NULL,
  `places`       INT         NOT NULL,
  `closed_at`    INT             NULL DEFAULT NULL,
  `close_reason` VARCHAR(12)     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_call_open` (`callID`, `closed_at`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `call_callout_member` (
  `id`          INT         NOT NULL AUTO_INCREMENT,
  `callout_id`  INT         NOT NULL,
  `callID`      INT         NOT NULL,
  `userID`      INT         NOT NULL,
  `outcome`     VARCHAR(12)     NULL DEFAULT NULL,
  `answered_at` INT             NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_member` (`callout_id`, `callID`, `userID`),
  KEY `idx_user_call` (`userID`, `callID`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- ── Verification (expect two empty tables, correct structure) ────────────
-- SHOW CREATE TABLE `call_callout`;
-- SHOW CREATE TABLE `call_callout_member`;
-- SELECT COUNT(*) FROM `call_callout`;          -- 0
-- SELECT COUNT(*) FROM `call_callout_member`;   -- 0
--
-- ── Rollback ─────────────────────────────────────────────────────────────
-- DROP TABLE `call_callout_member`;
-- DROP TABLE `call_callout`;
-- (The PHP must be rolled back first — the reads reference the tables.
--  Before dropping, close any open call-out so no crew row is left at 1:
--  run close-callout.php for each, or revert by hand from the member rows.)
