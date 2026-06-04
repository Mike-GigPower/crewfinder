-- ─────────────────────────────────────────────────────────────────────────
-- THE GOAT — cohort migration (Phase 1)
-- Adds a per-user `cohort` attribute to SmartStaff's users table.
-- ─────────────────────────────────────────────────────────────────────────
--
-- Cohort model (resolved in whoami.php):
--   • A usergroupID == 1 login  -> always 'admin'  (NOT read from this column)
--   • A personal EIN login      -> this column, restricted to 'leadership'
--                                  or 'crew'; anything else / NULL -> 'crew'
--
-- So this column only ever grants LEADERSHIP. Admin stays tied to the
-- usergroupID == 1 login, and the column cannot escalate a crew-group
-- account to full admin access.
--
-- Safe to run before or after deploying whoami.php — the endpoint tolerates
-- the column being absent (defaults everyone non-admin to 'crew').

-- 1. Add the column. NULL default => existing crew resolve to 'crew'.
ALTER TABLE users
    ADD COLUMN cohort VARCHAR(16) NULL DEFAULT NULL;

-- 2. Seed Leadership for the internal team's PERSONAL (EIN) logins.
--    Replace the EINs below with Joe / Rich / Monty's actual employee numbers.
--    (Leave anyone who should remain plain crew untouched.)
--
-- UPDATE users SET cohort = 'leadership'
--   WHERE ein IN ('XXXX', 'YYYY', 'ZZZZ');

-- 3. (Optional) verify
-- SELECT id, ein, firstname, lastname, usergroupID, cohort
--   FROM users
--  WHERE cohort IS NOT NULL;
