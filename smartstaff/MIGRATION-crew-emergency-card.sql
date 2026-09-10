-- MIGRATION-crew-emergency-card.sql
-- Phase 1 slice A of DESIGN-crew-emergency-card-v0_1.md
--
-- RUN ON smartst_test FIRST. Verify with section 4. Only then smartst_smartstaff.
--
-- Server is MariaDB 11.4.13. These tables are InnoDB + utf8mb4 DELIBERATELY,
-- against the MyISAM / latin1_swedish_ci house default -- see design D13.
-- InnoDB buys CHECK constraints, real FKs between the two card tables, and
-- append-only enforced by trigger rather than by convention (D14).
--
-- There is NO foreign key to `users`. `users` is MyISAM and cross-engine
-- foreign keys do not exist. Referential integrity to `users` is the
-- application's job, as it already is everywhere else in this schema.
--
-- Run ONE STATEMENT AT A TIME in phpMyAdmin. A multi-statement batch aborts at
-- the first failure and leaves you unsure how far it got -- the lesson from
-- BRIEF-call-cancellation-phase1.md.


-- ---------------------------------------------------------------------------
-- 1. crew_emergency_info -- one row per crew member
-- ---------------------------------------------------------------------------
-- `state` is three-valued on purpose (D3). The failure that hurts somebody is
-- a reader seeing nothing and assuming "clear" when the person was simply
-- never asked. call_crew_map.is_call_boss is the live cautionary example:
-- 241,893 of its rows are 0x00 padding, byte-distinct from '0' and invisible
-- to a careless query.
--
-- `user_id` is a SmartStaff users.id. NEVER an EIN.

