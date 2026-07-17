-- ─────────────────────────────────────────────────────────────────────────
-- THE GOAT — visa worker + crew documents migration
-- Represents a working-visa crew member in SmartStaff, and gives crew a place
-- to hold their signed employment contract. Feeds the convert-to-crew push
-- (admin-set-visa.php / admin-add-contract.php).
-- ─────────────────────────────────────────────────────────────────────────
--
-- SmartStaff's `users` table has NO visa representation today (confirmed against
-- smartst_test). Rather than widen the legacy `users` table, immigration PII goes
-- in a dedicated 1:1 `user_visa` table, and the signed contract goes in a small
-- per-user `user_documents` table (same arrangement as user_licenses: a `user`
-- FK + a pdf_file in user_uploads/). The ONLY change to the legacy `users` table
-- is one additive, nullable quick-flag column so the rostering / compliance side
-- can spot a visa worker without a join.
--
-- House rules: additive + nullable so nothing existing breaks; backtick `user`
-- (reserved); DATE columns are NULL DEFAULT NULL (never 0000-00-00); MyISAM to
-- match the existing tables. Safe to run before or after the endpoints deploy —
-- the endpoints tolerate an empty table, and nothing reads these columns until
-- convert writes them.
--
-- DEPLOY: run on smartst_test first, verify, THEN prod in a quiet window
-- (same discipline as the licence date columns / MIGRATION-cohort.sql).

-- ─────────────────────────────────────────────────────────────────────────
-- 1. user_visa — one row per working-visa crew member (1:1 with users.id).
--    Mirrors what onboarding captures in Supabase work_eligibility +
--    visa_extraction + the recorded VEVO check (vevo_check). UNIQUE(`user`)
--    makes the convert upsert idempotent — a re-convert updates the one row.
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `user_visa` (
    `id`                      INT(11)      NOT NULL AUTO_INCREMENT,
    `user`                    INT(11)      NOT NULL,
    `work_eligibility_status` VARCHAR(32)  NULL DEFAULT NULL,  -- 'citizen_pr' | 'working_visa'
    `is_visa_worker`          TINYINT(1)   NOT NULL DEFAULT 0, -- quick 0/1 flag
    `passport_number`         VARCHAR(64)  NULL DEFAULT NULL,
    `passport_country`        VARCHAR(128) NULL DEFAULT NULL,
    `visa_subclass`           VARCHAR(32)  NULL DEFAULT NULL,  -- e.g. '482','500','485'
    `visa_grant_number`       VARCHAR(64)  NULL DEFAULT NULL,
    `trn`                     VARCHAR(64)  NULL DEFAULT NULL,
    `visa_grant_date`         DATE         NULL DEFAULT NULL,
    `visa_expiry`             DATE         NULL DEFAULT NULL,  -- date work rights end
    `visa_conditions`         TEXT         NULL,
    `has_work_limitation`     TINYINT(1)   NULL DEFAULT NULL,  -- 1 restricted / 0 not / NULL unclear
    `vevo_verified_at`        DATETIME     NULL DEFAULT NULL,  -- recorded VEVO check, carried across
    `vevo_verified_by`        VARCHAR(255) NULL DEFAULT NULL,
    `visa_pdf`                VARCHAR(255) NULL DEFAULT NULL,  -- filename in user_uploads/
    `updated_ts`              INT(10)      NULL DEFAULT NULL,  -- unix time of last write
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user` (`user`)
) ENGINE=MyISAM;

-- ─────────────────────────────────────────────────────────────────────────
-- 2. user_documents — per-user documents that aren't licences/inductions.
--    First use: the signed employment contract (doc_type='contract').
--    UNIQUE(`user`, doc_type) makes the contract push idempotent per crew member.
--    (The company-wide `agreements` table is a DIFFERENT thing — shared EA PDFs
--    everyone sees; this is the individual's own signed document.)
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `user_documents` (
    `id`         INT(11)      NOT NULL AUTO_INCREMENT,
    `user`       INT(11)      NOT NULL,
    `doc_type`   VARCHAR(32)  NOT NULL,               -- 'contract'
    `pdf_file`   VARCHAR(255) NULL DEFAULT NULL,       -- filename in user_uploads/
    `signed_at`  DATETIME     NULL DEFAULT NULL,       -- when they e-signed
    `version`    VARCHAR(64)  NULL DEFAULT NULL,       -- contract_version
    `created_ts` INT(10)      NULL DEFAULT NULL,        -- unix time of the push
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user_doctype` (`user`, `doc_type`)
) ENGINE=MyISAM;

-- ─────────────────────────────────────────────────────────────────────────
-- 3. users.is_visa_worker — one additive, nullable quick-flag on the legacy
--    table so the rostering / compliance side can spot a visa worker without a
--    join. NULL default => every existing crew member resolves to "not flagged";
--    convert sets it to 1 for working-visa crew (via admin-set-visa.php).
-- ─────────────────────────────────────────────────────────────────────────
ALTER TABLE `users`
    ADD COLUMN `is_visa_worker` TINYINT(1) NULL DEFAULT NULL;
