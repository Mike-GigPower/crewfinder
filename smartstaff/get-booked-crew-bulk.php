<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* admin OR leadership — this endpoint returns booked-crew assignments for
	/* every call in a window, not just one. Leadership is read-only; this is a
	/* read endpoint, so it is permitted.
	*/

	if (!goat_can_read_all())
	{
		http_response_code(403);
		die('{"error":"Admin or Leadership only"}');
	}

	/*
	/* validate input
	/*
	/* start, end: YYYY-MM-DD (inclusive start, exclusive end)
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
	/* process the request
	/*
	/* Single query: every confirmed (status=5) crew-call assignment whose
	/* underlying calendar shift overlaps the window. Joined to users for
	/* name and through calls → bookings → venues for clash-rule venue
	/* comparison.
	/*
	/* status=5 only — pending/declined/noshow do not represent commitments
	/* that should trigger clash flags. Matches the conflict semantics used
	/* by check_conflict in app.py.
	/*
	/* Schema notes (mirrored from get-shifts-bulk.php):
	/*   users      : firstname + lastname (no single name column)
	/*   bookings   : name, venueID
	/*   calls      : bookingID, call_name
	/*   venues     : venue (not "name")
	/*   calendars  : user, call, type, start, end (type=2 for confirmed shifts)
	*/

	$sql = "
		SELECT
			ccm.callID      AS call_id,
			ccm.userID      AS user_id,
			u.firstname     AS firstname,
			u.lastname      AS lastname,
			cal.start       AS start_dt,
			cal.end         AS end_dt,
			v.venue         AS venue_name
		FROM call_crew_map ccm
		LEFT JOIN users     u   ON u.id      = ccm.userID
		LEFT JOIN calendars cal ON cal.call  = ccm.callID
		                       AND cal.user  = ccm.userID
		                       AND cal.type  = 2
		LEFT JOIN calls     c   ON c.id      = ccm.callID
		LEFT JOIN bookings  b   ON b.id      = c.bookingID
		LEFT JOIN venues    v   ON v.id      = b.venueID
		WHERE ccm.status = 5
		  AND cal.start  < $end_sql
		  AND cal.end    > $start_sql
		ORDER BY ccm.callID ASC, u.lastname ASC
	";

	$result = mysql_query($sql);

	if ($result === false)
	{
		http_response_code(500);
		die('{"error":"query failed: ' . addslashes(mysql_error()) . '"}');
	}

	$assignments = array();

	while ($row = mysql_fetch_object($result))
	{
		/* skip rows where the calendar shift didn't materialise — defensive,
		/* shouldn't happen given the JOIN but the data has historically had
		/* orphan call_crew_map rows */
		if (!$row->start_dt || !$row->end_dt)
			continue;

		/* "Lastname, Firstname" — matches list-crew-bulk.php +
		/* get-shifts-bulk.php + get-unavailabilities-bulk.php */
		$display_name = trim($row->lastname);
		if (strlen(trim($row->firstname)) > 0)
			$display_name .= ', ' . trim($row->firstname);

		$assignments[] = array(
			'call_id' => (int) $row->call_id,
			'user_id' => (int) $row->user_id,
			'user'    => $display_name,
			'start'   => date('Y-m-d\TH:i:s', strtotime($row->start_dt)),
			'end'     => date('Y-m-d\TH:i:s', strtotime($row->end_dt)),
			'venue'   => $row->venue_name ? $row->venue_name : '',
		);
	}

	echo json_encode(array(
		'window'      => array('start' => $start_raw, 'end' => $end_raw),
		'assignments' => $assignments,
	));

?>
