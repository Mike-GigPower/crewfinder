-- ============================================================================
-- PROBE P3 (updated) -- upcoming calls overlapping a Crew Boss call with no
-- link, EXCLUDING calls marked "No Crew Boss".
-- BRIEF-default-link-self-submit-off, Release 2, B6.
--
-- WHERE: phpMyAdmin, SQL tab. READ-ONLY: one SELECT, nothing is written.
-- RUN ON: test first (smartst_test), after MIGRATION-link-button.sql; then
-- prod (smartst_smartstaff). Select the database in the left panel, or add
-- the USE line for the box you are on:
--
--   USE smartst_test;
--
-- The query is P3 from PROBE-crew-self-times-2026-11-04.sql, unchanged except
-- for ONE added clause (marked "ADDED" below). Read that file's header for the
-- name test, is_call_boss and the INNER JOIN conventions.
--
-- Known differences from the "Not linked" warning in THE GOAT
-- (goat_unlinked_boss_overlap()), unchanged by this update:
--   * any explicit link hides a call here; the warning only treats a link that
--     COUNTS (live, staffed boss call) as a link;
--   * "confirmed" here is status = 5; the warning also leaves out unanswered
--     promotions;
--   * times are off by up to an hour on the two DST changeover days (the
--     warning is not);
--   * the next 30 days only; the warning looks at every call on a booking.
-- The overlap inequalities themselves are identical (strict: ending as the
-- other begins does not count).
-- ============================================================================

SELECT
    c.bookingID                                            AS booking_id,
    b.name                                                 AS booking_name,
    c.id                                                   AS call_id,
    c.call_name                                            AS call_name,
    FROM_UNIXTIME(c.start_date, '%Y-%m-%d')                AS call_date,
    c.start_time                                           AS call_start,
    c.est_length                                           AS call_hours,
    (SELECT COUNT(*) FROM call_crew_map m
      WHERE m.callID = c.id AND m.status = 5)              AS confirmed_on_call,
    (SELECT COUNT(*) FROM call_crew_map m
      WHERE m.callID = c.id AND m.status = 5
        AND SUBSTRING(m.is_call_boss, 1, 1) = '1')         AS team_leads_on_call,
    bc.id                                                  AS overlap_boss_call_id,
    bc.call_name                                           AS overlap_boss_call_name,
    FROM_UNIXTIME(bc.start_date, '%Y-%m-%d')               AS boss_date,
    bc.start_time                                          AS boss_start,
    bc.est_length                                          AS boss_hours,
    (SELECT COUNT(*) FROM call_crew_map m
      WHERE m.callID = bc.id AND m.status = 5)             AS confirmed_on_boss_call
FROM calls c
INNER JOIN calls bc
        ON bc.bookingID = c.bookingID
       AND bc.id       <> c.id
LEFT  JOIN bookings b ON b.id = c.bookingID
WHERE c.start_date >= UNIX_TIMESTAMP(CURDATE())
  AND c.start_date <  UNIX_TIMESTAMP(CURDATE() + INTERVAL 30 DAY)
  AND c.cancelled_at IS NULL

  -- the call itself is not a Crew Boss call
  AND NOT (    LOWER(TRIM(c.call_name)) LIKE '%boss%'
           AND LOWER(TRIM(c.call_name)) NOT LIKE '%cancel%'
           AND LOWER(TRIM(c.call_name)) NOT IN ('imag & boss', 'crew boss & pushers'))

  -- no usable explicit link (none, dangling, or self)
  AND NOT EXISTS (
        SELECT 1
        FROM call_supervision s
        INNER JOIN calls sb ON sb.id = s.boss_call
        WHERE s.child_call = c.id
          AND s.boss_call <> c.id
      )

  -- the candidate is a live, boss-named Crew Boss call
  AND bc.cancelled_at IS NULL
  AND LOWER(TRIM(bc.call_name)) LIKE '%boss%'
  AND LOWER(TRIM(bc.call_name)) NOT LIKE '%cancel%'
  AND LOWER(TRIM(bc.call_name)) NOT IN ('imag & boss', 'crew boss & pushers')

  -- strict overlap
  AND (bc.start_date + TIME_TO_SEC(bc.start_time))
        < (c.start_date + TIME_TO_SEC(c.start_time) + ROUND(c.est_length * 3600))
  AND (bc.start_date + TIME_TO_SEC(bc.start_time) + ROUND(bc.est_length * 3600))
        > (c.start_date + TIME_TO_SEC(c.start_time))

  -- not marked "No Crew Boss" (J7) -- ADDED for the Link button, the same
  -- exclusion goat_unlinked_boss_overlap() now applies (B6). Run
  -- MIGRATION-link-button.sql first: before it, this table doesn't exist.
  AND NOT EXISTS (SELECT 1 FROM call_no_crew_boss n WHERE n.callID = c.id)

  -- and it would actually yield a contact
  AND EXISTS (SELECT 1 FROM call_crew_map m
               WHERE m.callID = bc.id AND m.status = 5)
ORDER BY c.start_date ASC, c.start_time ASC, c.id ASC, bc.id ASC;
