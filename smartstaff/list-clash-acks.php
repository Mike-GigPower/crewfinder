<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* admin OR leadership OR operations — this is a bulk READ over a window,
	/* returning the same class of data the schedule already exposes to
	/* Leadership. Gated on goat_can_read_all(), mirroring
	/* get-booked-crew-bulk.php. (The WRITE — ack-clash.php — keeps its own
	/* strict admin-only check.)
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
	/* Every acknowledgement whose LOWER call's calendar shift (type=2) overlaps
	/* the window. Joined through calendars for the window filter and through
	/* users on acked_by for the operator's display name.
	/*
	/* Schema notes (mirrored from get-booked-crew-bulk.php):
	/*   users      : firstname + lastname (no single name column)
	/*   calendars  : user, call, type, start, end (type=2 for confirmed shifts)
	*/

	$sql = "
		SELECT
			a.userID       AS user_id,
			a.callID_a     AS call_a,
			a.callID_b     AS call_b,
			a.rule_no      AS rule,
			a.fingerprint  AS fingerprint,
			a.acked_at     AS acked_at,
			a.acked_by     AS acked_by,
			a.note         AS note,
			u.firstname    AS firstname,
			u.lastname     AS lastname
		FROM call_clash_ack a
		LEFT JOIN calendars cal ON cal.user = a.userID
		                       AND cal.call = a.callID_a
		                       AND cal.type = 2
		LEFT JOIN users     u   ON u.id     = a.acked_by
		WHERE cal.start < $end_sql
		  AND cal.end   > $start_sql
		ORDER BY a.callID_a ASC, a.userID ASC
	";

	$result = mysql_query($sql);

	if ($result === false)
	{
		http_response_code(500);
		die('{"error":"query failed: ' . addslashes(mysql_error()) . '"}');
	}

	$acks = array();

	while ($row = mysql_fetch_object($result))
	{
		/* "Lastname, Firstname" — matches get-booked-crew-bulk.php +
		/* list-crew-bulk.php + get-shifts-bulk.php */
		$acked_by_name = trim($row->lastname);
		if (strlen(trim($row->firstname)) > 0)
			$acked_by_name .= ', ' . trim($row->firstname);

		$acks[] = array(
			'user_id'       => (int) $row->user_id,
			'call_a'        => (int) $row->call_a,
			'call_b'        => (int) $row->call_b,
			'rule'          => (int) $row->rule,
			'fingerprint'   => $row->fingerprint,
			'acked_at'      => (int) $row->acked_at,
			'acked_by'      => (int) $row->acked_by,
			'acked_by_name' => $acked_by_name,
			'note'          => ($row->note !== null && $row->note !== '') ? $row->note : null,
		);
	}

	echo json_encode(array(
		'window' => array('start' => $start_raw, 'end' => $end_raw),
		'acks'   => $acks,
	));

?>
