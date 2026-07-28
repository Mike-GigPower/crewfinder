ALTER TABLE `call_feeds`
  ADD COLUMN `mode` ENUM('locked','recommended') NOT NULL DEFAULT 'locked'
  AFTER `target_call`;
