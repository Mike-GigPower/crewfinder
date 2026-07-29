<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SELF endpoint — returns the logged-in user's OWN past confirmed shifts.
	/* Scoped to goat_acting_user_id(), so a crew member can only ever read their
	/* own history. No admin gate. Same dual-trust pattern as my-shifts.php
	/* (session OR X-Goat-Service-Key), so no cohort.php change is needed.
	/*
	/* WHY THIS DOES NOT REUSE my-shifts.php
	/*
	/*  1. my-shifts.php caps its window at 120 days. A full history is 16+ years,
	/*     which would be ~50 sequential requests (SmartStaff holds a PHP file
	/*     lock, so they cannot be parallelised).
	/*  2. my-shifts.php reads the `calendars` table and treats a type-2 row as
	/*     the shift. A calendar row is a derived artefact that can be missing or
	/*     purged for old calls. call_crew_map is the actual record of "this
	/*     person was on this call", so history reads that directly.
	/*
	/* WHAT COUNTS AS HISTORY
	/*
	/*  status = 5 (Confirmed) only. Verified against 16 years of data: worked
	/*  shifts are never moved off status 5 once done, so 5 is a safe test for
	/*  the past as well as the future. Declined (6), no-show (8) and unanswered
	/*  (0/1) rows are deliberately excluded — this page answers "what have I
	/*  worked", not "what was I offered".
	/*
	/*  Only calls that STARTED BEFORE TODAY are returned. Using the start date
	/*  (not the end) means an overnight load-out that began 23:00 yesterday and
	/*  finished 06:00 today counts as yesterday's shift, which is how crew think
	/*  about it. Today's shifts stay on /shifts until tomorrow.
	/*
	/* PERFORMANCE
	/*
	/*  The WHERE leads on ccm.userID, which is the first column of the existing
	/*  (userID, callID) index — so this is an index read of roughly 70 rows for a
	/*  typical crew member and ~4,600 for the heaviest. The joins out to calls,
	/*  bookings and venues are all primary-key lookups. No new index required,
	/*  which matters: call_crew_map is MyISAM with ~246k rows and an ALTER would
	/*  lock it.
	/*
	/* TIMES
	/*
	/*  calls.start_date is a real unix timestamp; calls.start_time is a separate
	/*  wall-clock string. They are combined the same way my-shifts.php combines
	/*  the prev_start_* columns. `start` is emitted as a wall-clock ISO string
	/*  with NO timezone, matching every other crew endpoint — the portal must
	/*  never build a Date() from it.
	*/


	$userID = (int) goat_acting_user_id();

	/*
	/* Optional safety cap. The portal does not pass this; it exists so a future
	/* caller cannot accidentally pull an unbounded result set. We select one row
	/* MORE than the cap so we can honestly report whether anything was cut off.
	*/

	$limit = 6000;

	if (isset($_GET['limit']))
	{
		$requested = (int) $_GET['limit'];

		if ($requested > 0 && $requested <= 10000)
			$limit = $requested;
	}

	$fetch_limit = $limit + 1;

	/* midnight this morning, server time — the boundary between "history" and
	   "upcoming" */

	$today_ts = (int) strtotime('today 00:00:00');

	$sql = "
		SELECT
			ccm.is_call_boss AS is_call_boss,
			c.id             AS call_id,
			c.bookingID      AS booking_id,
			c.call_name      AS call_name,
			c.start_date     AS start_date,
			c.start_time     AS start_time,
			c.est_length     AS est_length,
			b.name           AS booking_name,
			v.venue          AS venue_name,
			v.suburb         AS venue_suburb
		FROM call_crew_map ccm
		JOIN calls c ON c.id = ccm.callID
		LEFT JOIN bookings b ON b.id = c.bookingID
		LEFT JOIN venues   v ON v.id = b.venueID
		WHERE ccm.userID = $userID
		  AND ccm.status = 5
		  AND c.start_date < $today_ts
		ORDER BY c.start_date DESC, c.start_time DESC
		LIMIT $fetch_limit
	";

	$result = mysql_query($sql);

	if ($result === false)
	{
		http_response_code(500);
		die('{"error":"query failed: ' . addslashes(mysql_error()) . '"}');
	}

	$shifts    = array();
	$seen      = 0;
	$truncated = false;

	while ($row = mysql_fetch_object($result))
	{
		$seen++;

		/* the extra row we deliberately over-selected — stop, and say so */

		if ($seen > $limit)
		{
			$truncated = true;
			break;
		}

		$start_ts = strtotime(date('Y-m-d', (int) $row->start_date) . ' ' . $row->start_time);

		/*
		/* html_entity_decode is a no-op on clean data, but booking and venue
		/* names entered through SmartStaff's admin UI can carry encoded
		/* entities (a "&" saved as "&amp;"). React escapes on render, so an
		/* undecoded entity would display literally as "&amp;".
		*/

		$shifts[] = array(
			'call_id'      => (int) $row->call_id,
			'booking_id'   => (int) $row->booking_id,
			'call_name'    => html_entity_decode((string) $row->call_name,    ENT_QUOTES, 'UTF-8'),
			'booking_name' => html_entity_decode((string) $row->booking_name, ENT_QUOTES, 'UTF-8'),
			'venue'        => html_entity_decode((string) $row->venue_name,   ENT_QUOTES, 'UTF-8'),
			'suburb'       => html_entity_decode((string) $row->venue_suburb, ENT_QUOTES, 'UTF-8'),
			'start'        => date('Y-m-d\TH:i:s', $start_ts),
			'est_length'   => (double) $row->est_length,
			'is_call_boss' => ((int) $row->is_call_boss === 1) ? 1 : 0,
		);
	}

	echo json_encode(array(
		'generated_at' => date('Y-m-d\TH:i:s'),
		'count'        => count($shifts),
		'truncated'    => $truncated,
		'shifts'       => $shifts,
	));

?>
