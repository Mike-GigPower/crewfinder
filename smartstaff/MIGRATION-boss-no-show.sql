-- ─────────────────────────────────────────────────────────────────────────
-- MIGRATION — Crew Boss reports a No-show (slice 1 of BRIEF-boss-no-show.md)
--
-- call_time_submissions.no_show   1 = the boss reported this person as a
--                                 No-show instead of entering times. The
--                                 row carries NULL on_time / off_time and no
--                                 break rows.
--
-- No other state column. Reported = latest live row has no_show = 1 and the
-- person is still status 5; Confirmed = Ops set status 8 (existing path);
-- Rejected = Ops void the report row.
--
-- ORDER: the slice 1 PHP is safe either side of this (every read and write
-- checks the column with SHOW COLUMNS — goat_ts_have_no_show()). Until it
-- runs, a No-show report is refused with a 500 and nothing else changes.
--
-- TEST then PROD. Run as the app's own DB user in phpMyAdmin, with the
-- schema selected in the left panel (not from information_schema).
-- ─────────────────────────────────────────────────────────────────────────

-- 0. PRE-CHECK — read-only. Must return an EMPTY result (column not there yet).

SHOW COLUMNS FROM call_time_submissions LIKE 'no_show';

-- 1. The column.

ALTER TABLE call_time_submissions
  ADD COLUMN no_show TINYINT(1) NOT NULL DEFAULT 0;

-- 2. Verify — paste both outputs back.
--    Expect: one row, Type tinyint(1), Null NO, Default 0.
--    Expect: total_rows equals the table's row count, no_show_rows = 0.

SHOW COLUMNS FROM call_time_submissions LIKE 'no_show';

SELECT COUNT(*) AS total_rows, SUM(no_show = 1) AS no_show_rows
  FROM call_time_submissions;
