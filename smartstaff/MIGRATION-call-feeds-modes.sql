-- ─────────────────────────────────────────────────────────────────────────
-- MIGRATION — Feed modes (locked vs recommended)
--
-- locked      : crew booked on the source are COMMITTED to the target.
--               Offers expand, availability is intersected, the package is
--               answered as one unit. This is v4.11.0 behaviour.
-- recommended : crew booked on the source are PREFERRED for the target.
--               Offers still expand (rows are created on both calls), but
--               availability ranks rather than filters, the two calls are
--               answered independently, and the slots count as `likely`
--               rather than `reserved`.
--
-- DEFAULT 'locked' is deliberate: every existing edge, including every
-- migrated symmetric link, keeps today's behaviour untouched.
--
-- Run on TEST (smartst_test) first, verify, then PROD.
-- ─────────────────────────────────────────────────────────────────────────

ALTER TABLE `call_feeds`
  ADD COLUMN `mode` ENUM('locked','recommended') NOT NULL DEFAULT 'locked'
  AFTER `target_call`;

-- ── Verification (expect every row 'locked', count unchanged) ────────────
-- SELECT mode, COUNT(*) FROM call_feeds GROUP BY mode;
--
-- ── Rollback ─────────────────────────────────────────────────────────────
-- ALTER TABLE `call_feeds` DROP COLUMN `mode`;
--
-- The PHP does NOT need rolling back first. goat_feeds_have_mode() detects
-- the missing column and every traversal falls back to treating all edges as
-- locked — v4.11.0 behaviour. Verified on test 28 Jul 2026 (M3): identical
-- output, no fatals, with the modes PHP still deployed.
