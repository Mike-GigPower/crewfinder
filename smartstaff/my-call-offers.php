<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SELF endpoint — the acting crew member's OFFERED calls: the ones still
	/* awaiting a response, call_crew_map.status IN (0,1) (Unconfirmed /
	/* SMS Sent). Mirrors the crew dashboard read (dash.php) but offered-only.
	/*
	/* Offered calls are NOT in the `calendars` table yet — a calendar row is
	/* only created on confirm (addToCalendar) — so this is a separate read from
	/* my-shifts.php. Each call's wall-clock start/end is resolved from
	/* calls.start_date (unix date) + start_time (time) + est_length (hours),
	/* and emitted in the same ISO shape as my-shifts.php so the portal renders
	/* offers with the same formatter.
	*/

	$userID = (int) goat_acting_user_id();

	if ($userID <= 0)
	{
		http_response_code(403);
		die('{"error":"not authorised"}');
	}

	$sql = "
		SELECT
			calls.id          AS call_id,
			calls.bookingID   AS booking_id,
			calls.call_name   AS call_name,
			calls.start_date  AS start_date,
			calls.start_time  AS start_time,
			calls.est_length  AS est_length,
			calls.required    AS required,
			call_crew_map.status       AS status,
			call_crew_map.is_call_boss AS is_call_boss,
			bookings.name     AS booking_name,
			venues.venue      AS venue_name
		FROM call_crew_map
		LEFT JOIN calls    ON call_crew_map.callID  = calls.id
		LEFT JOIN bookings ON calls.bookingID       = bookings.id
		LEFT JOIN venues   ON bookings.venueID      = venues.id
		WHERE call_crew_map.userID = " . $userID . "
		  AND call_crew_map.status IN (0, 1)
		  AND calls.id IS NOT NULL
		ORDER BY calls.start_date ASC, calls.start_time ASC
	";

	$res = mysql_query($sql);

	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"offers query failed: ' . addslashes(mysql_error()) . '"}');
	}

	$offers = array();

	while ($row = mysql_fetch_object($res))
	{
		$dateStr    = date('Y-m-d', (int) $row->start_date);
		$startUnix  = strtotime($dateStr . ' ' . $row->start_time);
		$lengthSecs = (int) round(((double) $row->est_length) * 3600);
		$endUnix    = $startUnix + $lengthSecs;

		$offers[] = array(
			'call_id'      => (int) $row->call_id,
			'booking_id'   => (int) $row->booking_id,
			'call_name'    => $row->call_name,
			'booking_name' => $row->booking_name,
			'venue'        => $row->venue_name,
			'start'        => date('Y-m-d\TH:i:s', $startUnix),
			'end'          => date('Y-m-d\TH:i:s', $endUnix),
			'est_length'   => (double) $row->est_length,
			'required'     => (int) $row->required,
			'status'       => (int) $row->status,
			'is_call_boss' => (int) $row->is_call_boss
		);
	}

	echo json_encode(array('offers' => $offers));

?>
