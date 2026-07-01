<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* admin OR leadership — returns the all-crew call list (the Schedule view).
	/* Leadership is read-only; this is a read endpoint, so it is permitted.
	/* This exists so the Schedule no longer depends on scraping the admin
	/* /bookings pages (which a leadership EIN session cannot read).
	*/

	if (!goat_can_read_all())
	{
		http_response_code(403);
		die('{"error":"Admin or Leadership only"}');
	}

	/*
	/* validate input
	/*
	/* start, end:  YYYY-MM-DD (inclusive start, exclusive end)
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

	$start_i = (int) $start_ts;
	$end_i   = (int) $end_ts;

	/*
	/* process the request
	/*
	/* One query: every call whose date falls in the window, joined to its
	/* booking, the booking's venue, and the booking's onsite contact.
	/*
	/* Schema notes (verified against live DB):
	/*   calls    : id, bookingID, call_name, start_time (TIME), est_length
	/*              (DOUBLE hours), start_date (INT — unix ts at local midnight),
	/*              required, booked, notes
	/*   bookings : id, name, venueID, onsiteUserID, hidden
	/*   venues   : id, venue
	/*   users    : id, firstname, lastname  (onsite contact)
	/*
	/* ASSUMPTION: calls.start_date is a unix timestamp. If the window filter
	/* returns nothing / wrong dates, this is the line to revisit.
	*/

	$sql = "
		SELECT
			c.id          AS call_id,
			c.bookingID   AS booking_id,
			c.call_name   AS call_name,
			c.start_date  AS start_date,
			c.start_time  AS start_time,
			c.est_length  AS est_length,
			c.required    AS required,
			c.link_group  AS link_group,
			/* calls.booked is not maintained live (came back 0 for every call);
			/* the live confirmed count is call_crew_map status=5. Computed with a
			/* GROUP BY join — a single pass over the windowed calls' crew rows. A
			/* correlated per-row subquery here timed the endpoint out (read>20s). */
			COUNT(CASE WHEN ccm.status = 5 THEN 1 END) AS booked,
			c.notes       AS notes,
			b.name        AS booking_name,
			v.venue       AS venue_name,
			ou.firstname  AS contact_fn,
			ou.lastname   AS contact_ln
		FROM calls c
		LEFT JOIN bookings      b   ON b.id       = c.bookingID
		LEFT JOIN venues        v   ON v.id       = b.venueID
		LEFT JOIN users         ou  ON ou.id      = b.onsiteUserID
		LEFT JOIN call_crew_map ccm ON ccm.callID = c.id
		WHERE c.start_date >= $start_i
		  AND c.start_date <  $end_i
		  AND (b.hidden IS NULL OR b.hidden = 0)
		  AND b.status <> 1   /* exclude Completed bookings — match the admin /bookings view */
		GROUP BY c.id
		ORDER BY c.start_date ASC, c.start_time ASC
	";

	$result = mysql_query($sql);

	if ($result === false)
	{
		http_response_code(500);
		die('{"error":"query failed: ' . addslashes(mysql_error()) . '"}');
	}

	$calls = array();

	while ($row = mysql_fetch_object($result))
	{
		$date_i   = (int) $row->start_date;
		$time_hm  = substr($row->start_time, 0, 5);          /* HH:MM */
		if (!preg_match('/^\d{2}:\d{2}$/', $time_hm)) $time_hm = '00:00';

		list($hh, $mm) = array_map('intval', explode(':', $time_hm));
		$start_unix = $date_i + ($hh * 3600) + ($mm * 60);
		$len        = (float) $row->est_length;
		$end_unix   = $start_unix + (int) round($len * 3600);

		$required = (int) $row->required;
		$booked   = (int) $row->booked;

		$contact = trim(trim($row->contact_fn) . ' ' . trim($row->contact_ln));

		$calls[] = array(
			'booking_id'   => (int) $row->booking_id,
			'call_id'      => (int) $row->call_id,
			'booking_name' => $row->booking_name,
			'venue'        => $row->venue_name,
			'contact'      => $contact,
			'call_name'    => $row->call_name,
			'date'         => date('d/m/y',        $date_i),
			'date_iso'     => date('Y-m-d',        $date_i),
			'time'         => $time_hm,
			'length'       => $len,
			'start_iso'    => date('Y-m-d\TH:i:s', $start_unix),
			'end_iso'      => date('Y-m-d\TH:i:s', $end_unix),
			'booked'       => $booked,
			'required'     => $required,
			'link_group'   => ($row->link_group === null ? null : (int) $row->link_group),
			'full'         => ($booked >= $required && $required > 0),
			'notes'        => $row->notes,
		);
	}

	echo json_encode(array(
		'window' => array('start' => $start_raw, 'end' => $end_raw),
		'calls'  => $calls,
	));

?>
