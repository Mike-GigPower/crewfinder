<?php

	/*
	/* global file */

	include('../../global.php');

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
	/*   type = 2  -> confirmed shift   (call FK populated)
	*/

	if (!$user->checkSession())
	{
		http_response_code(401);
		die('{"error":"Not logged in"}');
	}

	$userID = (int) $_SESSION[SITE_KEY]['userID'];

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
			v.venue         AS venue_name
		FROM calendars cal
		LEFT JOIN calls    c ON c.id  = cal.call
		LEFT JOIN bookings b ON b.id  = c.bookingID
		LEFT JOIN venues   v ON v.id  = b.venueID
		WHERE cal.user = $userID
		  AND cal.start < $end_sql
		  AND cal.end   > $start_sql
		  AND cal.type IN (1, 2)
		  AND (cal.type = 1 OR c.id IS NOT NULL)
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