CREATE TABLE IF NOT EXISTS crew_emergency_info (
  user_id     INT UNSIGNED NOT NULL,
  state       ENUM('not_answered','nothing_to_declare','declared')
              NOT NULL DEFAULT 'not_answered',
  consent_at  DATETIME NULL DEFAULT NULL,
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL,
  updated_by  INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id),
  CONSTRAINT chk_cei_consent CHECK (
    (state =  'not_answered' AND consent_at IS NULL)
    OR
    (state <> 'not_answered' AND consent_at IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- 2. crew_emergency_entry -- 0..n per crew member
-- ---------------------------------------------------------------------------
-- One row per declared thing. Somebody may carry an auto-injector AND have
-- asthma; a single-value card would make them choose, which is not acceptable
-- in a safety feature.
--
-- `action_note` is what a BYSTANDER should do (D2) -- "carries an adrenaline
-- auto-injector, left boot pocket" -- not a diagnosis. Better first aid, and
-- it discloses less.
--
-- ON DELETE CASCADE means withdrawing the card is one DELETE on the parent.

CREATE TABLE IF NOT EXISTS crew_emergency_entry (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id             INT UNSIGNED NOT NULL,
  category            VARCHAR(32)  NOT NULL,
  action_note         VARCHAR(500) NOT NULL,
  medication_location VARCHAR(200) NULL DEFAULT NULL,
  sort_order          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at          DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_cee_user (user_id, sort_order),
  CONSTRAINT fk_cee_user FOREIGN KEY (user_id)
    REFERENCES crew_emergency_info (user_id) ON DELETE CASCADE,
  CONSTRAINT chk_cee_category CHECK (
    category IN ('auto_injector','seizures','diabetes','asthma','cardiac','other')
  ),
  CONSTRAINT chk_cee_action_note CHECK (action_note <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- 3. crew_emergency_access_log -- append-only
-- ---------------------------------------------------------------------------
-- The estate's first access log. SmartStaff has never recorded a read:
-- `lastlogin` and `loginAttempts` exist on `users` and are never written.
--
-- DELIBERATELY NO FOREIGN KEY on subject_user_id. The log must SURVIVE the
-- card being deleted -- a cascade here would destroy the audit trail at
-- exactly the moment somebody withdrew, which is backwards.
--
-- viewer_username, NOT EIN: admin accounts return ein "0", a shared
-- placeholder, so EIN is not an identity here.
--
-- grant_reason is the column that makes this an audit trail rather than a
-- list. It records WHY access was allowed, so a later reader can check the
-- rule was applied correctly -- not merely that somebody looked.

CREATE TABLE IF NOT EXISTS crew_emergency_access_log (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subject_user_id INT UNSIGNED NOT NULL,
  viewer_user_id  INT UNSIGNED NOT NULL,
  viewer_username VARCHAR(100) NOT NULL,
  viewer_cohort   VARCHAR(20)  NOT NULL,
  surface         VARCHAR(20)  NOT NULL,
  grant_reason    VARCHAR(40)  NOT NULL,
  call_id         INT UNSIGNED NULL DEFAULT NULL,
  accessed_at     DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ceal_subject (subject_user_id, accessed_at),
  KEY idx_ceal_viewer  (viewer_user_id,  accessed_at),
  CONSTRAINT chk_ceal_surface CHECK (surface IN ('goat','crewhub')),
  CONSTRAINT chk_ceal_reason  CHECK (
    grant_reason IN ('self','cohort_admin','cohort_operations',
                     'in_call_boss','supervision')
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- 3b. Append-only triggers
-- ---------------------------------------------------------------------------
-- Single-statement bodies with no BEGIN/END, so NO DELIMITER change is needed
-- and these paste straight into phpMyAdmin's SQL tab.
--
-- Honest limit: this does not stop anyone who can DROP the trigger. It stops
-- application bugs and casual edits, which "append-only by discipline" never
-- did.

CREATE TRIGGER trg_ceal_no_update BEFORE UPDATE ON crew_emergency_access_log
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'crew_emergency_access_log is append-only';

CREATE TRIGGER trg_ceal_no_delete BEFORE DELETE ON crew_emergency_access_log
FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'crew_emergency_access_log is append-only';


-- ---------------------------------------------------------------------------
-- 4. VERIFICATION -- smartst_test only
-- ---------------------------------------------------------------------------
-- Schema hardcoded rather than DATABASE(): phpMyAdmin has a known
-- schema-resolution quirk from an information_schema context on this box.
-- Swap to 'smartst_smartstaff' when verifying prod.

-- 4a. Engine and collation actually landed as asked
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'smartst_test'
  AND TABLE_NAME LIKE 'crew\_emergency%'
ORDER BY TABLE_NAME;
-- EXPECT: three rows, all InnoDB, all utf8mb4_unicode_ci.

-- 4b. Both triggers exist
SELECT TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = 'smartst_test'
  AND EVENT_OBJECT_TABLE = 'crew_emergency_access_log';
-- EXPECT: two rows -- UPDATE/BEFORE and DELETE/BEFORE.

-- 4c. CHECK constraint refuses an incoherent state. MUST FAIL.
INSERT INTO crew_emergency_info
  (user_id, state, consent_at, created_at, updated_at, updated_by)
VALUES (999999, 'declared', NULL, NOW(), NOW(), 999999);
-- EXPECT: error 4025 -- CONSTRAINT `chk_cei_consent` failed.

-- 4d. A coherent row inserts fine.
INSERT INTO crew_emergency_info
  (user_id, state, consent_at, created_at, updated_at, updated_by)
VALUES (999999, 'declared', NOW(), NOW(), NOW(), 999999);

-- 4e. FK + CASCADE work, and 4-byte UTF-8 survives.
INSERT INTO crew_emergency_entry
  (user_id, category, action_note, medication_location, sort_order, created_at)
VALUES (999999, 'auto_injector',
        'Carries an adrenaline auto-injector. Cafe latte test -- 4 byte check',
        'left boot pocket', 0, NOW());

-- 4f. FK refuses an orphan. MUST FAIL.
INSERT INTO crew_emergency_entry
  (user_id, category, action_note, created_at)
VALUES (888888, 'asthma', 'orphan row, should be refused', NOW());
-- EXPECT: error 1452 -- foreign key constraint fails.

-- 4g. Bad category refused. MUST FAIL.
INSERT INTO crew_emergency_entry
  (user_id, category, action_note, created_at)
VALUES (999999, 'not_a_real_category', 'should be refused', NOW());
-- EXPECT: error 4025 -- CONSTRAINT `chk_cee_category` failed.

-- 4h. THE GATE. Log accepts an insert...
INSERT INTO crew_emergency_access_log
  (subject_user_id, viewer_user_id, viewer_username, viewer_cohort,
   surface, grant_reason, accessed_at)
VALUES (999999, 9734, 'migration-test', 'admin', 'goat', 'self', NOW());

-- ...and then refuses to be changed. BOTH MUST FAIL with error 1644.
UPDATE crew_emergency_access_log
   SET viewer_cohort = 'operations'
 WHERE viewer_username = 'migration-test';
-- EXPECT: 1644 -- crew_emergency_access_log is append-only.

DELETE FROM crew_emergency_access_log WHERE viewer_username = 'migration-test';
-- EXPECT: 1644 -- crew_emergency_access_log is append-only.

-- 4i. Clean up the card fixtures. The CASCADE should take the entry with it.
DELETE FROM crew_emergency_info WHERE user_id = 999999;

SELECT (SELECT COUNT(*) FROM crew_emergency_info  WHERE user_id = 999999) AS info_left,
       (SELECT COUNT(*) FROM crew_emergency_entry WHERE user_id = 999999) AS entry_left;
-- EXPECT: 0 and 0. Entry gone via CASCADE, not by hand.

-- The 'migration-test' LOG row cannot be deleted and stays on smartst_test
-- forever. That is not a mess to tidy -- it is the standing proof that the
-- trigger works.


-- ---------------------------------------------------------------------------
-- 5. PROD
-- ---------------------------------------------------------------------------
-- Run sections 1, 2, 3 and 3b only. Then 4a and 4b for verification BY
-- INSPECTION -- do not run 4c-4i on prod. The same reasoning as the 9 -> 0
-- write test in BRIEF-call-cancellation-phase1.md: the behaviour is already
-- proved on test, and running it on prod would leave an undeletable fixture
-- row in a live audit log.
