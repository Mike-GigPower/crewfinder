<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* THE GOAT / CrewHub — per-crew offer responsiveness in a date window.
	/*
	/* Feeds /admin/crew-stats on the CrewHub ops dashboard: per crew member,
	/* how many offers they were sent, how many they answered, how many they
	/* ignored, and how fast they answered.
	/*
	/* Every figure is anchored to a TIMESTAMP, never to live status, so later
	/* status churn cannot retro-edit history and the two reconciliation
	/* identities always hold:
	/*
	/*     offered   = responded + didnt_respond + pending
	/*     responded = accepted  + declined            (accepted includes no_show)
	/*
	/* phone_* sit OUTSIDE both identities — those rows were never offered
	/* through the system at all.
	/*
	/* THREE POPULATIONS, keyed on the Part A columns:
	/*   system-offered : offered_at IS NOT NULL
	/*   timed          : offered_at + responded_at set AND responded_src IS NULL
	/*   phone          : offered_at IS NULL (row exists, never a system offer)
	/*
	/* responded_src: NULL = crew answered through the system; 'ops' = an ops
	/* user typed the resolution in (counts as responded, EXCLUDED from timing,
	/* because the duration would measure ops data-entry lag); 'phone' = an
	/* Add & Confirm, which add-call.php tags so the update trigger can clear
	/* the stamps it would otherwise inherit from the status-0 insert.
	/*
	/* REQUIRES Part A on the same environment: call_crew_map.offered_at,
	/* responded_at, responded_src, plus both triggers. Without the ALTER every
	/* request dies with "Unknown column".
	/*
	/* PHP 5.x — mysql_* only, no ?? operator, no short [] arrays.
	*/

	/*
	/* AUTH — two accepted callers, exactly as get-open-offers-bulk.php.
	/*
	/* goat_can_read_all() alone is NOT enough: the portal is a Supabase Edge
	/* Function with no SmartStaff session, so a session-only gate locks out the
	/* only real caller — and it would still pass a browser test, because a
	/* browser HAS a session. The service-key branch must be checked separately.
	*/

	$goat_key = isset($_SERVER['HTTP_X_GOAT_SERVICE_KEY'])
	          ? $_SERVER['HTTP_X_GOAT_SERVICE_KEY'] : '';

	if (!goat_service_key_ok($goat_key) && !goat_can_read_all())
	{
		http_response_code(403);
		die('{"error":"Service key or Admin/Leadership session required"}');
	}

	/*
	/* THE CUTOVER CLAMP — do not remove.
	/*
	/* Timing collection began the moment the triggers went live on prod. Every
	/* one of the 248,410 rows that pre-dates it has offered_at NULL — and
	/* "offered_at IS NULL" is the exact test for a phone entry. So without a
	/* clamp, every confirmed row from the days between the created_at migration
	/* and the trigger cutover would be reported as a phone confirmation:
	/* silently, plausibly, and wrongly.
	/*
	/* Rows older than the created_at migration have created_at NULL too and
	/* fall out of the window on their own, so the damage is bounded — but real.
	/*
	/* The clamp is applied to the WINDOW, not to created_at directly: a row
	/* created before the cutover but RE-OFFERED after it has a post-cutover
	/* offered_at, is fully classifiable, and is correctly included.
	*/

	$CUTOVER = '2026-07-27 23:44:52';

	$cutover_ts = strtotime($CUTOVER);

	/*
	/* validate input
	/*
	/* start : YYYY-MM-DD, defaults to today - 90 days
	/* end   : YYYY-MM-DD, defaults to today. INCLUSIVE of that whole day.
	/*
	/* The window is on when the OFFER was made, not on the call date — this is
	/* a report about responsiveness over a period of activity, not about a
	/* period of work.
	*/

	$start_raw = isset($_GET['start']) ? $_GET['start'] : '';
	$end_raw   = isset($_GET['end'])   ? $_GET['end']   : '';

	if ($start_raw === '')
		$start_raw = date('Y-m-d', time() - (90 * 86400));

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
		$end_raw = date('Y-m-d');

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

	/* cap the span to protect the DB */

	if (($end_ts - $start_ts) > (400 * 86400))
	{
		http_response_code(400);
		die('{"error":"window exceeds 400 days"}');
	}

	/*
	/* effective window: [eff_start, end_excl)
	/*
	/* eff_start is the later of the requested start and the cutover. end_excl
	/* is the day after end, so the whole end day is included.
	*/

	$eff_start_ts = $start_ts;

	if ($cutover_ts !== false && $eff_start_ts < $cutover_ts)
		$eff_start_ts = $cutover_ts;

	$end_excl_ts = $end_ts + 86400;

	/* Built from validated integers via date(), so these are safe to
	/* interpolate — there is no user string reaching the SQL. */

	$sql_start = date('Y-m-d H:i:s', $eff_start_ts);
	$sql_end   = date('Y-m-d H:i:s', $end_excl_ts);

    $window = array(
		'requested_start' => $start_raw,
		'start'           => date('Y-m-d', $eff_start_ts),
		'end'             => $end_raw,
		'clamped'         => ($eff_start_ts > $start_ts ? true : false),
	);

	/* Window sits entirely before collection began — return the envelope with
	/* an empty crew list rather than an error. The page shows "collection began
	/* on <cutover>", which is the honest answer, not a failure. */

	if ($eff_start_ts >= $end_excl_ts)
	{
		echo json_encode(array(
			'generated_at' => date('Y-m-d\TH:i:s'),
			'cutover'      => str_replace(' ', 'T', $CUTOVER),
			'window'       => $window,
			'crew'         => array(),
		));
		exit;
	}

	/*
	/* PASS 1 — everything computable from call_crew_map alone.
	/*
	/* The window filter is deliberately an OR of two sargable range tests
	/* rather than COALESCE(offered_at, created_at): the COALESCE form is not
	/* sargable and full-scans ~248k MyISAM rows, which is how get-calls-bulk.php
	/* earned its 20-second timeout. Both branches are backed by the Part A
	/* indexes (idx_ccm_offered_at, idx_ccm_created_at).
	/*
	/* usergroupID = 3 keeps admins, contacts and portal accounts out of a crew
	/* report. Inactive crew are deliberately NOT filtered — a deactivated crew
	/* member's history is still history.
	/*
	/* 'unclassified' should always be 0. It counts rows that responded (so they
	/* are inside `responded`) but whose current status is none of 5/6/7/8, which
	/* would break the second identity. The only known route is an ops user
	/* setting an already-answered row back to status 1; the update trigger's
	/* re-offer arm only resets on status 0. Surfaced rather than hidden so it
	/* cannot rot silently.
	*/

	$timed_when = "ccm.offered_at IS NOT NULL AND ccm.responded_at IS NOT NULL AND ccm.responded_src IS NULL";

	$sql1 = "
		SELECT
			ccm.userID  AS user_id,
			u.ein       AS ein,
			u.firstname AS crew_fn,
			u.lastname  AS crew_ln,

			SUM(ccm.offered_at IS NOT NULL)                                   AS offered,
			SUM(ccm.offered_at IS NOT NULL AND ccm.responded_at IS NOT NULL)  AS responded,

			SUM(ccm.offered_at IS NOT NULL AND ccm.responded_at IS NOT NULL AND ccm.status IN (5,7,8))     AS accepted,
			SUM(ccm.offered_at IS NOT NULL AND ccm.responded_at IS NOT NULL AND ccm.status = 6)            AS declined,
			SUM(ccm.offered_at IS NOT NULL AND ccm.responded_at IS NOT NULL AND ccm.status = 8)            AS no_show,
			SUM(ccm.offered_at IS NOT NULL AND ccm.responded_at IS NOT NULL AND ccm.status NOT IN (5,6,7,8)) AS unclassified,

			SUM(ccm.offered_at IS NULL AND ccm.status IN (5,7,8)) AS phone_accepted,
			SUM(ccm.offered_at IS NULL AND ccm.status = 6)        AS phone_declined,

			MIN(CASE WHEN $timed_when THEN TIMESTAMPDIFF(SECOND, ccm.offered_at, ccm.responded_at) END) AS min_secs,
			MAX(CASE WHEN $timed_when THEN TIMESTAMPDIFF(SECOND, ccm.offered_at, ccm.responded_at) END) AS max_secs,
			AVG(CASE WHEN $timed_when THEN TIMESTAMPDIFF(SECOND, ccm.offered_at, ccm.responded_at) END) AS avg_secs,
			SUM($timed_when)                                                                            AS timed_sample

		FROM call_crew_map ccm
		INNER JOIN users u ON u.id = ccm.userID
		WHERE u.usergroupID = 3
		  AND (
		        (ccm.offered_at  >= '$sql_start' AND ccm.offered_at  < '$sql_end')
		     OR (ccm.offered_at IS NULL
		         AND ccm.created_at >= '$sql_start' AND ccm.created_at < '$sql_end')
		      )
		GROUP BY ccm.userID, u.ein, u.firstname, u.lastname
	";

	$res1 = mysql_query($sql1);

	if ($res1 === false)
	{
		http_response_code(500);
		die('{"error":"stats query failed: ' . addslashes(mysql_error()) . '"}');
	}

	$crew = array();

	while ($row = mysql_fetch_object($res1))
	{
		$uid = (int) $row->user_id;

		/* "Lastname, Firstname" — same construction as list-crew-bulk.php and
		/* get-open-offers-bulk.php, so the portal gets an identical display
		/* string from every endpoint. users has no name column. Names are
		/* HTML-encoded in the DB and passed through raw; the portal decodes. */

		$crew_name = trim($row->crew_ln);
		if (strlen(trim($row->crew_fn)) > 0)
		{
			if (strlen($crew_name) > 0)
				$crew_name .= ', ';
			$crew_name .= trim($row->crew_fn);
		}

		/* mysql_* returns every column as a string, including the SUM()s and
		/* the AVG(). Cast explicitly — an uncast "0" is truthy in PHP. */

		$timed = (int) $row->timed_sample;

		$crew[$uid] = array(
			'user_id'        => $uid,
			'ein'            => ($row->ein === null ? null : (string) $row->ein),
			'name'           => $crew_name,

			'offered'        => (int) $row->offered,
			'responded'      => (int) $row->responded,
			'didnt_respond'  => 0,
			'pending'        => 0,

			'accepted'       => (int) $row->accepted,
			'declined'       => (int) $row->declined,
			'no_show'        => (int) $row->no_show,

			'phone_accepted' => (int) $row->phone_accepted,
			'phone_declined' => (int) $row->phone_declined,

			'response_rate'  => null,

			'respond_seconds' => array(
				'min' => ($row->min_secs === null ? null : (int) $row->min_secs),
				'max' => ($row->max_secs === null ? null : (int) $row->max_secs),
				/* AVG comes back as a decimal string; round to a whole second
				/* server-side so the portal never has to. */
				'avg' => ($row->avg_secs === null ? null : (int) round((float) $row->avg_secs)),
			),

			'timed_sample'   => $timed,

			/* always 0 — see the note above pass 1 */
			'unclassified'   => (int) $row->unclassified,
		);
	}

	mysql_free_result($res1);

	/*
	/* PASS 2 — didnt_respond / pending. Needs the calls join for the start
	/* instant, so it is kept separate: forcing it into pass 1 would join
	/* call_crew_map to calls across the whole window for every metric.
	/*
	/* start_date is a unix ts at local midnight and start_time is a TIME, so
	/* start_date + TIME_TO_SEC(start_time) is the start instant — the same
	/* composition get-calls-bulk.php and get-open-offers-bulk.php use, so the
	/* three endpoints can never disagree about when a call began.
	/*
	/* "now" is passed in from PHP rather than using UNIX_TIMESTAMP(NOW()), so
	/* the past/future split cannot drift if MySQL's session timezone ever
	/* disagrees with PHP's.
	/*
	/* COALESCE on TIME_TO_SEC: a NULL start_time would make the comparison NULL
	/* and the row would silently vanish from BOTH buckets, breaking the first
	/* identity with no error.
	/*
	/* Hidden bookings are excluded. Completed bookings are NOT — unlike the
	/* open-offers endpoint, Completed is the normal end state for past work and
	/* filtering it would discard nearly all history.
	*/

	$now_i = (int) time();

	$sql2 = "
		SELECT
			ccm.userID AS user_id,
			SUM( (c.start_date + COALESCE(TIME_TO_SEC(c.start_time), 0)) <  $now_i ) AS didnt_respond,
			SUM( (c.start_date + COALESCE(TIME_TO_SEC(c.start_time), 0)) >= $now_i ) AS pending
		FROM call_crew_map ccm
		INNER JOIN calls c    ON c.id = ccm.callID
		LEFT  JOIN bookings b ON b.id = c.bookingID
		WHERE ccm.offered_at IS NOT NULL
		  AND ccm.responded_at IS NULL
		  AND ccm.offered_at >= '$sql_start'
		  AND ccm.offered_at <  '$sql_end'
		  AND (b.hidden IS NULL OR b.hidden = 0)
		GROUP BY ccm.userID
	";

	$res2 = mysql_query($sql2);

	if ($res2 === false)
	{
		http_response_code(500);
		die('{"error":"pending query failed: ' . addslashes(mysql_error()) . '"}');
	}

	while ($row = mysql_fetch_object($res2))
	{
		$uid = (int) $row->user_id;

		/* Merge INTO pass 1 only. A userID here that is absent from pass 1 is
		/* not crew (pass 2 does not filter usergroupID) and is dropped. */

		if (!isset($crew[$uid]))
			continue;

		$crew[$uid]['didnt_respond'] = (int) $row->didnt_respond;
		$crew[$uid]['pending']       = (int) $row->pending;
	}

	mysql_free_result($res2);

	/*
	/* response_rate — responded / (responded + didnt_respond).
	/*
	/* pending is deliberately OUT of the denominator: a live offer for a future
	/* call is not a non-response, and counting it would penalise a crew member
	/* for an offer they still have time to answer.
	/*
	/* NULL, not 0, when the denominator is empty — "no data" and "never
	/* responds" must not render the same.
	*/

	$out = array();

	foreach ($crew as $uid => $c)
	{
		$denom = $c['responded'] + $c['didnt_respond'];

		if ($denom > 0)
			$c['response_rate'] = (int) round(100 * $c['responded'] / $denom);
		else
			$c['response_rate'] = null;

		if ($c['timed_sample'] === 0)
		{
			$c['respond_seconds'] = array('min' => null, 'max' => null, 'avg' => null);
		}

		$out[] = $c;
	}

	echo json_encode(array(
		'generated_at' => date('Y-m-d\TH:i:s'),
		'cutover'      => str_replace(' ', 'T', $CUTOVER),
		'window'       => $window,
		'crew'         => $out,
	));

?>
