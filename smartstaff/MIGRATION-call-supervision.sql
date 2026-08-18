-- ─────────────────────────────────────────────────────────────────────────
-- MIGRATION — Call supervision (crew boss scope)
--
-- An edge "boss_call -> child_call" means: whoever is confirmed on boss_call
-- oversees child_call. It grants VISIBILITY and AUTHORISATION only. It never
-- books anyone onto anything — that is what call_feeds does, and the two must
-- not be confused.
--
-- Three call-relationship concepts now coexist:
--   link_group       vestigial — superseded by call_feeds, nothing displays it
--   call_feeds       live, behavioural — drives offers, packages, capacity
--   call_supervision this — authorisation and contact resolution only
--
-- UNIQUE (child_call) is SINGLE-COLUMN, unlike call_feeds' two-column
-- uniq_edge. This is deliberate: one boss call per supervised call. It is what
-- makes rung 2 of the contact hierarchy deterministic where two boss calls
-- overlap in time. Widening to (boss_call, child_call) later is a one-line
-- migration; narrowing later is not.
--
-- NO BACKFILL. Time overlap cannot distinguish which boss owns which crew —
-- that ambiguity is the reason this table exists. Guessing would reintroduce
-- it invisibly. Edges accumulate as ops set them on new bookings; bookings
-- without edges fall back to today's overlap behaviour unchanged.
--
-- Run on TEST (smartst_test) first, verify, then PROD.
-- ─────────────────────────────────────────────────────────────────────────

-- 1. The edge table. Types match calls.id / calls.bookingID (both int(11)).
--    booking_id is denormalised so get-booking.php can pull every edge in a
--    booking with one indexed query, exactly as call_feeds does.
--    created_by records who granted the access — new to this table; no other
--    smartstaff table carries one.
CREATE TABLE IF NOT EXISTS `call_supervision` (
  `id`          INT(11) NOT NULL AUTO_INCREMENT,
  `booking_id`  INT(11) NOT NULL,
  `boss_call`   INT(11) NOT NULL,
  `child_call`  INT(11) NOT NULL,
  `created`     INT(11) NULL DEFAULT NULL,
  `created_by`  INT(11) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_child` (`child_call`),
  KEY `idx_boss` (`boss_call`),
  KEY `idx_booking` (`booking_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- ── Verification (run after) ─────────────────────────────────────────────
-- Expect PRIMARY(id), uniq_child(child_call) with Non_unique = 0,
-- idx_boss, idx_booking:
--
-- SHOW INDEX FROM `call_supervision`;
--
-- Engine and collation must match calls / call_crew_map / call_feeds:
--
-- SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
-- FROM information_schema.TABLES
-- WHERE TABLE_SCHEMA = DATABASE()
--   AND TABLE_NAME IN ('call_supervision','call_feeds','call_crew_map','calls');
--
-- Any edge pointing at a call that no longer exists (expect zero rows —
-- dangling edges are harmless because every helper INNER JOINs calls, but a
-- non-zero count means deletions are accumulating):
--
-- SELECT s.id, s.boss_call, s.child_call
-- FROM call_supervision s
-- LEFT JOIN calls cb ON cb.id = s.boss_call
-- LEFT JOIN calls cc ON cc.id = s.child_call
-- WHERE cb.id IS NULL OR cc.id IS NULL;

-- ── Rollback ─────────────────────────────────────────────────────────────
-- DROP TABLE IF EXISTS `call_supervision`;
