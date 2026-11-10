-- MIGRATION-crew-dietary.sql
-- Session 1 slice A of BRIEF-dietary-requirements.md
-- (design DESIGN-dietary-requirements-v0_3.md).
--
-- RUN ON smartst_test FIRST. Verify with section 2. Only then smartst_smartstaff.
--
-- Server is MariaDB 11.4.13. InnoDB + utf8mb4_unicode_ci DELIBERATELY, against
-- the MyISAM / latin1_swedish_ci house default -- the same choice and the same
-- collation as MIGRATION-crew-emergency-card.sql. InnoDB is what makes the
-- CHECK constraints below enforced rather than decorative.
--
-- There is NO foreign key to `users`. `users` is MyISAM and cross-engine
-- foreign keys do not exist. Referential integrity to `users` is the
-- application's job, as it already is everywhere else in this schema.
--
-- Run ONE STATEMENT AT A TIME in phpMyAdmin. A multi-statement batch aborts at
-- the first failure and leaves you unsure how far it got.


-- ---------------------------------------------------------------------------
-- 1. crew_dietary -- one row per crew member
-- ---------------------------------------------------------------------------
-- `state` is three-valued on purpose (D1). "No dietary requirements" is a
-- button somebody pressed, not an empty form, and it must never be confused
-- with "never asked". NO ROW means not_answered; every endpoint treats a
-- missing row exactly as that state.
--
-- `user_id` is a SmartStaff users.id. NEVER an EIN. users.id is signed
-- int(11); INT UNSIGNED matches the emergency tables.
--
-- `options` is the ticked codes, comma-joined in goat_dietary_options() order
-- (dietary-lib.php is the vocabulary). Never stored in users.notes (D3).
--
-- `consent_at` NULL = entered by the office and not yet confirmed by the crew
-- member (D11). An ops edit keeps an existing value (Q8).
--
-- `updated_source`: crewhub = the crew member, apply = carried across from
-- the application form at convert, goat = the Gig Power office.

CREATE TABLE IF NOT EXISTS crew_dietary (
  user_id        INT UNSIGNED NOT NULL,
  state          ENUM('not_answered','none','declared') NOT NULL DEFAULT 'not_answered',
  options        VARCHAR(200) NULL,
  note           VARCHAR(300) NULL,
  consent_at     DATETIME NULL,
  updated_at     DATETIME NOT NULL,
  updated_by     INT UNSIGNED NOT NULL,
  updated_source VARCHAR(10) NOT NULL,
  PRIMARY KEY (user_id),
  CONSTRAINT chk_cd_declared CHECK (state <> 'declared' OR (options IS NOT NULL AND options <> '')),
  CONSTRAINT chk_cd_source   CHECK (updated_source IN ('crewhub','apply','goat'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- 2. VERIFICATION -- smartst_test only
-- ---------------------------------------------------------------------------
-- Schema hardcoded rather than DATABASE(): phpMyAdmin has a known
-- schema-resolution quirk from an information_schema context on this box.
-- Swap to 'smartst_smartstaff' when verifying prod.

-- 2a. Engine, collation and both CHECKs actually landed
SHOW CREATE TABLE crew_dietary;
-- EXPECT: ENGINE=InnoDB, CHARSET=utf8mb4, COLLATE=utf8mb4_unicode_ci,
--         CONSTRAINT `chk_cd_declared` and CONSTRAINT `chk_cd_source`.

SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'smartst_test'
  AND TABLE_NAME = 'crew_dietary';
-- EXPECT: one row, InnoDB, utf8mb4_unicode_ci.

-- 2b. declared with no options is refused. MUST FAIL.
INSERT INTO crew_dietary
  (user_id, state, options, note, consent_at, updated_at, updated_by, updated_source)
VALUES (999999, 'declared', NULL, NULL, NULL, NOW(), 999999, 'goat');
-- EXPECT: error 4025 -- CONSTRAINT `chk_cd_declared` failed.

-- 2c. Unknown source is refused. MUST FAIL.
INSERT INTO crew_dietary
  (user_id, state, options, note, consent_at, updated_at, updated_by, updated_source)
VALUES (999999, 'none', NULL, NULL, NULL, NOW(), 999999, 'phone');
-- EXPECT: error 4025 -- CONSTRAINT `chk_cd_source` failed.

-- 2d. A coherent row inserts fine, and 4-byte UTF-8 survives in the note.
INSERT INTO crew_dietary
  (user_id, state, options, note, consent_at, updated_at, updated_by, updated_source)
VALUES (999999, 'declared', 'vegetarian,allergy', 'Peanuts -- 4 byte check 🥜',
        NULL, NOW(), 999999, 'goat');

SELECT user_id, state, options, note FROM crew_dietary WHERE user_id = 999999;
-- EXPECT: the row above with the peanut emoji intact.

-- 2e. Clean up.
DELETE FROM crew_dietary WHERE user_id = 999999;


-- ---------------------------------------------------------------------------
-- 3. PROD
-- ---------------------------------------------------------------------------
-- Run section 1 only, then 2a for verification BY INSPECTION. 2b-2e are
-- already proved on test; there is no reason to write fixtures into prod.
