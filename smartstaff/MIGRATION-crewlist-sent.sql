-- ─────────────────────────────────────────────────────────────────────────
-- MIGRATION — Crew list sent marker (DESIGN-crewlist-sent-v0_2 §4,
-- BRIEF-crewlist-sent Slice A)
--
-- call_crewlist_sent: one row each time a call's crew list is marked sent.
-- The row is a SNAPSHOT of what was printed (design §4.1), taken by THE GOAT
-- from the same booking data the PDF was made from:
--
--   crew_ids    sorted CSV of the userIDs CONFIRMED (call_crew_map.status 5)
--               on the call when the list was printed — D3: only confirmed
--               crew changes reset the marker
--   start_date  calls.start_date at send time (unix, local midnight)
--   start_time  calls.start_time at send time, 'HH:MM:SS'
--   est_length  calls.est_length at send time
--
-- get-crewlist-status.php compares the snapshot with the live call:
-- everything equal -> 'sent', anything different -> 'changed', no live row
-- -> 'none' (the call is simply absent from the response).
--
-- HISTORY IS KEPT (design §4.3). Marking again adds a row; the NEWEST row with
-- undone_at IS NULL is the current one. Older rows are the answer to "we never
-- got the update".
--
-- UNDO IS A SOFT DELETE (D10). undo-crewlist-sent.php stamps undone_at /
-- undone_by on the newest live row rather than deleting it, so "who marked it,
-- who took it back" survives and the previous send becomes current again.
--
-- bookingID is read from `calls` by mark-crewlist-sent.php, never taken from
-- the client. It is denormalised here only so a booking's history can be read
-- without a join.
--
-- MyISAM + utf8 to match call_callout and the rest of the call_* family.
--
-- Run on TEST (smartst_test) first, verify, then PROD (smartst_smartstaff).
-- Nothing reads this table until the crew-list PHP ships; the PHP must not be
-- deployed to an environment before this has run there (every endpoint
-- would 500 on the missing table).
-- ─────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `call_crewlist_sent` (
  `id`          INT          NOT NULL AUTO_INCREMENT,
  `callID`      INT          NOT NULL,
  `bookingID`   INT          NOT NULL,
  `crew_ids`    TEXT         NOT NULL,
  `start_date`  INT          NOT NULL,
  `start_time`  VARCHAR(8)   NOT NULL,
  `est_length`  DECIMAL(6,2) NOT NULL,
  `sent_by`     INT          NOT NULL,
  `sent_at`     INT          NOT NULL,
  `undone_at`   INT              NULL DEFAULT NULL,
  `undone_by`   INT              NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_call` (`callID`, `undone_at`),
  KEY `idx_booking` (`bookingID`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- ── Verification (expect one empty table, correct structure) ─────────────
-- SHOW CREATE TABLE `call_crewlist_sent`;
-- SELECT COUNT(*) FROM `call_crewlist_sent`;    -- 0
--
-- ── Rollback ─────────────────────────────────────────────────────────────
-- DROP TABLE `call_crewlist_sent`;
-- (The PHP must be rolled back first — mark-crewlist-sent.php,
--  undo-crewlist-sent.php, get-crewlist-status.php and crewlist-sent-lib.php
--  all reference the table. Dropping it loses the send history; there is no
--  other copy.)
