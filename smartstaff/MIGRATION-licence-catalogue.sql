-- ─────────────────────────────────────────────────────────────────────────
-- MIGRATION — Licence catalogue (Phase 0)
--
-- The licence taxonomy currently lives in FIVE hardcoded copies: app.py's
-- LICENCE_CATALOGUE, website/lib/licences.ts, and the $allowedTypes array in
-- admin-add-license.php, admin-edit-license.php and my-add-license.php. This
-- table becomes the single source; Phase 1 switches all five to read from it,
-- each keeping its constant as a fallback.
--
-- InnoDB, matching venue_induction_catalogue. NO foreign key to user_licenses:
-- that table is MyISAM, the same reason the induction tables carry none.
--
-- `grp`, not `group` — reserved word.
--
-- There is deliberately NO warn_days column. The global LICENCE_WARN_DAYS = 60
-- stays, and per-type windows were ruled out. An unused nullable column the code
-- ignores is a trap for whoever reads this next.
--
-- Run on TEST (smartst_test) first, verify with §3 below, then PROD.
-- ─────────────────────────────────────────────────────────────────────────

-- 1. The table.
CREATE TABLE IF NOT EXISTS `licence_catalogue` (
  `id`                INT(11) NOT NULL AUTO_INCREMENT,
  `code`              VARCHAR(64)  NOT NULL,
  `name`              VARCHAR(255) NOT NULL,
  `grp`               VARCHAR(64)  NOT NULL,
  `expiry_mode`       ENUM('none','date','period') NOT NULL DEFAULT 'none',
  `validity_months`   INT(11) NULL DEFAULT NULL,
  `require_certified` TINYINT(1) NOT NULL DEFAULT 1,
  `published`         TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order`        INT(11) NOT NULL DEFAULT 0,
  `notes`             TEXT NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- 2. Seed — the 41 codes, GENERATED from app.py's LICENCE_CATALOGUE,
--    LICENCE_GROUP_ORDER and LICENCE_EXPIRY_EXPECTED rather than hand-typed. A
--    mistyped code here orphans every user_licenses.type_canonical row that
--    points at it, so the list is machine-produced from the constants already
--    running in production.
--
--    expiry_mode reproduces LICENCE_EXPIRY_EXPECTED exactly:
--      date   — the 10 hard-expiry codes (a blank expiry is a real gap)
--      period — FA only, 36 months, derived from date_certified
--      none   — the remaining 30 (a blank expiry is fine)
--    TC (Traffic Control) is deliberately `none`, matching today's constants.
--
--    require_certified is 1 for the period type (it has nothing to compute from
--    otherwise) and 0 for the rest, which is today's behaviour: no licence type
--    currently demands a certification date. The column DEFAULT is 1 so rows
--    added later by the editor require one unless told otherwise.
--
--    sort_order is group-major: LICENCE_GROUP_ORDER rank * 1000, then the
--    within-group order the constant already used, stepped by 10 for headroom.
--
--    INSERT IGNORE so a re-run on a seeded database is a no-op rather than a
--    duplicate-key abort.
INSERT IGNORE INTO `licence_catalogue`
  (`code`, `name`, `grp`, `expiry_mode`, `validity_months`,
   `require_certified`, `published`, `sort_order`, `notes`)
VALUES
  ('LF', 'Forklift truck', 'Forklift', 'date', NULL, 0, 1, 0, NULL),
  ('LO', 'Order-picking forklift truck', 'Forklift', 'date', NULL, 0, 1, 10, NULL),
  ('WP', 'Boom-type elevating work platform, over 11m', 'EWP', 'date', NULL, 0, 1, 1000, NULL),
  ('EWPSOA', 'EWP statement of attainment, under 11m', 'EWP', 'date', NULL, 0, 1, 1010, NULL),
  ('WAH', 'Working at Heights', 'Heights', 'none', NULL, 0, 1, 2000, NULL),
  ('CI', 'Construction Induction (white card)', 'Construction', 'none', NULL, 0, 1, 3000, NULL),
  ('WWCC', 'Working with Children Check', 'Child safety', 'date', NULL, 0, 1, 4000, NULL),
  ('FA', 'First Aid', 'First aid', 'period', 36, 1, 1, 5000, NULL),
  ('TC', 'Traffic Control', 'Traffic', 'none', NULL, 0, 1, 6000, NULL),
  ('LR', 'Light rigid', 'Driver', 'date', NULL, 0, 1, 7000, NULL),
  ('MR', 'Medium rigid', 'Driver', 'date', NULL, 0, 1, 7010, NULL),
  ('HR', 'Heavy rigid', 'Driver', 'date', NULL, 0, 1, 7020, NULL),
  ('HC', 'Heavy combination', 'Driver', 'date', NULL, 0, 1, 7030, NULL),
  ('MC', 'Multi-combination', 'Driver', 'date', NULL, 0, 1, 7040, NULL),
  ('DG', 'Dogging', 'Rigging', 'none', NULL, 0, 1, 8000, NULL),
  ('RB', 'Basic rigging', 'Rigging', 'none', NULL, 0, 1, 8010, NULL),
  ('RI', 'Intermediate rigging', 'Rigging', 'none', NULL, 0, 1, 8020, NULL),
  ('RA', 'Advanced rigging', 'Rigging', 'none', NULL, 0, 1, 8030, NULL),
  ('SB', 'Basic scaffolding', 'Scaffolding', 'none', NULL, 0, 1, 9000, NULL),
  ('SI', 'Intermediate scaffolding', 'Scaffolding', 'none', NULL, 0, 1, 9010, NULL),
  ('SA', 'Advanced scaffolding', 'Scaffolding', 'none', NULL, 0, 1, 9020, NULL),
  ('CT', 'Tower crane', 'Cranes', 'none', NULL, 0, 1, 10000, NULL),
  ('CS', 'Self-erecting tower crane', 'Cranes', 'none', NULL, 0, 1, 10010, NULL),
  ('CD', 'Derrick crane', 'Cranes', 'none', NULL, 0, 1, 10020, NULL),
  ('CP', 'Portal boom crane', 'Cranes', 'none', NULL, 0, 1, 10030, NULL),
  ('CB', 'Bridge and gantry crane', 'Cranes', 'none', NULL, 0, 1, 10040, NULL),
  ('CV', 'Vehicle loading crane', 'Cranes', 'none', NULL, 0, 1, 10050, NULL),
  ('CN', 'Non-slewing mobile crane', 'Cranes', 'none', NULL, 0, 1, 10060, NULL),
  ('C2', 'Slewing mobile crane, up to 20t', 'Cranes', 'none', NULL, 0, 1, 10070, NULL),
  ('C6', 'Slewing mobile crane, up to 60t', 'Cranes', 'none', NULL, 0, 1, 10080, NULL),
  ('C1', 'Slewing mobile crane, up to 100t', 'Cranes', 'none', NULL, 0, 1, 10090, NULL),
  ('C0', 'Slewing mobile crane, over 100t', 'Cranes', 'none', NULL, 0, 1, 10100, NULL),
  ('HM', 'Materials hoist (cantilever platform)', 'Hoists', 'none', NULL, 0, 1, 11000, NULL),
  ('HP', 'Hoist (personnel and materials)', 'Hoists', 'none', NULL, 0, 1, 11010, NULL),
  ('BS', 'Standard boiler operation', 'Pressure equipment', 'none', NULL, 0, 1, 12000, NULL),
  ('BA', 'Advanced boiler operation', 'Pressure equipment', 'none', NULL, 0, 1, 12010, NULL),
  ('TO', 'Turbine operation', 'Pressure equipment', 'none', NULL, 0, 1, 12020, NULL),
  ('ES', 'Reciprocating steam engine operation', 'Pressure equipment', 'none', NULL, 0, 1, 12030, NULL),
  ('RS', 'Reach stacker', 'Other HRW', 'none', NULL, 0, 1, 13000, NULL),
  ('PB', 'Concrete-placing boom', 'Other HRW', 'none', NULL, 0, 1, 13010, NULL),
  ('TV', 'Non-slewing telehandler, over 3t', 'Other HRW', 'none', NULL, 0, 1, 13020, NULL)
;

-- 3. Verify. Expect EXACTLY 41 / 10 / 1 / 30. Any other shape and the seed is
--    wrong — stop, do not proceed to prod, do not deploy the read path.
SELECT COUNT(*) AS rows_total,
       SUM(`expiry_mode` = 'date')   AS mode_date,
       SUM(`expiry_mode` = 'period') AS mode_period,
       SUM(`expiry_mode` = 'none')   AS mode_none
FROM `licence_catalogue`;

-- 4. The invariants §2 enforces in PHP, checked once against the seed. Both
--    queries must return ZERO rows. MariaDB CHECK constraints are unevenly
--    enforced across the versions here, which is why they are assertions rather
--    than schema.
--      a) period requires a positive validity_months AND require_certified = 1
SELECT `code`, `expiry_mode`, `validity_months`, `require_certified`
FROM `licence_catalogue`
WHERE `expiry_mode` = 'period'
  AND (`validity_months` IS NULL OR `validity_months` <= 0 OR `require_certified` <> 1);
--      b) none/date must never carry a period
SELECT `code`, `expiry_mode`, `validity_months`
FROM `licence_catalogue`
WHERE `expiry_mode` IN ('none', 'date') AND `validity_months` IS NOT NULL;

-- 5. The set comparison that actually matters — every code in the table must
--    exist in app.py's LICENCE_TYPE_ALLOWLIST and vice versa. Paste this list
--    back and diff it against the constant rather than asserting they match.
SELECT GROUP_CONCAT(`code` ORDER BY `code` SEPARATOR ' ') AS codes_in_table
FROM `licence_catalogue`;

-- 6. Rows whose type_canonical points at a code the catalogue does not hold.
--    Expect zero. A non-empty result means a triaged licence row is orphaned and
--    the seed is missing a code.
SELECT DISTINCT l.`type_canonical`
FROM `user_licenses` l
LEFT JOIN `licence_catalogue` c ON c.`code` = l.`type_canonical`
WHERE l.`type_canonical` IS NOT NULL AND c.`id` IS NULL;
