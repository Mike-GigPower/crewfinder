<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');
	include('call-graph.php');
	include_once('resolve-call-contact.php');

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
	/*
	/* STARTED OFFERS ARE HIDDEN: a call whose start time has already passed can
	/* no longer be accepted (see respond-to-call.php), so we drop it here — it
	/* simply stops appearing on the crew member's dashboard. For linked calls
	/* (a "package" answered as one unit), if ANY call in the group has started
	/* the WHOLE group is dropped, so we never show a half-package that can't be
	/* accepted. "Has it started?" is measured against Australia/Melbourne time
	/* so it is correct regardless of the server's own timezone.
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
			calls.link_group  AS link_group,
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

	/* Melbourne "now" for the started-check. time() is an absolute epoch, and
	/* $melTz resolves each call's wall-clock start to its true absolute instant,
	/* so the comparison holds whatever timezone this server runs in. */

	$melTz = new DateTimeZone('Australia/Melbourne');
	$now   = time();

	/*
	/* Pass 1 — read every offered row, compute its display start/end (wall-clock,
	/* unchanged) AND a separate absolute "has it started?" flag. Record which
	/* linked groups contain an already-started call so the whole package can be
	/* dropped together in pass 2.
	*/

	$rows          = array();
	$startedGroups = array();
	$startedCalls  = array();

	while ($row = mysql_fetch_object($res))
	{
		$dateStr    = date('Y-m-d', (int) $row->start_date);

		/* display start/end — round-trips the wall-clock regardless of server tz */
		$startUnix  = strtotime($dateStr . ' ' . $row->start_time);
		$lengthSecs = (int) round(((double) $row->est_length) * 3600);
		$endUnix    = $startUnix + $lengthSecs;

		/* gate — absolute instant of the Melbourne wall-clock start */
		$startTs = false;
		try {
			$dt = new DateTime($dateStr . ' ' . $row->start_time, $melTz);
			$startTs = $dt->getTimestamp();
		} catch (Exception $e) {
			$startTs = false;
		}
		$hasStarted = ($startTs !== false && $startTs <= $now);

		$lg = ($row->link_group === null ? null : (int) $row->link_group);

		/* A started call poisons its whole package — the crew member can no
		/* longer accept any of it, so the package is dropped as a unit. Marked
		/* by call id (not group id) because feeds have no group token. */

		if ($hasStarted)
		{
			$startedCalls[(int) $row->call_id] = true;
		}

		$rows[] = array(
			'row'        => $row,
			'startUnix'  => $startUnix,
			'endUnix'    => $endUnix,
			'lg'         => $lg,
			'hasStarted' => $hasStarted,
		);
	}

	/*
	/* Pass 2 — emit the offers that are still open: skip a call that has itself
	/* started, and skip any remaining sibling of a linked group whose other call
	/* has started.
	*/

	$offers = array();

	foreach ($rows as $r)
	{
		if ($r['hasStarted'])
		{
			continue;   /* this call has already started */
		}

		/* drop this offer if any call in its package has already started */

		$pkg     = goat_user_package($userID, (int) $r['row']->call_id);
		$poisoned = false;

		foreach ($pkg as $pc)
		{
			if (isset($startedCalls[$pc]))
			{
				$poisoned = true;
				break;
			}
		}

		if ($poisoned)
		{
			continue;
		}

		$row = $r['row'];
		$lg  = $r['lg'];

		$offers[] = array(
			'call_id'      => (int) $row->call_id,
			'booking_id'   => (int) $row->booking_id,
			'call_name'    => $row->call_name,
			'booking_name' => $row->booking_name,
			'venue'        => $row->venue_name,
			'start'        => date('Y-m-d\TH:i:s', $r['startUnix']),
			'end'          => date('Y-m-d\TH:i:s', $r['endUnix']),
			'est_length'   => (double) $row->est_length,
			'required'     => (int) $row->required,
			'link_group'   => $lg,
			'package_id'   => goat_package_id($pkg),
			'commits_to'   => goat_commits_to((int) $row->call_id),
			'declining_withdraws' => goat_declining_withdraws($userID, (int) $row->call_id),
			'status'       => (int) $row->status,
			'is_call_boss' => (int) $row->is_call_boss,
			/* contact hierarchy — everything emitted here is upcoming by
			   construction (pass 2 drops started offers and started packages) */
			'contacts'     => goat_resolve_call_contact((int) $row->call_id, $userID)
		);
	}

	echo json_encode(array('offers' => $offers));

?>
