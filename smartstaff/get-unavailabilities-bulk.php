<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* admin OR leadership — this endpoint returns unavailabilities for every
	/* crew member, not just the requester. Leadership is read-only; this is a
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
	/* Single query: every type=1 (unavailability) row that overlaps the window.
	/* Joined to users so the caller gets name without a second lookup.
	/*
	/* Datetimes preserved at hour-level granularity (unlike the legacy HTML
	/* scrape which only surfaced dates). Each row carries its calendars.id so
	/* callers can correlate with the per-user get-unavailabilities.php payload
	/* (which exposes the same id for delete-by-id operations).
	*/

	$sql = "
		SELECT
			cal.id     AS event_id,
			cal.user   AS user_id,
			u.firstname AS firstname,
			u.lastname  AS lastname,
			cal.title  AS title,
			cal.start  AS start_dt,
			cal.end    AS end_dt
		FROM calendars cal
		LEFT JOIN users u ON u.id = cal.user
		WHERE cal.start < $end_sql
		  AND cal.end   > $start_sql
		  AND cal.type = 1
		ORDER BY cal.user ASC, cal.start ASC
	";

	$result = mysql_query($sql);

	if ($result === false)
	{
		http_response_code(500);
		die('{"error":"query failed: ' . addslashes(mysql_error()) . '"}');
	}

	$unavails = array();

	while ($row = mysql_fetch_object($result))
	{
		/* "Lastname, Firstname" — matches list-crew-bulk.php + get-shifts-bulk.php */
		$display_name = trim($row->lastname);
		if (strlen(trim($row->firstname)) > 0)
			$display_name .= ', ' . trim($row->firstname);

		$unavails[] = array(
			'event_id' => (int) $row->event_id,
			'user_id'  => (int) $row->user_id,
			'user'     => $display_name,
			'title'    => $row->title,
			'reason'   => $row->title,
			'start'    => date('Y-m-d\TH:i:s', strtotime($row->start_dt)),
			'end'      => date('Y-m-d\TH:i:s', strtotime($row->end_dt)),
		);
	}

	echo json_encode(array(
		'window'   => array('start' => $start_raw, 'end' => $end_raw),
		'unavails' => $unavails,
	));

?>
