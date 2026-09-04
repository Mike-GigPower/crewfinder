-- ─────────────────────────────────────────────────────────────────────────
-- THE GOAT 5.31.0 — store the VEVO Visa Details Check alongside the visa
-- ─────────────────────────────────────────────────────────────────────────
--
-- One additive, nullable column mirroring `visa_pdf` exactly: the filename of
-- the uploaded VEVO check in user_uploads/.
--
-- WHY HERE AND NOT IN user_documents
-- The VEVO check is part of the visa record, not a separate document about the
-- person. `vevo_verified_at` and `vevo_verified_by` already live in user_visa,
-- and the stamp and its evidence belong in the same row — a stamp whose
-- supporting document sits in another table is a join away from being a stamp
-- nobody can substantiate. user_documents would also have meant reusing
-- `signed_at` for something nobody signed.
--
-- House rules, same as MIGRATION-user-visa.sql: additive + nullable so nothing
-- existing breaks, backtick the reserved `user` where referenced, MyISAM to
-- match. Safe to run before the endpoints deploy — nothing reads the column
-- until admin-set-visa.php writes it.
--
-- DEPLOY: smartst_test first, verify, THEN prod, and BOTH before the app build.
-- An app that posts vevo_pdf to an endpoint whose table has no column for it
-- writes nothing and says nothing.

ALTER TABLE `user_visa`
    ADD COLUMN `vevo_pdf` VARCHAR(255) NULL DEFAULT NULL AFTER `visa_pdf`;

-- Verify (expect exactly one row, Null = YES, Default = NULL):
--   SHOW COLUMNS FROM `user_visa` LIKE 'vevo_pdf';
--
-- Existing rows are unaffected: every one of the 23 visa records on prod keeps
-- NULL here, which the UI renders as "no VEVO check on file" — the true state.
