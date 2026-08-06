-- ─────────────────────────────────────────────────────────────────────────
-- MIGRATION — Clash warning acknowledgement
--
-- A row means: ops has acknowledged a fatigue/travel WARNING (Rule 2 or 3)
-- raised against ONE crew member on ONE specific pair of calls. The warning
-- then renders muted instead of amber. This is NOT suppression — the
-- arrangement stays visible, it just stops shouting.
--
-- Only Rules 2 (long-shift gap) and 3 (venue-change buffer) are acknowledgeable.
-- Rule 1 (a genuine time overlap) must be RESOLVED, never dismissed. Rule 4
-- (whole-set 24hr ceiling) has no counterpart call to key against. Neither is
-- ever written here; ack-clash.php rejects rule_no other than 2 or 3 with 400.
--
-- Column meaning — the non-obvious ones:
--   userID    : the crew member WARNED ABOUT.
--   acked_by  : the operator who signed it off. A DIFFERENT person to userID —
--               do not conflate the two.
--   callID_a  : the numerically LOWER of the two call ids, always.
--   callID_b  : the numerically HIGHER. Storing the pair in a fixed numeric
--               order means either direction of a pair resolves to ONE row, and
--               the UNIQUE key below can enforce one ack per (crew, pair, rule).
--   fingerprint : sha1 of a pipe-delimited canonical string built from userID,
--               both call ids (low first) and their calendars.start/end times
--               (type=2) and the rule number. Python builds it when reading the
--               schedule; ack-clash.php rebuilds it when validating a write. The
--               two implementations MUST agree byte-for-byte or an ack silently
--               fails to match on read. See ack-clash.php and the design doc §6.
--   acked_at  : unix time() the ack was written.
--   note      : optional free text ("rolling into own load-out"), <=255 chars.
--
-- Write path is DELETE-then-INSERT, NOT INSERT IGNORE — same reasoning as
-- call_promo_ack. A retime changes the fingerprint; ops acknowledges the NEW
-- arrangement; the old row must be replaced, not preserved.
--
-- A row whose fingerprint no longer matches the current calendar times is a
-- LAPSED acknowledgement. Nothing deletes it — it simply stops matching, the
-- warning returns amber, and the next acknowledgement overwrites it. There is
-- deliberately no cron cleanup.
--
-- Run on TEST (smartst_test) first, verify, then PROD (smartst_smartstaff).
-- ─────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `call_clash_ack` (
  `id`          INT          NOT NULL AUTO_INCREMENT,
  `userID`      INT          NOT NULL,
  `callID_a`    INT          NOT NULL,
  `callID_b`    INT          NOT NULL,
  `rule_no`     TINYINT      NOT NULL,
  `fingerprint` CHAR(40)     NOT NULL,
  `acked_at`    INT          NOT NULL,
  `acked_by`    INT          NOT NULL,
  `note`        VARCHAR(255)     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pair` (`userID`, `callID_a`, `callID_b`, `rule_no`),
  KEY `idx_calls` (`callID_a`, `callID_b`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- ── Verification (expect an empty table, correct structure) ──────────────
-- SHOW CREATE TABLE `call_clash_ack`;
-- SELECT COUNT(*) FROM `call_clash_ack`;   -- 0
--
-- ── Rollback ─────────────────────────────────────────────────────────────
-- DROP TABLE `call_clash_ack`;
-- (The PHP must be rolled back first — the reads reference the table.)
