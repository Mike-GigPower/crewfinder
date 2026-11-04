-- ============================================================================
-- PROBE — Crew self-submitted times, slice B (BRIEF-crew-self-times.md §3.7)
-- 4 Nov 2026
--
-- WHERE: phpMyAdmin, SQL tab.   RUN ON: prod (smartst_smartstaff).
-- READ-ONLY. Every statement is a SELECT. Nothing is written.
--
-- Three questions, one per part. Paste each result set back.
--
--   P1  supervision links whose SUPERVISING call is not boss-named      (B / D20)
--   P2  supervision links whose SUPERVISING call is cancelled           (C)
--   P3  upcoming calls whose Crew Boss contact comes ONLY from the
--       same-booking overlap fallback, which slice B removes            (E / D22)
--
-- THE NAME TEST IN SQL mirrors goat_is_boss_call_name()
-- (resolve-call-contact.php:63) exactly: raw call_name, lower-cased and
-- trimmed; contains 'boss'; does not contain 'cancel'; is not one of the two
-- known working calls. Raw, not HTML-decoded, because the PHP compares raw
-- too -- so "IMAG &amp; Boss" fails the exclusion in both places alike.
--
-- is_call_boss IS binary(50). Never "= 1". SUBSTRING(..., 1, 1) = '1' reads
-- the first byte (0x31), the same thing the PHP (int) cast reads.
--
-- Every query INNER JOINs `calls` on both ends of a link, matching
-- goat_supervision_boss_call(): a link left dangling by a deleted call is
-- invisible to the code and so is left out here.
-- ============================================================================

USE smartst_smartstaff;


-- ---- P1 : links from a call that is NOT a dedicated Crew Boss call ---------
-- Decision B: only Crew Boss calls supervise. Every row here is a link that
-- slice B will start IGNORING (tier, scope, lookup and contact), so the child
-- loses its boss through this link. Expect zero or a handful.
--
-- child_upcoming = 1 marks the ones that matter now; past ones only affect
-- history views and the Times outstanding lane.

SELECT
    s.id                                   AS link_id,
    s.booking_id                           AS booking_id,
    b.name                                 AS booking_name,
    s.boss_call                            AS boss_call_id,
    cb.call_name                           AS boss_call_name,
    s.child_call                           AS child_call_id,
    cc.call_name                           AS child_call_name,
    FROM_UNIXTIME(cc.start_date, '%Y-%m-%d') AS child_date,
    cc.start_time                          AS child_start,
    (cc.start_date >= UNIX_TIMESTAMP(CURDATE())) AS child_upcoming,
    FROM_UNIXTIME(s.created)               AS linked_at,
    s.created_by                           AS linked_by
FROM call_supervision s
INNER JOIN calls cb ON cb.id = s.boss_call
INNER JOIN calls cc ON cc.id = s.child_call
LEFT  JOIN bookings b ON b.id = s.booking_id
WHERE NOT (
        LOWER(TRIM(cb.call_name)) LIKE '%boss%'
    AND LOWER(TRIM(cb.call_name)) NOT LIKE '%cancel%'
    AND LOWER(TRIM(cb.call_name)) NOT IN ('imag & boss', 'crew boss & pushers')
)
ORDER BY cc.start_date DESC, cc.start_time DESC;


-- ---- P2 : links from a CANCELLED supervising call --------------------------
-- Decision C: cancelled Crew Boss calls never count. The resolver already
-- ignores these for CONTACT; scope and the boss lookup do not yet, so every
-- row here is a child whose time-entry authority / boss attribution changes.
--
-- confirmed_on_boss > 0 is the dangerous case (D12 re-add on a cancelled
-- call): someone confirmed on a cancelled boss call, still holding scope.
-- boss_name_ok = 0 means the row also appears in P1.

SELECT
    s.id                                   AS link_id,
    s.booking_id                           AS booking_id,
    b.name                                 AS booking_name,
    s.boss_call                            AS boss_call_id,
    cb.call_name                           AS boss_call_name,
    FROM_UNIXTIME(cb.cancelled_at)         AS boss_cancelled_at,
    (    LOWER(TRIM(cb.call_name)) LIKE '%boss%'
     AND LOWER(TRIM(cb.call_name)) NOT LIKE '%cancel%'
     AND LOWER(TRIM(cb.call_name)) NOT IN ('imag & boss', 'crew boss & pushers')
    )                                      AS boss_name_ok,
    (SELECT COUNT(*) FROM call_crew_map m
      WHERE m.callID = s.boss_call AND m.status = 5)  AS confirmed_on_boss,
    s.child_call                           AS child_call_id,
    cc.call_name                           AS child_call_name,
    FROM_UNIXTIME(cc.start_date, '%Y-%m-%d') AS child_date,
    (cc.cancelled_at IS NOT NULL)          AS child_also_cancelled,
    (cc.start_date >= UNIX_TIMESTAMP(CURDATE())) AS child_upcoming
FROM call_supervision s
INNER JOIN calls cb ON cb.id = s.boss_call
INNER JOIN calls cc ON cc.id = s.child_call
LEFT  JOIN bookings b ON b.id = s.booking_id
WHERE cb.cancelled_at IS NOT NULL
ORDER BY cc.start_date DESC, cc.start_time DESC;


-- ---- P3 : upcoming calls whose Crew Boss contact is OVERLAP-ONLY -----------
-- Decision E removes the same-booking overlap fallback from rung 2 of
-- goat_resolve_call_contact(). This lists, for the next 30 days, every
-- (call, overlapping Crew Boss call) pair that fallback produces today, i.e.
-- the calls that need an explicit link before slice B ships.
--
-- Mirrors the resolver's fallback branch (resolve-call-contact.php:357-387):
--   * the call itself is not cancelled and is NOT boss-named (crew on a boss
--     call skip rungs 1-2 entirely)
--   * no usable explicit link: none at all, a dangling one, or a self-link
--     (the resolver treats all three as "no edge")
--   * the candidate is another call in the SAME booking, boss-named, not
--     cancelled, and STRICTLY overlaps (ending exactly as yours begins does
--     not count)
--   * the candidate has at least one confirmed person -- an empty boss call
--     yields no contact, so it isn't one the call would lose
--
-- team_leads_on_call: rung 1 returns a flagged Team Lead BEFORE the overlap
-- fallback is ever reached. So:
--   team_leads_on_call = 0  -> every crew member on the call loses the Crew
--                              Boss contact (falls to the on-site contact)
--   team_leads_on_call > 0  -> only the Team Lead themselves loses it; the
--                              rest of the crew already see the Team Lead
--
-- TIMES ARE APPROXIMATE BY UP TO AN HOUR ON THE TWO DST CHANGEOVER DAYS.
-- start_date is a unix timestamp at local midnight and TIME_TO_SEC() adds
-- clock seconds to it -- the arithmetic calls-awaiting-times.php explains
-- the PHP avoids. Good enough to find candidates; the 30-day window does not
-- cross a changeover until April 2027 in any case.
--
-- The 30-day window uses the MySQL server's CURDATE(). If the server runs
-- on UTC that is up to 11 hours off Melbourne at either end -- harmless for
-- a 30-day sweep.

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

  -- and it would actually yield a contact
  AND EXISTS (SELECT 1 FROM call_crew_map m
               WHERE m.callID = bc.id AND m.status = 5)
ORDER BY c.start_date ASC, c.start_time ASC, c.id ASC, bc.id ASC;
