<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');
	include_once('resolve-call-contact.php');
	/*
	/* SLICE C2 — THE OPT-IN. Switches rung 2 of the contact hierarchy to prefer
	/* an explicit call_supervision edge over time overlap.
	/*
	/* THIS LINE LOOKS UNUSED AND IS NOT. Nothing in this file calls into
	/* supervision-graph.php directly; resolve-call-contact.php reaches it via
	/* function_exists('goat_supervision_boss_call'), guarded at the call site to
	/* avoid a circular include (see the comment there). Remove this include and
	/* the resolver silently falls back to overlap-only — no error, no failed
	/* test, because overlap is still a valid code path. It just stops finding
	/* the boss of any call that does not happen to overlap its boss call.
	*/
	include_once('supervision-graph.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SELF endpoint — returns the logged-in user's OWN calendar (shifts +
	/* unavailabilities) for a bounded window. Scoped to $_SESSION userID, so a
	/* crew member can only read their own schedule. No admin gate.
	/*
	/* This is the self-scoped sibling of get-shifts-bulk.php (which is admin
	/* only and returns every crew member). Field shapes are kept identical so
	/* the app can reuse the same parsing for "My Utilization" and "My Schedule".
	/*
	/*   type = 1  -> unavailability   (call FK is NULL)
	/*   type = 2  -> shift            (call FK populated)
	/*
	/* IMPORTANT: a type-2 calendar row on its own does NOT prove the crew member
	/* is confirmed. A row can linger after a call was declined (status 6) or was
	/* only ever assigned/offered. So for type-2 rows we ALSO require a matching
	/* call_crew_map row with status = 5 (Confirmed). Without this check, declined
	/* calls leak into "My Shifts". Backups (status 7) never get a calendar row,
	/* so they are naturally excluded.
	*/


	$userID = goat_acting_user_id();

	/*
	/* validate input — start, end: YYYY-MM-DD (inclusive start, exclusive end)
	*/

	$start_raw = isset($_GET['start']) ? $_GET['start'] : '';
	$end_raw   = isset($_GET['end'])   ? $_GET['end']   : '';

	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_raw) ||
	    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_raw))
	{
		http_response_code(400);
		die('{"error":"start and end must be YYYY-MM-DD"}');
	}

	$start_ts = strtotime($start_raw . ' 00:00:00');
	$end_ts   = strtotime($end_raw   . ' 00:00:00');

	if ($start_ts === false || $end_ts === false || $end_ts <= $start_ts)
	{
		http_response_code(400);
		die('{"error":"invalid date range"}');
	}

	/* cap the window at 120 days to protect the DB */

	if (($end_ts - $start_ts) > (120 * 86400))
	{
		http_response_code(400);
		die('{"error":"window exceeds 120 days"}');
	}

	$start_sql = $db->sc(date('Y-m-d 00:00:00', $start_ts));
	$end_sql   = $db->sc(date('Y-m-d 00:00:00', $end_ts));

	/*
	/* Single query: this user's calendars rows overlapping the window, joined
	/* to calls + bookings + venues for shift context. Mirrors get-shifts-bulk
	/* but with `cal.user = <self>` instead of returning all crew.
	/*
	/* The EXISTS(...) guard is the fix: a type-2 (shift) row is only returned
	/* when this same user has a CONFIRMED (status 5) call_crew_map row for that
	/* call. Type-1 (unavailability) rows are unaffected.
	/*
	/* is_call_boss is read by a CORRELATED SUBQUERY, not by widening the EXISTS
	/* into a join. A join to call_crew_map would multiply calendar rows if a
	/* (callID, userID) pair ever had more than one row — which should not
	/* happen, but MyISAM enforces nothing, and silently doubling a crew
	/* member's shifts is a far worse failure than one extra indexed lookup.
	/* The subquery leads on (userID, callID), the existing index, and returns
	/* NULL for type-1 rows (cal.call is NULL) — read only in the type-2 branch.
	/*
	/* It drives the "Crew list" link on the shift card: a call boss can see who
	/* else is on their call and ring them. my-shift-history.php already emits
	/* this field; this brings upcoming shifts into line with past ones.
	*/

	$sql = "
		SELECT
			cal.id          AS event_id,
			cal.user        AS user_id,
			cal.title       AS title,
			cal.start       AS start_dt,
			cal.end         AS end_dt,
			cal.type        AS event_type,
			cal.call        AS call_id,
			c.bookingID     AS booking_id,
			c.call_name     AS call_name,
			b.name          AS booking_name,
			v.venue         AS venue_name,
			v.address       AS venue_address,
			v.suburb        AS venue_suburb,
			v.state         AS venue_state,
			v.postcode      AS venue_postcode,
			cca.prev_start_date AS prev_start_date,
			cca.prev_start_time AS prev_start_time,
			cca.prev_est_length AS prev_est_length,
			cca.changed_at      AS changed_at,
			cpa.promoted_at     AS promoted_at,
			cpa.acked_at        AS promo_acked_at,
			(
			  SELECT ccb.is_call_boss
			  FROM call_crew_map ccb
			  WHERE ccb.callID = cal.call
			    AND ccb.userID = cal.user
			    AND ccb.status = 5
			  LIMIT 1
			)                   AS is_call_boss
		FROM calendars cal
		LEFT JOIN calls    c ON c.id  = cal.call
		LEFT JOIN bookings b ON b.id  = c.bookingID
		LEFT JOIN venues   v ON v.id  = b.venueID
		LEFT JOIN call_change_ack cca
		       ON cca.callID = cal.call
		      AND cca.userID = cal.user
		LEFT JOIN call_promo_ack cpa
		       ON cpa.callID = cal.call
		      AND cpa.userID = cal.user
		WHERE cal.user = $userID
		  AND cal.start < $end_sql
		  AND cal.end   > $start_sql
		  AND cal.type IN (1, 2)
		  AND (
		        cal.type = 1
		        OR (
		             c.id IS NOT NULL
		             AND EXISTS (
		               SELECT 1
		               FROM call_crew_map ccm
		               WHERE ccm.callID = cal.call
		                 AND ccm.userID = cal.user
		                 AND ccm.status = 5
		             )
		           )
		      )
		ORDER BY cal.start ASC
	";

	$result = mysql_query($sql);

	if ($result === false)
	{
		http_response_code(500);
		die('{"error":"query failed: ' . addslashes(mysql_error()) . '"}');
	}

	/*
	/* SUPERVISORY SCOPE, RESOLVED ONCE — ADDED 27 Aug 2026 (slice C4).
	/*
	/* BOTH of these are WHOLE-SCOPE queries, not per-call tests. Calling either
	/* inside the row loop would issue one query per shift, and this endpoint
	/* returns up to 56 days of them — a visible slowdown on the busiest page in
	/* Crew Hub. Resolve once, test with in_array() below.
	/*
	/*   $bossScope — the calls this user may ACT ON: flagged on the call
	/*                itself, or a child of a dedicated boss call they are
	/*                confirmed on.
	/*   $bossCalls — the user's OWN dedicated boss calls. A SEPARATE SET, and
	/*                the reason is the whole point of this slice: goat_boss_
	/*                scope() reaches a container ONLY through its direct
	/*                branch, which requires the is_call_boss flag. The
	/*                supervisory branch adds a boss call's CHILDREN and never
	/*                the boss call itself. So a confirmed-but-unflagged boss —
	/*                Q4's normal case, 18 of 22 containers on test — is not in
	/*                scope for their own Crew Boss call and would see no Crew
	/*                list button on the one card they most need it from.
	/*
	/* Both return array() for most crew, which is the point: can_see_crew then
	/* collapses to is_call_boss and nothing changes for the roster at large.
	*/

	$bossScope = goat_boss_scope($userID);
	$bossCalls = goat_boss_calls_for_user($userID);

	$shifts   = array();
	$unavails = array();

	while ($row = mysql_fetch_object($result))
	{
		$entry = array(
			'event_id' => (int) $row->event_id,
			'user_id'  => (int) $row->user_id,
			'title'    => $row->title,
			'start'    => date('Y-m-d\TH:i:s', strtotime($row->start_dt)),
			'end'      => date('Y-m-d\TH:i:s', strtotime($row->end_dt)),
		);

		if ($row->event_type == 2)
		{
			$entry['call_id']      = (int) $row->call_id;
			$entry['booking_id']   = (int) $row->booking_id;
			$entry['call_name']    = $row->call_name;
			$entry['booking_name'] = $row->booking_name;
			$entry['venue']          = $row->venue_name;
			$entry['venue_address']  = (string) $row->venue_address;
			$entry['venue_suburb']   = (string) $row->venue_suburb;
			$entry['venue_state']    = (string) $row->venue_state;
			$entry['venue_postcode'] = (string) $row->venue_postcode;

			/*
			/* Is this crew member FLAGGED as the call boss on this call? A fact
			/* about the call_crew_map row and nothing more — the "Crew list"
			/* link is gated on can_see_crew below, not on this, since 27 Aug
			/* 2026. Always emitted (1 or 0) rather than omitted when false, so
			/* the portal can tell "not the boss" from "old PHP that never sent
			/* the field".
			*/

			$entry['is_call_boss'] = ((int) $row->is_call_boss === 1) ? 1 : 0;

			/*
			/* MAY THIS CREW MEMBER SEE THE CREW LIST FOR THIS CALL? A SEPARATE
			/* QUESTION FROM is_call_boss, AND IT NEEDS A SEPARATE FIELD.
			/*
			/* is_call_boss is a FACT ABOUT A call_crew_map ROW and other
			/* consumers read it as exactly that. Widening it to mean "may see
			/* the crew" would make it lie to every one of them. This is the
			/* honestly-named boolean for the question the UI actually has.
			/*
			/* TRUE BY ANY OF THREE ROUTES, matching the union my-call-crew.php
			/* gates on:
			/*
			/*   1. flagged is_call_boss on this call
			/*   2. holding it through a call_supervision edge (slice C3)
			/*   3. it IS one of the viewer's own dedicated boss calls
			/*
			/* Route 3 is not redundant with route 2 and the difference is what
			/* this slice exists for. goat_boss_scope() reaches a container only
			/* via its DIRECT branch, which requires the flag; its supervisory
			/* branch adds children and never the container. A boss confirmed on
			/* a Crew Boss call and not flagged on it — Q4's normal case — is
			/* therefore absent from their own container's scope, and the Crew
			/* Boss card is the one card on their shifts page that is actually
			/* theirs: the children are not on this page at all, because they
			/* are not confirmed on them.
			/*
			/* ADDITIVE. is_call_boss is emitted unchanged directly above; no
			/* existing consumer moves.
			*/

			$entry['can_see_crew'] =
				(((int) $row->is_call_boss === 1)
				 || in_array((int) $row->call_id, $bossScope)
				 || in_array((int) $row->call_id, $bossCalls))
					? 1 : 0;

			/*
			/* Contact hierarchy — who does this crew member call? Resolved at READ
			/* time, never cached into a notification: the boss can change after an
			/* offer goes out. See DESIGN-call-contact-hierarchy.
			/*
			/* FUTURE SHIFTS ONLY. A finished shift needs no contact, and the window
			/* here is capped at 120 days — skipping the past keeps the query count
			/* proportional to what is actually upcoming.
			*/

			if (strtotime($row->end_dt) >= time())
				$entry['contacts'] = goat_resolve_call_contact((int) $row->call_id, (int) $row->user_id);

			/*
			/* A matched call_change_ack row means this confirmed shift has an
			/* OUTSTANDING timing change awaiting the crew member's Accept/Decline.
			/* Emit the "was" timing so the portal can render the delta. Resolved
			/* the SAME way as the offer/backup endpoints (unix date + start_time;
			/* end = start + prev_est_length hours). No match -> omit change_pending.
			*/
			if ($row->prev_start_date !== null)
			{
				$prevDateStr  = date('Y-m-d', (int) $row->prev_start_date);
				$prevStartTs  = strtotime($prevDateStr . ' ' . $row->prev_start_time);
				$prevEndTs    = $prevStartTs + (int) round(((double) $row->prev_est_length) * 3600);

				$entry['change_pending'] = true;
				$entry['prev_start']     = date('Y-m-d\TH:i:s', $prevStartTs);
				$entry['prev_end']       = date('Y-m-d\TH:i:s', $prevEndTs);
				$entry['changed_at']     = (int) $row->changed_at;
			}

			/*
			/* A call_promo_ack row with no acked_at means ops promoted this crew
			/* member off standby and they have not answered. The portal renders
			/* Accept/Decline. No match, or already answered -> omit entirely.
			*/
			if ($row->promoted_at !== null && $row->promo_acked_at === null)
			{
				$entry['promo_pending'] = true;
				$entry['promoted_at']   = (int) $row->promoted_at;
			}

			$shifts[] = $entry;
		}
		else
		{
			$entry['reason'] = $row->title;
			$unavails[] = $entry;
		}
	}

	echo json_encode(array(
		'window'   => array('start' => $start_raw, 'end' => $end_raw),
		'shifts'   => $shifts,
		'unavails' => $unavails,
	));

?>
