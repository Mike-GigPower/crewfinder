-- ─────────────────────────────────────────────────────────────────────────
-- MIGRATION — Call feeds (directional call dependencies)
--
-- Replaces the symmetric `link_group` model with a directed edge table.
-- "source_call feeds target_call" = crew booked on source are also booked on
-- target. A symmetric link is expressed as two edges (A->B and B->A), so the
-- backfill preserves existing linked-call behaviour exactly.
--
-- `link_group` and `call_link_seq` are intentionally LEFT IN PLACE as a
-- rollback path. A follow-up migration drops them once this has run clean in
-- production for a release or two.
--
-- Run on TEST (smartst_test) first, verify, then PROD.
-- ─────────────────────────────────────────────────────────────────────────

-- 1. The edge table. Types match calls.id / calls.bookingID (both int(11)).
--    booking_id is denormalised so get-booking.php can pull every edge in a
--    booking with one indexed query.
CREATE TABLE IF NOT EXISTS `call_feeds` (
  `id`          INT(11) NOT NULL AUTO_INCREMENT,
  `booking_id`  INT(11) NOT NULL,
  `source_call` INT(11) NOT NULL,
  `target_call` INT(11) NOT NULL,
  `created`     INT(11) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_edge` (`source_call`, `target_call`),
  KEY `idx_source` (`source_call`),
  KEY `idx_target` (`target_call`),
  KEY `idx_booking` (`booking_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- 2. Backfill: every existing link_group becomes its full set of ordered
--    pairs. A group of n calls produces n*(n-1) edges. This is correct — in
--    the old model every member commits you to every other member.
INSERT INTO `call_feeds` (`booking_id`, `source_call`, `target_call`, `created`)
SELECT a.bookingID, a.id, b.id, UNIX_TIMESTAMP()
FROM `calls` a
JOIN `calls` b
  ON a.link_group = b.link_group
 AND a.id <> b.id
WHERE a.link_group IS NOT NULL
  AND a.link_group > 0;

-- ── Verification (run after, expect zero rows) ───────────────────────────
-- Any linked group whose edge count doesn't equal n*(n-1):
--
-- SELECT link_group, COUNT(*) AS n,
--        (SELECT COUNT(*) FROM call_feeds f
--          JOIN calls c2 ON c2.id = f.source_call
--         WHERE c2.link_group = c.link_group) AS edges
-- FROM calls c
-- WHERE link_group IS NOT NULL AND link_group > 0
-- GROUP BY link_group
-- HAVING edges <> n * (n - 1);

-- ── Rollback ─────────────────────────────────────────────────────────────
-- DROP TABLE IF EXISTS `call_feeds`;
