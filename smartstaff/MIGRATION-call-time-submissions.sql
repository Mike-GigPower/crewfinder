-- ─────────────────────────────────────────────────────────────────────────
-- MIGRATION — Call time submissions (crew boss time entry)
--
-- A submission is a set of times TYPED BY A CREW BOSS after a shift. Nothing
-- here is observed, stamped, or measured by a device. Treat every value as a
-- human recollection entered on a phone in a loading dock.
--
-- WHAT THIS IS NOT: payroll. Nothing in these tables reaches call_crew_map
-- until Ops accept it in THE GOAT through the existing admin path. These are
-- the boss's claim; call_crew_map remains the record.
--
-- APPEND-ONLY. Corrections INSERT a new row pointing at the old one through
-- supersedes_id; nothing is ever edited in place. The actor is a crew boss
-- rather than an Ops person and the data is payroll-adjacent, so the audit
-- trail is the point, not a by-product.
--
-- Superseding a submission supersedes its BREAKS too — a correction writes a
-- fresh set of break rows against the new submission id. Break rows are never
-- moved or rewritten.
--
-- Run on TEST (smartst_test) only in this slice. Prod goes with slice 2 or
-- later — nothing reaches these tables until slice 2 writes to them.
-- ─────────────────────────────────────────────────────────────────────────

-- 1. The submission table. Types match calls.id / users.id (both int(11)).
--
--    THERE IS DELIBERATELY NO UNIQUE KEY ON (callID, userID). Anyone reading
--    this beside call_supervision's uniq_child will assume it was forgotten.
--    It was not. call_supervision permits exactly one boss per call, so a
--    unique key expresses the rule. This table is APPEND-ONLY: a correction is
--    a second row for the same person on the same call, and a unique key would
--    forbid precisely the behaviour the design depends on. The live row is
--    resolved by ORDER BY id DESC in time-submission-graph.php, not by the
--    schema.
--
--    userID DEFAULTS 0 AND IS NOT REQUIRED. An unbooked person whose identity
--    could not be established carries userID = 0 and a typed unbooked_name.
--    Consequence for every consumer: 0 is a real value meaning "unidentified",
--    never a wildcard. The helpers guard on it explicitly.
--
--    off_next_day / start_next_day carry the shift over midnight. A load-out
--    finishing at 02:00 is off_time 02:00 with off_next_day = 1, not 26:00 —
--    TIME would accept 26:00 but nothing else in the estate reads it that way.
CREATE TABLE IF NOT EXISTS `call_time_submissions` (
  `id`             INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `callID`         INT(11) NOT NULL,
  `userID`         INT(11) NOT NULL DEFAULT 0,
  `unbooked`       TINYINT(1) NOT NULL DEFAULT 0,
  `unbooked_name`  VARCHAR(120) NULL DEFAULT NULL,
  `covering_for`   INT(11) NOT NULL DEFAULT 0,
  `on_time`        TIME NULL DEFAULT NULL,
  `off_time`       TIME NULL DEFAULT NULL,
  `off_next_day`   TINYINT(1) NOT NULL DEFAULT 0,
  `note`           VARCHAR(255) NULL DEFAULT NULL,
  `submitted_by`   INT(11) NOT NULL DEFAULT 0,
  `submitted_at`   DATETIME NULL DEFAULT NULL,
  `supersedes_id`  INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `voided`         TINYINT(1) NOT NULL DEFAULT 0,
  `accepted_at`    DATETIME NULL DEFAULT NULL,
  `accepted_by`    INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_call` (`callID`),
  KEY `idx_user_call` (`userID`, `callID`),
  KEY `idx_supersedes` (`supersedes_id`),
  KEY `idx_accepted` (`accepted_at`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- 2. Break rows, one per break, ordered by seq within a submission.
--
--    duration_mins IS AN INTEGER, NOT A TIME STRING, and this is the whole
--    reason the column is not called `break`. call_crew_map.break is
--    varchar(255) and holds values typed past an input with no validation:
--    '00:75', '01:75', '00:60' are all live. '00:75' means FORTY-FIVE MINUTES
--    (confirmed 26 Aug 2026) — it is not 75 minutes, and it is not 1h15. The
--    ambiguity is unresolvable from the data alone. Storing minutes removes it
--    at source. Do not add a convenience column that renders back to 'HH:MM'.
--
--    NO FOREIGN KEY ON submission_id. MyISAM does not enforce them, so one
--    would be decoration that reads as a guarantee. The helpers INNER JOIN
--    instead, which makes orphaned break rows invisible rather than fatal.
CREATE TABLE IF NOT EXISTS `call_time_submission_breaks` (
  `id`              INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `submission_id`   INT(10) UNSIGNED NOT NULL,
  `start_time`      TIME NULL DEFAULT NULL,
  `start_next_day`  TINYINT(1) NOT NULL DEFAULT 0,
  `duration_mins`   INT(11) NOT NULL DEFAULT 0,
  `seq`             TINYINT(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_submission` (`submission_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Engine and collation are MyISAM / latin1_swedish_ci to match calls,
-- call_crew_map, call_feeds and call_supervision (verified 18-19 Aug 2026).
-- A utf8mb4 table here would collate differently in any JOIN against them.

-- ── Verification (run after) ─────────────────────────────────────────────
-- Expect PRIMARY(id), idx_call, idx_user_call(userID,callID), idx_supersedes,
-- idx_accepted — and NO unique key other than PRIMARY. A uniq on
-- (callID,userID) appearing here means someone "fixed" the schema and broke
-- corrections; see the note in section 1.
--
-- SHOW INDEX FROM `call_time_submissions`;
-- SHOW INDEX FROM `call_time_submission_breaks`;
--
-- NOTE: these are SHOW statements, NOT the information_schema query used in
-- MIGRATION-call-supervision.sql. Prod's phpMyAdmin user cannot read
-- information_schema (found 26 Aug 2026), and phpMyAdmin rejects an entire
-- batch if any statement in it references that schema. Run them ONE PER RUN.
--
-- Orphaned break rows (expect zero). Harmless in operation because the helpers
-- INNER JOIN, but a rising count means submissions are being deleted somewhere
-- they should not be — this table is append-only and nothing should DELETE:
--
-- SELECT b.id, b.submission_id
-- FROM call_time_submission_breaks b
-- LEFT JOIN call_time_submissions s ON s.id = b.submission_id
-- WHERE s.id IS NULL;

-- ── Rollback ─────────────────────────────────────────────────────────────
-- Breaks FIRST. Dropping the parent first leaves orphaned break rows behind if
-- the second statement fails, and nothing then knows what they belonged to.
--
-- DROP TABLE IF EXISTS `call_time_submission_breaks`;
-- DROP TABLE IF EXISTS `call_time_submissions`;
