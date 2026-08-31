-- ─────────────────────────────────────────────────────────────────────────
-- MIGRATION — Licence catalogue: the Miscellaneous group
--
-- Adds a FIFTEENTH group to `licence_catalogue`, holding the certifications
-- ops actually chase that sit outside the WorkSafe HRW schedule and the
-- VicRoads driver classes.
--
-- WHY THIS IS A MIGRATION AND NOT AN EDITOR ACTION. save-licence-catalogue.php
-- rejects a `grp` that is not already present in the table, deliberately: a new
-- group needs an ordering decision and sort_order is group-major, which the
-- row editor has no place to make. So the FIRST row of a new group is always a
-- migration. Every SUBSEQUENT Miscellaneous code can be added through the
-- Licence Catalogue editor in the app, with no SQL and no release.
--
-- ORDERING. LICENCE_GROUP_ORDER in app.py runs 14 groups, rank 0..13, and
-- sort_order is rank * 1000. Miscellaneous is rank 14 -> 14000, stepped by 10
-- within the group, exactly matching _build_licence_catalogue_fallback().
-- licence_group_order() derives the display order from sort_order (first
-- appearance wins), so the table and the constant cannot disagree.
--
-- EXPIRY. All three are 'date': the operator records what is printed on the
-- card, and a blank expiry reads as a real gap. No retroactive effect — these
-- are new codes, so `user_licenses` holds ZERO rows of these types today and
-- nobody's compliance pill changes on deploy. §4 asserts that before you run it.
--
-- Run on TEST (smartst_test) first, verify with §3–§5, then PROD.
-- ─────────────────────────────────────────────────────────────────────────

-- 1. Pre-flight. Expect 41 rows and 14 distinct groups — the state this
--    migration assumes. Any other shape means the seed drifted; stop here.
SELECT COUNT(*) AS rows_before, COUNT(DISTINCT `grp`) AS groups_before
FROM `licence_catalogue`;

-- 2. The three rows. INSERT IGNORE so a re-run is a no-op rather than a
--    duplicate-key abort on `uq_code`.
--
--    require_certified = 0 matches every other non-period type: no licence type
--    currently demands a certification date. The column DEFAULT is 1, so it is
--    stated explicitly here rather than left to the default.
INSERT IGNORE INTO `licence_catalogue`
  (`code`, `name`, `grp`, `expiry_mode`, `validity_months`,
   `require_certified`, `published`, `sort_order`, `notes`)
VALUES
  ('POLICE',   'Police Check',                   'Miscellaneous', 'date', NULL, 0, 1, 14000, NULL),
  ('ISO45001', 'ISO 45001 Auditor',              'Miscellaneous', 'date', NULL, 0, 1, 14010, NULL),
  ('RSA',      'Responsible Service of Alcohol', 'Miscellaneous', 'date', NULL, 0, 1, 14020, NULL)
;

-- 3. Verify. Expect EXACTLY 44 rows and 15 groups.
SELECT COUNT(*) AS rows_after, COUNT(DISTINCT `grp`) AS groups_after
FROM `licence_catalogue`;

-- 4. The new group, in order. Expect the three rows at 14000 / 14010 / 14020,
--    every one 'date', validity_months NULL, require_certified 0, published 1.
SELECT `code`, `name`, `grp`, `expiry_mode`, `validity_months`,
       `require_certified`, `published`, `sort_order`
FROM `licence_catalogue`
WHERE `grp` = 'Miscellaneous'
ORDER BY `sort_order` ASC;

-- 5. No existing licence row points at a new code. Expect ZERO. A non-empty
--    result means somebody was already triaged onto one of these strings and
--    the "no retroactive compliance change" claim above does not hold — read
--    those rows before deploying the app.
SELECT `type_canonical`, COUNT(*) AS n
FROM `user_licenses`
WHERE `type_canonical` IN ('POLICE', 'ISO45001', 'RSA')
GROUP BY `type_canonical`;

-- 6. Group order as the app will render it. Expect Miscellaneous LAST, after
--    Other HRW, matching LICENCE_GROUP_ORDER in app.py.
SELECT `grp`, MIN(`sort_order`) AS grp_rank
FROM `licence_catalogue`
GROUP BY `grp`
ORDER BY grp_rank ASC;

-- 7. Invariants, re-checked. Both must return ZERO rows.
--      a) period requires a positive validity_months AND require_certified = 1
SELECT `code`, `expiry_mode`, `validity_months`, `require_certified`
FROM `licence_catalogue`
WHERE `expiry_mode` = 'period'
  AND (`validity_months` IS NULL OR `validity_months` <= 0 OR `require_certified` <> 1);
--      b) none/date must never carry a period
SELECT `code`, `expiry_mode`, `validity_months`
FROM `licence_catalogue`
WHERE `expiry_mode` IN ('none', 'date') AND `validity_months` IS NOT NULL;

-- ─────────────────────────────────────────────────────────────────────────
-- ROLLBACK. Safe ONLY while §5 still returns zero rows — deleting a code that
-- a user_licenses row points at orphans that row.
--
--   DELETE FROM `licence_catalogue` WHERE `code` IN ('POLICE','ISO45001','RSA');
--
-- Once anyone holds one of these, unpublish instead of deleting:
--
--   UPDATE `licence_catalogue` SET `published` = 0
--    WHERE `code` IN ('POLICE','ISO45001','RSA');
--
-- Note what unpublishing does: licence_expiry_expected() derives from PUBLISHED
-- rows only, so every undated holder flips from 'unknown' to 'na' and quietly
-- stops being chased. That is the same warning save-licence-catalogue.php's
-- preview branch exists to surface.
-- ─────────────────────────────────────────────────────────────────────────
