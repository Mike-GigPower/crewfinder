-- ─────────────────────────────────────────────────────────────────────────
-- MIGRATION — A backup takes themselves off standby
-- (BRIEF-backup-standby-slice2 A1 · DESIGN-backup-leave-standby-v0_2 §4 · S1, S2)
--
-- TWO CHANGES, in this order:
--
--   1. call_standby_leave — a new table recording WHEN a crew member took
--      themselves off standby, and whether that call was the one they tapped
--      or one D2 cascaded to.
--   2. ccm_responded_at_upd — arm 2 of the existing BEFORE UPDATE trigger
--      learns status 13, so a re-offer (13 -> 0) restarts the offer clock the
--      same way 6 -> 0 and 10 -> 0 do.
--
-- WHY A NEW TABLE (S1). call_crew_map has nowhere to put the time: it carries
-- one row per crew member per call and no event history. offer_history was
-- considered and rejected — THE GOAT's "2nd offer" badge counts its rows per
-- user and call (get-booking.php's ohx join), so a withdrawal written there
-- would make a crew member look like they had been re-offered.
--
-- WHY src MATTERS. 'crew' is the call they chose; 'cascade' is an upstream
-- standby D2 dropped with it; 'change' is a backup answering "No longer
-- available" to a timing change (S5). Only 'crew' is a decision about that
-- particular call, and anything that later reads this table for behaviour
-- rather than display needs to be able to tell them apart.
--
-- MORE THAN ONE ROW PER USER AND CALL IS NORMAL, so there is no unique key:
-- a row can leave standby, be re-offered (D6), accept back onto standby and
-- leave again. Every reader takes MAX(left_at).
--
-- ENGINE AND COLLATION match offer_history, read on test and prod 19 Nov:
-- MyISAM, charset latin1, collation latin1_swedish_ci. NOT the utf8 that
-- MIGRATION-callout.sql uses — a mismatched collation on `src` would make any
-- future JOIN against another latin1 table collate differently.
--
-- Run on TEST (smartst_test) first, verify with section 3, then PROD
-- (smartst_smartstaff). Nothing reads either change until the slice 2 PHP
-- ships, and every PHP reader of the table is behind a capability check
-- (goat_have_standby_leave()), so step 1 on its own is inert and reversible.
-- ─────────────────────────────────────────────────────────────────────────


-- ---------------------------------------------------------------------------
-- 1. THE TABLE
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `call_standby_leave` (
  `id`      INT         NOT NULL AUTO_INCREMENT,
  `callID`  INT         NOT NULL,
  `userID`  INT         NOT NULL,
  `left_at` INT         NOT NULL,              -- unix ts, as call_change_ack.changed_at
  `src`     VARCHAR(8)  NOT NULL,              -- 'crew' | 'cascade' | 'change'
  PRIMARY KEY (`id`),
  KEY `idx_call_user` (`callID`, `userID`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- idx_call_user is (callID, userID) in that order on purpose: every reader
-- asks "who left standby on THIS call" (get-booking.php and
-- get-call-responses.php both join per call, grouped by userID), never "every
-- call this user left". VARCHAR(8) fits 'cascade' with one byte spare; it is
-- deliberately not an ENUM, matching call_callout_member.outcome.


-- ---------------------------------------------------------------------------
-- 2. THE TRIGGER — arm 2 learns 13 (S2)
-- ---------------------------------------------------------------------------
--
-- ⚠️  THE BODY IS NOT IN THIS FILE, AND MUST NOT BE TYPED INTO IT.
--
-- The live body is 391 bytes, md5 8e17ddf893a0ee5c5f1f477f11bbca70, confirmed
-- byte-identical on test and prod on 19 Nov. The repo records that hash and a
-- PRETTY-PRINTED quote of the body (Claude outputs/DESIGN-ops-red-zone-v0_1.md
-- lines 90-98, as read 31 Aug, before 10/11/12 joined arm 2), but the two do
-- not agree: re-hashing that quote gives 396 bytes, not 382, so its whitespace
-- is not what MariaDB stores. The 382 -> 391 delta of exactly +9 — the length
-- of ',10,11,12' — confirms the 10 Nov edit touched nothing but arm 2's list,
-- and so confirms arm 2 reads literally `IN (5,6,7,8,9,10,11,12)`. It does not
-- recover the surrounding bytes.
--
-- SO NOTHING IS COPIED BY HAND. 2c and 2d GENERATE the deployable file and the
-- rollback file from the live bytes with one REPLACE(); 2e verifies the file
-- before it is run and 2g verifies the trigger after. The body in 2b is for
-- READING — to see what the change means — never for pasting.

-- 2a. ONE EDIT PER ENVIRONMENT.
--     Change this single line on prod and everything in sections 2 and 3 that
--     reads information_schema follows it, so 2g can never check a different
--     database from the one 2f wrote to.
--
--     @db is a SESSION variable: paste sections 2 and 3 into ONE mysql session.
--     Open a new client and you must set it again. The shell commands in
--     2c/2d/2f cannot see it at all, which is why each is given twice, in full,
--     already filled in — there is nothing to edit in those.
--
--     THE SESSION TO RUN SECTIONS 1-3 IN:
--
--         mysql -u smartst_testu -p smartst_test
--
--     and on prod, for sections 1 and 2 ONLY — never section 3:
--
--         mysql -u smartst_smartst -p smartst_smartstaff
--
--     Always -u and -p. A bare `mysql` connects as 'smartst' with no password
--     and is refused before it reads a single statement.

SET @db := 'smartst_test';        -- change to 'smartst_smartstaff' on prod

-- 2b. Confirm the live trigger is what we think, BEFORE touching it.
SELECT LENGTH(ACTION_STATEMENT)      AS len,
       MD5(ACTION_STATEMENT)         AS md5,
       DEFINER                       AS definer,
       ACTION_TIMING, EVENT_MANIPULATION, EVENT_OBJECT_TABLE
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = @db
  AND TRIGGER_NAME   = 'ccm_responded_at_upd';
-- EXPECT  len 391
--         md5 8e17ddf893a0ee5c5f1f477f11bbca70
--         definer `smartst_testu`@`localhost`   (prod: `smartst_smartst`@`localhost`)
--         BEFORE / UPDATE / call_crew_map
--
-- ANY DIFFERENCE HERE AND STOP. A different hash means the body changed after
-- 10 Nov and the rest of this migration was written against a stale record.

-- 2b-i. The new body's length and hash, computed FROM THE LIVE BYTES.
SELECT LENGTH(nb) AS new_len,
       MD5(nb)    AS new_md5
FROM (
  SELECT REPLACE(ACTION_STATEMENT,
                 'IN (5,6,7,8,9,10,11,12) AND NEW.status = 0',
                 'IN (5,6,7,8,9,10,11,12,13) AND NEW.status = 0') AS nb
  FROM information_schema.TRIGGERS
  WHERE TRIGGER_SCHEMA = @db
    AND TRIGGER_NAME   = 'ccm_responded_at_upd'
) x;
-- new_len can only come back 391, 394 or 397, and that makes it the whole
-- check:
--   391  the REPLACE matched NOTHING. Arm 2 is not spelled the way this
--        migration assumes. STOP. Run 2d — its file carries the unmodified
--        ACTION_STATEMENT, so it is also how you dump the live body — and
--        send me that file. Do not run 2c or 2f.
--   394  matched exactly once. Correct: carry on.
--   397  matched twice, so that pattern appears somewhere else in the body.
--        Stop; replacing both would widen an arm that should not move.
--
-- WRITE DOWN new_md5. 2e compares the generated file against it and 2g
-- compares the deployed trigger against it. It cannot be known before this
-- query runs — the live bytes are its only source, which is why this file
-- does not state it.

-- 2b-ii. FOR READING ONLY — what the change means. Arm 2 gains ',13' and
--        nothing else moves.
--
--     BEGIN
--       IF     OLD.status = 0 AND NEW.responded_src = 'phone'
--         THEN SET NEW.offered_at = NULL, NEW.responded_at = NULL;
--       ELSEIF OLD.status IN (5,6,7,8,9,10,11,12,13) AND NEW.status = 0
--     --                                         ^^^ the only edit
--         THEN SET NEW.offered_at = NOW(), NEW.responded_at = NULL, NEW.responded_src = NULL;
--       ELSEIF OLD.status = 0 AND NEW.status IN (5,6,7) AND NEW.responded_at IS NULL
--         THEN SET NEW.responded_at = NOW();
--       END IF;
--     END
--
--     Arm 1 (phone rows) and arm 3 (the first answer to an offer) are NOT
--     touched. Arm 3 needing OLD.status = 0 is why 7 -> 13 stamps nothing,
--     which section 3 proves rather than assumes.
--
--     The indentation above is the 31 Aug design doc's, which we know is NOT
--     the stored whitespace. Read it for the logic, never for the bytes.

-- ───────────────────────────────────────────────────────────────────────────
-- 2c / 2d / 2f run in the cPanel TERMINAL, not in mysql. Run them from the
-- home directory. phpMyAdmin cannot do this part: it mangles DELIMITER.
--
-- EVERY HEREDOC BELOW IS <<'SQL' — QUOTED, AND THAT IS LOAD-BEARING.
-- With an unquoted heredoc, or with -e "...", the shell expands $$ to its own
-- PID, so `DELIMITER $$` would be written out as `DELIMITER 7208` and the
-- generated file would be unusable. The quoted delimiter turns off every
-- shell expansion inside the block.
--
--   -u  the definer user. REQUIRED. A bare `mysql` in the cPanel Terminal
--       connects as 'smartst' with no password and is refused outright:
--       "Access denied for user 'smartst'@'localhost' (using password: NO)".
--   -p  prompt for that user's password. Given on its own, NOT as -pSECRET:
--       an attached password ends up in the shell history and in `ps`.
--       `-p` followed by a separate flag still prompts — mysql only takes an
--       ATTACHED value — so `-p -N -r` is correct and unambiguous.
--   -N  no column-header row
--   -r  raw: do NOT turn the body's newlines into the two characters \n
--   -D  the database to read (the one @db names; mysql has no @db here)
--
-- TEST AND PROD WRITE TO DIFFERENT FILENAMES, AND THAT IS NOT TIDINESS:
--
--   test -> ~/trg-new.sql       ~/trg-new-body.txt       ~/trg-rollback.sql
--   prod -> ~/trg-prod-new.sql  ~/trg-prod-new-body.txt  ~/trg-prod-rollback.sql
--
-- Each generated file hard-codes ITS OWN environment's DEFINER. Reuse one name
-- for both and two things go wrong, both silent:
--
--   1. Generating prod's file OVERWRITES test's, destroying the test rollback
--      — and 2d is the only copy of the 391-byte original.
--   2. Worse, deploying a leftover test file to prod would write
--      DEFINER=`smartst_testu`@`localhost` onto the PROD trigger: a trigger
--      running as a user that does not exist there. Nothing would complain
--      until the next UPDATE to call_crew_map failed.
--
-- So 2e and 2f name the files per environment too. Check the filename in the
-- prompt before every redirect.
-- ───────────────────────────────────────────────────────────────────────────

-- 2c. GENERATE THE DEPLOYABLE FILE. Also writes the body on its own, so 2e
--     can hash exactly the bytes that are about to be deployed.
--
--     ---- TEST ----
-- mysql -u smartst_testu -p -N -r -D smartst_test <<'SQL' > ~/trg-new.sql
-- SELECT CONCAT(
--   'DELIMITER $$\n',
--   'CREATE OR REPLACE DEFINER=`smartst_testu`@`localhost` TRIGGER ccm_responded_at_upd\n',
--   '  BEFORE UPDATE ON call_crew_map FOR EACH ROW\n',
--   REPLACE(ACTION_STATEMENT,
--           'IN (5,6,7,8,9,10,11,12) AND NEW.status = 0',
--           'IN (5,6,7,8,9,10,11,12,13) AND NEW.status = 0'),
--   '$$\nDELIMITER ;\n')
-- FROM information_schema.TRIGGERS
-- WHERE TRIGGER_SCHEMA = 'smartst_test'
--   AND TRIGGER_NAME   = 'ccm_responded_at_upd';
-- SQL
--
-- mysql -u smartst_testu -p -N -r -D smartst_test <<'SQL' > ~/trg-new-body.txt
-- SELECT REPLACE(ACTION_STATEMENT,
--                'IN (5,6,7,8,9,10,11,12) AND NEW.status = 0',
--                'IN (5,6,7,8,9,10,11,12,13) AND NEW.status = 0')
-- FROM information_schema.TRIGGERS
-- WHERE TRIGGER_SCHEMA = 'smartst_test'
--   AND TRIGGER_NAME   = 'ccm_responded_at_upd';
-- SQL
--
--     ---- PROD ---- (definer and database both differ)
-- mysql -u smartst_smartst -p -N -r -D smartst_smartstaff <<'SQL' > ~/trg-prod-new.sql
-- SELECT CONCAT(
--   'DELIMITER $$\n',
--   'CREATE OR REPLACE DEFINER=`smartst_smartst`@`localhost` TRIGGER ccm_responded_at_upd\n',
--   '  BEFORE UPDATE ON call_crew_map FOR EACH ROW\n',
--   REPLACE(ACTION_STATEMENT,
--           'IN (5,6,7,8,9,10,11,12) AND NEW.status = 0',
--           'IN (5,6,7,8,9,10,11,12,13) AND NEW.status = 0'),
--   '$$\nDELIMITER ;\n')
-- FROM information_schema.TRIGGERS
-- WHERE TRIGGER_SCHEMA = 'smartst_smartstaff'
--   AND TRIGGER_NAME   = 'ccm_responded_at_upd';
-- SQL
--
-- mysql -u smartst_smartst -p -N -r -D smartst_smartstaff <<'SQL' > ~/trg-prod-new-body.txt
-- SELECT REPLACE(ACTION_STATEMENT,
--                'IN (5,6,7,8,9,10,11,12) AND NEW.status = 0',
--                'IN (5,6,7,8,9,10,11,12,13) AND NEW.status = 0')
-- FROM information_schema.TRIGGERS
-- WHERE TRIGGER_SCHEMA = 'smartst_smartstaff'
--   AND TRIGGER_NAME   = 'ccm_responded_at_upd';
-- SQL

-- 2d. GENERATE THE ROLLBACK FILE — the UNMODIFIED body, before the deploy.
--     This is the only copy of the 391-byte original. Generate it now; after
--     2f it cannot be recovered.
--
--     ---- TEST ----
-- mysql -u smartst_testu -p -N -r -D smartst_test <<'SQL' > ~/trg-rollback.sql
-- SELECT CONCAT(
--   'DELIMITER $$\n',
--   'CREATE OR REPLACE DEFINER=`smartst_testu`@`localhost` TRIGGER ccm_responded_at_upd\n',
--   '  BEFORE UPDATE ON call_crew_map FOR EACH ROW\n',
--   ACTION_STATEMENT,
--   '$$\nDELIMITER ;\n')
-- FROM information_schema.TRIGGERS
-- WHERE TRIGGER_SCHEMA = 'smartst_test'
--   AND TRIGGER_NAME   = 'ccm_responded_at_upd';
-- SQL
--
--     ---- PROD ----
-- mysql -u smartst_smartst -p -N -r -D smartst_smartstaff <<'SQL' > ~/trg-prod-rollback.sql
-- SELECT CONCAT(
--   'DELIMITER $$\n',
--   'CREATE OR REPLACE DEFINER=`smartst_smartst`@`localhost` TRIGGER ccm_responded_at_upd\n',
--   '  BEFORE UPDATE ON call_crew_map FOR EACH ROW\n',
--   ACTION_STATEMENT,
--   '$$\nDELIMITER ;\n')
-- FROM information_schema.TRIGGERS
-- WHERE TRIGGER_SCHEMA = 'smartst_smartstaff'
--   AND TRIGGER_NAME   = 'ccm_responded_at_upd';
-- SQL
--
--     Keep BOTH rollback files — ~/trg-rollback.sql (test) and
--     ~/trg-prod-rollback.sql (prod) — for as long as slice 2 might be rolled
--     back. Section 5 is just `mysql -u <definer> -p <db> < <that env's file>`.

-- 2e. CHECK THE GENERATED FILE BEFORE RUNNING IT. Same checks in both
--     environments, on that environment's OWN files. On prod substitute
--     trg-prod-new-body.txt and trg-prod-new.sql throughout.
--
-- wc -c < ~/trg-new-body.txt
--     EXPECT 395 — the 394 body bytes plus the one newline mysql appends to a
--     batch row. A 392 here means the REPLACE matched nothing (see 2b-i).
--
-- printf '%s' "$(cat ~/trg-new-body.txt)" | md5sum
--     EXPECT the new_md5 written down at 2b-i, exactly.
--     $(cat ...) strips the trailing newline(s), so this hashes the body
--     alone — the same bytes 2b-i hashed inside MariaDB. A match here proves
--     the generated file carries the intended body with no reflowing, BEFORE
--     anything is deployed. (On a Mac: md5 -q instead of md5sum.)
--
--     KNOWN GOOD, both servers, 19 Nov: 394 bytes, md5
--     15bc20aa40500486608697da36d160cb. The bodies were byte-identical before
--     the change (391 / 8e17ddf893a0ee5c5f1f477f11bbca70), so the same REPLACE
--     gives the same bytes and therefore the same hash on both.
--
-- head -3 ~/trg-new.sql
--     EXPECT line 1  DELIMITER $$          <- literally $$, NOT a number.
--                                             A number means the heredoc was
--                                             not quoted; regenerate with 2c.
--            line 2  CREATE OR REPLACE DEFINER=`smartst_testu`@`localhost` ...
--                    (on prod: `smartst_smartst` — if line 2 names the wrong
--                     user you generated with the other environment's block)
--            line 3    BEFORE UPDATE ON call_crew_map FOR EACH ROW
--
-- grep -c 'IN (5,6,7,8,9,10,11,12,13)' ~/trg-new.sql      -- EXPECT 1
-- grep -c 'DELIMITER'                  ~/trg-new.sql      -- EXPECT 2
-- tail -2 ~/trg-new.sql                -- EXPECT  END$$  then  DELIMITER ;

-- 2f. DEPLOY. CREATE OR REPLACE, never DROP + CREATE: a DROP leaves
--     call_crew_map unprotected between the two statements, and a failed
--     CREATE would leave it that way for good.
--
--     Run as the definer user, and give the DEFINER explicitly (the generated
--     file does) — an omitted DEFINER silently becomes CURRENT_USER, which on
--     prod would change who the trigger runs as.
--
--     ---- TEST ----
-- mysql -u smartst_testu -p smartst_test < ~/trg-new.sql
--
--     ---- PROD ----
-- mysql -u smartst_smartst -p smartst_smartstaff < ~/trg-prod-new.sql
--
--     No output means it worked. `-p` with a space prompts for the password
--     and takes the next word as the database.

-- 2g. Confirm the deploy — the same query as 2b, so the comparison is direct.
SELECT LENGTH(ACTION_STATEMENT) AS len,
       MD5(ACTION_STATEMENT)    AS md5,
       DEFINER                  AS definer
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = @db
  AND TRIGGER_NAME   = 'ccm_responded_at_upd';
-- EXPECT len 394, md5 = the new_md5 from 2b-i, definer UNCHANGED from 2b.
--
-- This is the authoritative check: it reads what MariaDB actually stored, not
-- what we meant to send. A len of 394 with a different md5 would mean the
-- body was reflowed on the way in — roll back with section 5 and regenerate
-- with 2c rather than editing anything by hand.


-- ---------------------------------------------------------------------------
-- 3. VERIFICATION — TEST ONLY, and EIN 5925 (O'Lack) ONLY
-- ---------------------------------------------------------------------------
-- Section 3 WRITES to call_crew_map. Never run it on prod, and never on a row
-- belonging to anyone else: 3c and 3d move a real row through 13 and 0, and
-- 3e is what puts it back.
--
-- EVERY WRITE IN THIS SECTION IS COMMENTED OUT, and the placeholders are
-- <angle-bracketed>. Uncomment and fill them in one at a time as you go. The
-- read-only SELECTs are left live, so sections 1-3 can be pasted wholesale
-- without changing a single row.

-- 3a. The table landed as asked. Uses @db from 2a.
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME   = 'call_standby_leave';
-- EXPECT one row: MyISAM, latin1_swedish_ci — the same as offer_history.

SHOW CREATE TABLE `call_standby_leave`;
SELECT COUNT(*) FROM `call_standby_leave`;   -- EXPECT 0

-- 3b. Arm 2 really contains 13 (2g checked the length and hash; this reads
--     the text, so the failure is legible rather than a hash mismatch).
--     Needs @db from 2a — same mysql session.
SELECT ACTION_STATEMENT LIKE '%IN (5,6,7,8,9,10,11,12,13)%' AS arm2_has_13
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = @db
  AND TRIGGER_NAME   = 'ccm_responded_at_upd';
-- EXPECT 1

-- 3c. 7 -> 13 STAMPS NOTHING.
--     This is the half of S2 that must NOT change: taking yourself off standby
--     is not an answer to an offer, so the original offer's timing must
--     survive it. Arm 3 needs OLD.status = 0, so no arm fires on 7 -> 13.
SELECT @uid := id FROM users WHERE ein = 5925;
-- EXPECT exactly one row. If 0 or more than 1, STOP.

SELECT crewmapID, callID, status, offered_at, responded_at, responded_src
FROM call_crew_map
WHERE userID = @uid AND status = 7
ORDER BY crewmapID
LIMIT 1;
-- WRITE DOWN crewmapID, offered_at, responded_at, responded_src.
-- 3e restores them and there is no other copy.
-- If this returns nothing, put 5925 on standby on a future call first.

-- SET @cm := <crewmapID from above>;
--
-- UPDATE call_crew_map SET status = 13
--  WHERE crewmapID = @cm AND status = 7;
-- EXPECT 1 row affected.

-- SELECT crewmapID, status, offered_at, responded_at, responded_src
-- FROM call_crew_map WHERE crewmapID = @cm;
-- EXPECT status 13, and offered_at / responded_at / responded_src ALL
--        BYTE-FOR-BYTE what you wrote down. Any change here and arm 1 or
--        arm 3 is firing when it should not — stop and roll back.

-- 3d. 13 -> 0 RESTARTS THE CLOCK. This is the half S2 adds.
-- UPDATE call_crew_map SET status = 0
--  WHERE crewmapID = @cm AND status = 13;
-- EXPECT 1 row affected.

-- SELECT crewmapID, status, offered_at, responded_at, responded_src,
--        TIMESTAMPDIFF(SECOND, offered_at, NOW()) AS offered_secs_ago
-- FROM call_crew_map WHERE crewmapID = @cm;
-- EXPECT status 0
--        offered_at    = now (offered_secs_ago in single digits)
--        responded_at  NULL
--        responded_src NULL
--
-- IF offered_at IS STILL THE OLD VALUE, ARM 2 DID NOT TAKE 13. The deploy did
-- not land, or the REPLACE in 2b matched something other than arm 2. Nothing
-- else in slice 2 works without this: the re-offer would read as already
-- answered and get-crew-offer-stats.php would mis-time it.

-- 3e. RESTORE, IN TWO HOPS. Put the row back exactly as 3c found it.
--
--     DO NOT GO STRAIGHT 0 -> 7. After 3d the row is at status 0, which is
--     precisely arm 3's OLD.status condition:
--
--       ELSEIF OLD.status = 0 AND NEW.status IN (5,6,7) AND NEW.responded_at IS NULL
--         THEN SET NEW.responded_at = NOW();
--
--     So a single-hop 0 -> 7 reaches arm 3, and what it does then depends on
--     the value being restored. If 3c's responded_at was NOT NULL the explicit
--     value survives; if it was NULL — a row ops put on standby by hand, never
--     offered — the arm fires and stamps responded_at = NOW(), silently
--     inventing a response that never happened. Hop via 13 and the question
--     does not arise.
--
--     Hop 1, 0 -> 13. No arm fires: arm 1 needs NEW.responded_src = 'phone',
--     arm 2 needs NEW.status = 0, and arm 3 needs NEW.status IN (5,6,7), which
--     13 is not. Nothing is written but the status.
-- UPDATE call_crew_map SET status = 13
--  WHERE crewmapID = @cm AND status = 0;

--     Hop 2, 13 -> 7, with the explicit values. Now no arm can fire at all:
--     arms 1 and 3 both need OLD.status = 0 and OLD is 13; arm 2 needs
--     NEW.status = 0 and NEW is 7. The values you set are the values that
--     land, and that is the ONLY reason writing offered_at by hand is safe.
-- UPDATE call_crew_map
--    SET status        = 7,
--        offered_at    = '<offered_at from 3c>',
--        responded_at  = '<responded_at from 3c>',   -- bare NULL if it was NULL
--        responded_src = '<responded_src from 3c>'   -- bare NULL if it was NULL
--  WHERE crewmapID = @cm AND status = 13;

-- SELECT crewmapID, status, offered_at, responded_at, responded_src
-- FROM call_crew_map WHERE crewmapID = @cm;
-- EXPECT identical to 3c's output, field for field.
--
-- Proven on test 19 Nov on crewmapID 281402: 7 -> 13 stamped nothing;
-- 13 -> 0 set offered_at to now (2 s old) with responded_at and responded_src
-- NULL; the two-hop restore returned the row exactly as it started.

-- 3f. Section 3 wrote no history rows — leave-standby.php does that, not the
--     trigger. Confirm the table is still empty.
SELECT COUNT(*) FROM `call_standby_leave`;   -- EXPECT 0


-- ---------------------------------------------------------------------------
-- 4. AFTER THE PHP SHIPS — the first real withdrawal
-- ---------------------------------------------------------------------------
-- Not part of this migration; the first row the endpoint writes. Section 8 of
-- HANDOFF-backup-standby-slice2-crewfinder.md is the full test plan.
--
-- SELECT id, callID, userID, FROM_UNIXTIME(left_at) AS left_at, src
-- FROM `call_standby_leave` ORDER BY id DESC LIMIT 10;
-- EXPECT the tapped call with src 'crew', each cascaded upstream call with
--        src 'cascade', all sharing one left_at.


-- ---------------------------------------------------------------------------
-- 5. ROLLBACK
-- ---------------------------------------------------------------------------
-- Order matters: the PHP first, then the trigger, then the table.
--
-- 5a. The trigger, back to the 391-byte body, from the file 2d generated
--     BEFORE the deploy. Nothing to paste and nothing to retype:
--
--     ---- TEST ----
-- mysql -u smartst_testu -p smartst_test < ~/trg-rollback.sql
--
--     ---- PROD ----
-- mysql -u smartst_smartst -p smartst_smartstaff < ~/trg-prod-rollback.sql
--
--     Then 2b again: EXPECT len 391, md5 8e17ddf893a0ee5c5f1f477f11bbca70.
--     That hash is the point of keeping it — it proves the original is back,
--     byte for byte, and not merely something 391 bytes long.
--
--     USE THAT ENVIRONMENT'S OWN FILE. ~/trg-rollback.sql carries
--     DEFINER=`smartst_testu`@`localhost`; running it against prod would leave
--     the prod trigger owned by a user that does not exist there.
--
--     REPO COPIES. Both server files are being downloaded into this repo, so
--     the pre-slice-2 original stops living only in a home directory someone
--     might tidy:
--
--       smartstaff/TRIGGER-ccm_responded_at_upd-391-test.sql
--       smartstaff/TRIGGER-ccm_responded_at_upd-391-prod.sql
--
--     They are the UNMODIFIED 391-byte body in its CREATE OR REPLACE wrapper,
--     exactly as 2d generated it. Verify each after downloading, before
--     trusting it as a rollback:
--
--       wc -c smartstaff/TRIGGER-ccm_responded_at_upd-391-test.sql   -- 549
--       wc -c smartstaff/TRIGGER-ccm_responded_at_upd-391-prod.sql   -- 551
--
--     (391 body + 1 trailing newline + a wrapper of 157 bytes on test, 159 on
--     prod — the definer name is two characters longer.) And in BOTH:
--
--       grep -c 'IN (5,6,7,8,9,10,11,12)'    -- 1   the pre-13 arm 2
--       grep -c 'IN (5,6,7,8,9,10,11,12,13)' -- 0   NOT the new body
--
--     A file that fails any of those is not the original; do not keep it.
--
--     IF THE FILE IS MISSING, 2d was skipped and the original body
--     is gone — it exists nowhere else, in this repo or in my notes. Recover
--     it from a database backup, not by retyping 2b-ii.
--
--     SAFE TO LEAVE IN PLACE. The widened arm only fires on X -> 0, and
--     without 13 in the list a 13 -> 0 re-offer simply keeps its old
--     offered_at. That is a stats bug, not a data loss, so there is no hurry
--     to roll the trigger back if the PHP is being rolled back in a rush.
--
-- 5b. The table.
-- DROP TABLE `call_standby_leave`;
--
--     SAFE TO LEAVE IN PLACE TOO, and safer than dropping it. Every PHP reader
--     is behind a capability check (get-booking.php's goat_have_standby_leave(),
--     get-call-responses.php's SHOW TABLES guard, leave-standby.php's
--     ls_has_table()), so an absent table degrades to left_at null and
--     history:false rather than failing. Dropping it DESTROYS the record of
--     who took themselves off standby and when — and nothing else holds that.
--     Only drop it if slice 2 is being abandoned.
--
-- 5c. Rows already at status 13 are NOT cleaned up by either step, by design.
--     They are real: those people did take themselves off standby. With the
--     slice 2 PHP rolled back, a 13 row reads as 'unconfirmed' through
--     get-booking.php's map default and THE GOAT's crewStatusInt() default, so
--     ops see them as unconfirmed rather than as an error. To clear them
--     properly, re-offer (13 -> 0) or set them back to Backup (13 -> 7) from
--     THE GOAT's dropdown BEFORE rolling the PHP back, while the status is
--     still labelled.
