<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');
	include_once('resolve-call-contact.php');

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
			cca.prev_start_date AS prev_start_date,
			cca.prev_start_time AS prev_start_time,
			cca.prev_est_length AS prev_est_length,
			cca.changed_at      AS changed_at
		FROM calendars cal
		LEFT JOIN calls    c ON c.id  = cal.call
		LEFT JOIN bookings b ON b.id  = c.bookingID
		LEFT JOIN venues   v ON v.id  = b.venueID
		LEFT JOIN call_change_ack cca
		       ON cca.callID = cal.call
		      AND cca.userID = cal.user
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
			$entry['venue']        = $row->venue_name;

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
