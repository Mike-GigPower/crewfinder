<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — every shift (call assignment) ever made to one crew
	/* member, with its current status, newest first. Powers the Shifts tab in
	/* the GOAT admin Edit Crew Member modal. Admin-gated.
	/*
	/* Source of truth is call_crew_map (one row per crew-member-per-call),
	/* joined to the call for date/time and the booking/venue for context. This
	/* also covers OFFERED calls (status 0/1), which never reach the calendars
	/* table, so it is a superset of my-shifts.php (which is confirmed-only).
	/*
	/* call_crew_map.status:  0 Unconfirmed, 1 SMS Sent (both "offered"),
	/*                        5 Confirmed, 6 Declined.
	/*
	/* Schema (verified against live DB, see get-calls-bulk.php):
	/*   call_crew_map : crewmapID, userID, callID, status, is_call_boss
	/*   calls         : id, bookingID, call_name, start_date (INT unix at local
	/*                   midnight), start_time (TIME), est_length (DOUBLE hours)
	/*   bookings      : id, name, venueID
	/*   venues        : id, venue
	*/

	if (goat_user_cohort() !== 'admin')
	{
		http_response_code(403);
		die('{"error":"Admin only"}');
	}

	$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

	if ($id <= 0)
	{
		http_response_code(400);
		die('{"error":"id required"}');
	}

	$sql = "
		SELECT
			ccm.crewmapID    AS map_id,
			ccm.status       AS status,
			ccm.is_call_boss AS is_call_boss,
			c.id             AS call_id,
			c.call_name      AS call_name,
			c.start_date     AS start_date,
			c.start_time     AS start_time,
			c.est_length     AS est_length,
			b.id             AS booking_id,
			b.name           AS booking_name,
			v.venue          AS venue_name
		FROM call_crew_map ccm
		LEFT JOIN calls    c ON c.id  = ccm.callID
		LEFT JOIN bookings b ON b.id  = c.bookingID
		LEFT JOIN venues   v ON v.id  = b.venueID
		WHERE ccm.userID = " . $id . "
		  AND c.id IS NOT NULL
		ORDER BY c.start_date DESC, c.start_time DESC
	";

	$res = mysql_query($sql);

	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"query failed: ' . addslashes(mysql_error()) . '"}');
	}

	$labels = array(
		0 => 'Unconfirmed',
		1 => 'SMS Sent',
		5 => 'Confirmed',
		6 => 'Declined'
	);

	$shifts = array();

	while ($row = mysql_fetch_object($res))
	{
		$date_i  = (int) $row->start_date;
		$time_hm = substr($row->start_time, 0, 5);
		if (!preg_match('/^\d{2}:\d{2}$/', $time_hm)) $time_hm = '00:00';

		list($hh, $mm) = array_map('intval', explode(':', $time_hm));
		$start_unix = $date_i + ($hh * 3600) + ($mm * 60);
		$len        = (float) $row->est_length;
		$end_unix   = $start_unix + (int) round($len * 3600);

		$st = (int) $row->status;
		$status_label = isset($labels[$st]) ? $labels[$st] : ('Status ' . $st);

		$shifts[] = array(
			'map_id'       => (int) $row->map_id,
			'call_id'      => (int) $row->call_id,
			'booking_id'   => (int) $row->booking_id,
			'call_name'    => $row->call_name,
			'booking_name' => $row->booking_name,
			'venue'        => $row->venue_name,
			'date_iso'     => date('Y-m-d',         $date_i),
			'date'         => date('D d M Y',       $date_i),
			'time'         => $time_hm,
			'length'       => $len,
			'start_iso'    => date('Y-m-d\TH:i:s',  $start_unix),
			'end_iso'      => date('Y-m-d\TH:i:s',  $end_unix),
			'status'       => $st,
			'status_label' => $status_label,
			'is_call_boss' => (int) $row->is_call_boss
		);
	}

	echo json_encode(array('shifts' => $shifts));

?>
