-- ─────────────────────────────────────────────────────────────────────────
-- MIGRATION — Linked calls (Phase 1)
--
-- Lets the same crew answer multiple calls in a booking as ONE unit. Calls
-- sharing a non-null `link_group` are one linked set; a crew member's
-- confirm/decline on any of them cascades to all of their offered rows in the
-- group (respond-to-call.php). This migration only adds storage — no data is
-- changed and unlinked calls (link_group NULL) behave exactly as before.
--
-- Run on TEST (smartst_test) first, verify, then PROD.
-- ─────────────────────────────────────────────────────────────────────────

-- 1. The link marker on calls. Nullable: NULL = not linked. Indexed because
--    respond-to-call.php / link-calls.php / get-booking.php look calls up by
--    group.
ALTER TABLE `calls`
  ADD COLUMN `link_group` INT NULL DEFAULT NULL,
  ADD INDEX `idx_link_group` (`link_group`);

-- 2. Group-id generator. Each "link" action inserts one row and stamps the new
--    AUTO_INCREMENT id onto the selected calls. A dedicated counter yields a
--    collision-free, meaningless token — never a reused call id, which would
--    dangle if that call is later cancelled. MyISAM to match the schema.
CREATE TABLE IF NOT EXISTS `call_link_seq` (
  `id`      INT NOT NULL AUTO_INCREMENT,
  `created` INT NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM;

-- ── Rollback (if ever needed) ────────────────────────────────────────────
-- ALTER TABLE `calls` DROP INDEX `idx_link_group`, DROP COLUMN `link_group`;
-- DROP TABLE IF EXISTS `call_link_seq`;
