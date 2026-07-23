<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* THE GOAT / CrewHub — ALL-crew open (unanswered) offers in a date window.
	/*
	/* Feeds the CrewHub ops dashboard (/admin/dashboard): open offers
	/* outstanding, their age, and which are held by crew with no push
	/* subscription. Returns ROWS, not totals, so the portal can slice by venue,
	/* booking, age or person without another PHP change.
	/*
	/* "Open offer" = a call_crew_map row at status 0 (Unconfirmed) or 1 (SMS
	/* Sent). Row creation IS the offer event: SmartStaff's add-call.php calls
	/* $sss->addToCall(), which inserts the row at status 0; the SMS flow only
	/* flips 0 -> 1. action=confcrew inserts the same way then immediately
	/* UPDATEs to status 5, so those rows are correctly excluded here — they
	/* were never offered.
	/*
	/* This returns other people's rows, so it must NOT use the self-scoping
	/* goat_acting_user_id() model of the my-*.php endpoints.
	/*
	/* REQUIRES call_crew_map.created_at (see the open-offers handover, Part A).
	/* Deploy this endpoint AFTER that ALTER has run on the same environment, or
	/* every request fails with "Unknown column".
	/*
	/* PHP 5.x — mysql_* only, no ?? operator, no short [] arrays.
	*/

	/*
	/* AUTH — two accepted callers:
	/*   1. the CrewHub portal, presenting X-Goat-Service-Key. This is the
	/*      primary caller: it is a Supabase Edge Function and has no SmartStaff
	/*      session, so a session-only gate would lock it out entirely.
	/*   2. a logged-in admin / leadership / operations session, so the endpoint
	/*      can be opened in a browser for testing.
	/* Both are already trusted with exactly this class of data by
	/* get-calls-bulk.php, so this widens nothing.
	*/

	$goat_key = isset($_SERVER['HTTP_X_GOAT_SERVICE_KEY'])
	          ? $_SERVER['HTTP_X_GOAT_SERVICE_KEY'] : '';

	if (!goat_service_key_ok($goat_key) && !goat_can_read_all())
	{
		http_response_code(403);
		die('{"error":"Service key or Admin/Leadership session required"}');
	}

	/*
	/* validate input
	/*
	/* start : YYYY-MM-DD, defaults to today (Melbourne — global.php sets the tz).
	/* end   : YYYY-MM-DD, defaults to start + 28 days. INCLUSIVE of that whole
	/*         day.
	/*
	/* The window is on the CALL's date, not on when the offer was made: an
	/* unanswered offer for a call that has already run is dead, not
	/* outstanding. The portal may still pass a past start to review history, so
	/* the params are honoured exactly as given.
	/*
	/* NOTE: get-calls-bulk.php uses a half-open window (start <= d < end). This
	/* endpoint's contract says "latest call start date", so a call ON the end
	/* date must be included — hence the +86400 below. Deliberate difference.
	*/

	$start_raw = isset($_GET['start']) ? $_GET['start'] : '';
	$end_raw   = isset($_GET['end'])   ? $_GET['end']   : '';

	if ($start_raw === '')
		$start_raw = date('Y-m-d');

	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_raw))
	{
		http_response_code(400);
		die('{"error":"start must be YYYY-MM-DD"}');
	}

	$start_ts = strtotime($start_raw . ' 00:00:00');

	if ($start_ts === false)
	{
		http_response_code(400);
		die('{"error":"invalid start date"}');
	}

	if ($end_raw === '')
		$end_raw = date('Y-m-d', $start_ts + (28 * 86400));

	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_raw))
	{
		http_response_code(400);
		die('{"error":"end must be YYYY-MM-DD"}');
	}

	$end_ts = strtotime($end_raw . ' 00:00:00');

	if ($end_ts === false || $end_ts < $start_ts)
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
	$end_i   = (int) $end_ts + 86400;        /* inclusive of the whole end day */

	/*
	/* process the request
	/*
	/* One row per unanswered crew-member-and-call.
	/*
	/* Driving table is calls, NOT call_crew_map: the date window is by far the
	/* most selective filter, and calls is ~38k rows against call_crew_map's
	/* ~246k. idx_ccm_call_status (callID, status) then backs the join + status
	/* filter. Without that index this scans every crew-map row per request.
	/*
	/* Schema notes (verified against the live DB):
	/*   calls          : id, bookingID, call_name, start_date (INT — unix ts at
	/*                    local midnight), start_time (TIME), est_length (DOUBLE
	/*                    hours), required
	/*   bookings       : id, name, venueID, hidden, status (1 = Completed)
	/*   venues         : id, venue
	/*   users          : id, firstname, lastname, ein   (there is NO name column)
	/*   call_crew_map  : crewmapID, userID, callID, status, sms_fail (int NULL),
	/*                    is_call_boss (binary(50)), created_at (added — Part A)
	/*
	/* Hidden and Completed bookings are excluded to match get-calls-bulk.php and
	/* the admin /bookings view — an offer on a closed job is not actionable.
	/*
	/* Inactive crew are deliberately NOT filtered out: an outstanding offer held
	/* by a deactivated crew member is a real problem to surface, not hide.
	/*
	/* Only integers are interpolated into the SQL (every input passed the
	/* YYYY-MM-DD regex, strtotime and an (int) cast), so there is no string
	/* concatenation needing mysql_real_escape_string here.
	*/

	$sql = "
		SELECT
			ccm.crewmapID    AS crewmap_id,
			ccm.callID       AS call_id,
			ccm.userID       AS user_id,
			ccm.status       AS status,
			ccm.created_at   AS created_at,
			ccm.sms_fail     AS sms_fail,
			ccm.is_call_boss AS is_call_boss,
			u.ein            AS ein,
			u.firstname      AS crew_fn,
			u.lastname       AS crew_ln,
			c.bookingID      AS booking_id,
			c.call_name      AS call_name,
			c.start_date     AS start_date,
			c.start_time     AS start_time,
			c.est_length     AS est_length,
			c.required       AS required,
			b.name           AS booking_name,
			v.venue          AS venue_name
		FROM calls c
		INNER JOIN call_crew_map ccm ON ccm.callID = c.id
		INNER JOIN users         u   ON u.id       = ccm.userID
		LEFT  JOIN bookings      b   ON b.id       = c.bookingID
		LEFT  JOIN venues        v   ON v.id       = b.venueID
		WHERE ccm.status IN (0, 1)
		  AND c.start_date >= $start_i
		  AND c.start_date <  $end_i
		  AND (b.hidden IS NULL OR b.hidden = 0)
		  AND b.status <> 1   /* exclude Completed bookings — match the admin /bookings view */
		ORDER BY c.start_date ASC, c.start_time ASC
	";

	$result = mysql_query($sql);

	if ($result === false)
	{
		http_response_code(500);
		die('{"error":"query failed: ' . addslashes(mysql_error()) . '"}');
	}

	$offers = array();

	while ($row = mysql_fetch_object($result))
	{
		/* start/end built exactly as get-calls-bulk.php builds them, so the two
		/* endpoints can never disagree about a call's wall-clock time.
		/* start_date is already a unix ts at local midnight and global.php has
		/* set the Melbourne tz, so plain date() is correct — no DateTimeZone. */

		$date_i  = (int) $row->start_date;
		$time_hm = substr($row->start_time, 0, 5);           /* HH:MM */
		if (!preg_match('/^\d{2}:\d{2}$/', $time_hm)) $time_hm = '00:00';

		list($hh, $mm) = array_map('intval', explode(':', $time_hm));
		$start_unix = $date_i + ($hh * 3600) + ($mm * 60);
		$len        = (float) $row->est_length;
		$end_unix   = $start_unix + (int) round($len * 3600);

		/* created_at is a TIMESTAMP ("YYYY-MM-DD HH:MM:SS") or NULL for rows
		/* that pre-date the column. NULL passes through as null so the dashboard
		/* shows "age unknown" rather than pretending the offer is brand new. */

		$created = $row->created_at;
		if ($created === null || $created === '' || $created === '0000-00-00 00:00:00')
		{
			$created_iso = null;
		}
		else
		{
			$created_iso = str_replace(' ', 'T', $created);
		}

		/* "Lastname, Firstname" — same construction as list-crew-bulk.php, so
		/* the portal gets the identical display string it already receives from
		/* the roster endpoint. users has no name column. */

		$crew_name = trim($row->crew_ln);
		if (strlen(trim($row->crew_fn)) > 0)
		{
			if (strlen($crew_name) > 0)
				$crew_name .= ', ';
			$crew_name .= trim($row->crew_fn);
		}

		$offers[] = array(
			'crewmap_id'   => (int) $row->crewmap_id,
			'call_id'      => (int) $row->call_id,
			'booking_id'   => (int) $row->booking_id,
			'user_id'      => (int) $row->user_id,
			/* string on purpose — join key into the portal's Supabase
			/* push_subscriptions table, which is keyed by EIN as text. */
			'ein'          => ($row->ein === null ? null : (string) $row->ein),
			'name'         => $crew_name,
			'call_name'    => $row->call_name,
			'booking_name' => $row->booking_name,
			'venue'        => $row->venue_name,
			'start'        => date('Y-m-d\TH:i:s', $start_unix),
			'end_iso'      => date('Y-m-d\TH:i:s', $end_unix),
			'date_iso'     => date('Y-m-d',        $date_i),
			'time'         => $time_hm,
			'length'       => $len,
			'required'     => (int) $row->required,
			/* 0 = Unconfirmed (never messaged), 1 = SMS Sent (messaged, no
			/* reply). Passed through raw — the dashboard reports them
			/* separately; they call for different operational actions. */
			'status'       => (int) $row->status,
			'created_at'   => $created_iso,
			/* NULL preserved, never cast to 0 — "no SMS failure recorded" must
			/* not blend into "SMS failed". */
			'sms_fail'     => ($row->sms_fail === null ? null : (int) $row->sms_fail),
			/* binary(50) in the schema, so cast rather than compare as string */
			'is_call_boss' => ((int) $row->is_call_boss === 1 ? 1 : 0),
		);
	}

	echo json_encode(array(
		'generated_at' => date('Y-m-d\TH:i:s'),
		'window'       => array('start' => $start_raw, 'end' => $end_raw),
		'offers'       => $offers,
	));

?>
