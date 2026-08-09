<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* THE GOAT / Ops landing — ALL-crew OUTSTANDING acknowledgements in a date
	/* window. One row per crew-member-and-call who has answered nothing since
	/* either a timing change (call_change_ack) or a promotion off standby
	/* (call_promo_ack). This is the row-level detail behind the "awaiting" count
	/* get-calls-bulk.php already surfaces per call on the Schedule.
	/*
	/* THE PREDICATE IS LIFTED VERBATIM from get-calls-bulk.php (v4.28.0) — do not
	/* recompose it. The two ack tables have OPPOSITE lifecycles and the guards are
	/* load-bearing (they mirror get-booking.php exactly):
	/*   - call_change_ack : the row is DELETED on answer (respond-to-change.php),
	/*     so its mere EXISTENCE is the pending flag. Applies to confirmed (5) AND
	/*     backup (7) crew.
	/*   - call_promo_ack  : the row is KEPT for the audit trail, stamped acked_at
	/*     on answer, so the pending test is acked_at IS NULL. Confirmed (5) only.
	/* Both tables carry UNIQUE (callID, userID), so each LEFT JOIN is at most 1:1
	/* and cannot multiply the crew-map rows.
	/*
	/* SUBSUMPTION: where a crew member has BOTH a pending change and a pending
	/* promo on the same call, one row is emitted, kind = "change". Answering the
	/* timing change answers both; two rows would imply two questions when there
	/* is one.
	/*
	/* Returns ROWS plus a server-computed counts object, so THE GOAT never
	/* re-derives a boundary the server already decided (mirrors the offers lane).
	/*
	/* PHP 5.x — mysql_* only, no ?? operator, no short [] arrays.
	*/

	/*
	/* AUTH — the dual gate, copied verbatim from get-open-offers-bulk.php:
	/*   1. the CrewHub portal presenting X-Goat-Service-Key (no SmartStaff
	/*      session), and
	/*   2. a logged-in admin / leadership / operations session, for browser
	/*      testing. goat_can_read_all() covers all three read-all cohorts.
	*/

	$goat_key = isset($_SERVER['HTTP_X_GOAT_SERVICE_KEY'])
	          ? $_SERVER['HTTP_X_GOAT_SERVICE_KEY'] : '';

	if (!goat_service_key_ok($goat_key) && !goat_can_read_all())
	{
		goat_json_error(403, 'Service key or Admin/Leadership session required');
	}

	/*
	/* validate input — window handling identical to get-open-offers-bulk.php.
	/*
	/* start : YYYY-MM-DD, defaults to today (Melbourne — global.php sets the tz).
	/* end   : YYYY-MM-DD, defaults to start + 28 days, INCLUSIVE of that whole day
	/*         (the +86400 below — matching the offers endpoint, NOT the half-open
	/*         window of get-calls-bulk.php).
	/*
	/* The window is on the CALL's date: a pending ack on a call that has already
	/* run is noise, and the default start of today keeps the view forward-looking
	/* exactly as the offers endpoint does. A caller may still pass a past start to
	/* review history, so the params are honoured as given.
	*/

	$start_raw = isset($_GET['start']) ? $_GET['start'] : '';
	$end_raw   = isset($_GET['end'])   ? $_GET['end']   : '';

	if ($start_raw === '')
		$start_raw = date('Y-m-d');

	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_raw))
	{
		goat_json_error(400, 'start must be YYYY-MM-DD');
	}

	$start_ts = strtotime($start_raw . ' 00:00:00');

	if ($start_ts === false)
	{
		goat_json_error(400, 'invalid start date');
	}

	if ($end_raw === '')
		$end_raw = date('Y-m-d', $start_ts + (28 * 86400));

	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_raw))
	{
		goat_json_error(400, 'end must be YYYY-MM-DD');
	}

	$end_ts = strtotime($end_raw . ' 00:00:00');

	if ($end_ts === false || $end_ts < $start_ts)
	{
		goat_json_error(400, 'invalid date range');
	}

	/* cap the window at 120 days to protect the DB */

	if (($end_ts - $start_ts) > (120 * 86400))
	{
		goat_json_error(400, 'window exceeds 120 days');
	}

	$start_i = (int) $start_ts;
	$end_i   = (int) $end_ts + 86400;        /* inclusive of the whole end day */

	/*
	/* process the request
	/*
	/* One row per outstanding (crew-member, call). Driving table is calls so the
	/* date window — by far the most selective filter — is applied first, exactly
	/* as get-open-offers-bulk.php does. The awaiting predicate is in the WHERE so
	/* only outstanding rows come back; the LEFT JOINs on the two 1:1 ack tables
	/* carry the columns each row needs to be classified.
	/*
	/* Schema notes (verified against live source):
	/*   call_change_ack : id, callID, userID, prev_start_date (INT unix date),
	/*                     prev_start_time (VARCHAR "HH:MM:SS"), prev_est_length,
	/*                     changed_at (INT). Row PRESENCE = pending.
	/*   call_promo_ack  : id, callID, userID, promoted_at (INT unix), acked_at
	/*                     (INT NULL), acked_src. acked_at IS NULL = pending.
	/*
	/* Only integers are interpolated into the SQL (every input passed the
	/* YYYY-MM-DD regex, strtotime and an (int) cast).
	*/

	$sql = "
		SELECT
			ccm.status       AS status,
			ccm.userID       AS user_id,
			u.ein            AS ein,
			u.firstname      AS crew_fn,
			u.lastname       AS crew_ln,
			c.id             AS call_id,
			c.bookingID      AS booking_id,
			c.call_name      AS call_name,
			c.start_date     AS start_date,
			c.start_time     AS start_time,
			b.name           AS booking_name,
			v.venue          AS venue_name,
			cca.id             AS cca_id,
			cca.prev_start_date AS prev_start_date,
			cca.prev_start_time AS prev_start_time,
			cpa.id             AS cpa_id,
			cpa.acked_at       AS cpa_acked_at,
			cpa.promoted_at    AS cpa_promoted_at
		FROM calls c
		INNER JOIN call_crew_map ccm ON ccm.callID = c.id
		INNER JOIN users         u   ON u.id       = ccm.userID
		LEFT  JOIN bookings      b   ON b.id       = c.bookingID
		LEFT  JOIN venues        v   ON v.id       = b.venueID
		LEFT  JOIN call_change_ack cca ON cca.callID = ccm.callID
		                              AND cca.userID = ccm.userID
		LEFT  JOIN call_promo_ack  cpa ON cpa.callID = ccm.callID
		                              AND cpa.userID = ccm.userID
		WHERE c.start_date >= $start_i
		  AND c.start_date <  $end_i
		  AND (b.hidden IS NULL OR b.hidden = 0)
		  AND b.status <> 1   /* exclude Completed bookings — match the admin /bookings view */
		  AND (
		        (ccm.status IN (5,7) AND cca.id IS NOT NULL)
		     OR (ccm.status = 5 AND cpa.id IS NOT NULL AND cpa.acked_at IS NULL)
		      )
		ORDER BY c.start_date ASC, c.start_time ASC
	";

	$result = mysql_query($sql);

	if ($result === false)
	{
		goat_json_error(500, 'query failed: ' . mysql_error());
	}

	$acks = array();

	/* One instant for the whole response, computed once above the loop so two
	/* calls either side of a lead-time boundary can't be judged against different
	/* nows. This is the exact mirror of the offers endpoint's single $now_unix —
	/* that one measures how long we have WAITED (age), this measures how long we
	/* have LEFT (lead). global.php has set the Melbourne tz, so time() and the
	/* start_date/start_time frame agree without offset arithmetic. */
	$now_unix = time();

	/* Tallies accumulated from the SAME variables each row uses, so a row and its
	/* count can never disagree. Both are mutually exclusive and sum to the total. */
	$c_kind = array('change' => 0, 'promo' => 0);
	$c_lead = array('under48' => 0, 'from48to168' => 0, 'over168' => 0);

	while ($row = mysql_fetch_object($result))
	{
		$status_i = (int) $row->status;

		/* Classify from the SAME guards the WHERE used — never a second, drifting
		/* evaluation. Subsumption: a row with both pending flags is one "change". */
		$has_change = ($row->cca_id !== null && ($status_i === 5 || $status_i === 7));
		$has_promo  = ($row->cpa_id !== null && $row->cpa_acked_at === null && $status_i === 5);

		if ($has_change)
			$kind = 'change';
		else if ($has_promo)
			$kind = 'promo';
		else
			continue;   /* WHERE guarantees one of the two; guard anyway */

		/* start built exactly as get-calls-bulk.php / get-open-offers-bulk.php
		/* build it, so the lanes never disagree about a call's wall-clock time. */
		$date_i  = (int) $row->start_date;
		$time_hm = substr($row->start_time, 0, 5);           /* HH:MM */
		if (!preg_match('/^\d{2}:\d{2}$/', $time_hm)) $time_hm = '00:00';

		list($hh, $mm) = array_map('intval', explode(':', $time_hm));
		$start_unix = $date_i + ($hh * 3600) + ($mm * 60);

		/* Lead bucket, assigned ONCE, from now to the call's start — EXACTLY lane
		/* 1's boundaries (under48 / from48to168 / over168). The donut counts and
		/* the drill-down list both read this tag, so they cannot disagree, and the
		/* two lanes on one screen agree about what "soon" means. */
		$lead_secs = $start_unix - $now_unix;
		if ($lead_secs < 172800)          /* < 48h */
			$lead_bucket = 'under48';
		else if ($lead_secs < 604800)     /* 48h–168h (2–7 days) */
			$lead_bucket = 'from48to168';
		else
			$lead_bucket = 'over168';

		$c_kind[$kind]++;
		$c_lead[$lead_bucket]++;

		/* The "was" timing — change rows only, from the ack's prev_* columns.
		/* prev_start_date is a unix date at local midnight; prev_start_time is
		/* "HH:MM:SS". Emitted ISO so the card can show "was Tue 11 Aug 22:00". */
		$prev_start = null;
		if ($kind === 'change' && $row->prev_start_date !== null)
		{
			$pt = substr($row->prev_start_time, 0, 8);
			if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $pt))
			{
				if (preg_match('/^\d{2}:\d{2}$/', substr($pt, 0, 5)))
					$pt = substr($pt, 0, 5) . ':00';
				else
					$pt = '00:00:00';
			}
			$prev_start = date('Y-m-d', (int) $row->prev_start_date) . 'T' . $pt;
		}

		/* promoted_at — promo rows only. A subsumed row is kind "change", so it
		/* correctly carries no promoted_at even though a promo row also exists. */
		$promoted_at = null;
		if ($kind === 'promo' && $row->cpa_promoted_at !== null)
			$promoted_at = date('Y-m-d\TH:i:s', (int) $row->cpa_promoted_at);

		/* "Lastname, Firstname" — same construction as the other bulk reads. */
		$crew_name = trim($row->crew_ln);
		if (strlen(trim($row->crew_fn)) > 0)
		{
			if (strlen($crew_name) > 0)
				$crew_name .= ', ';
			$crew_name .= trim($row->crew_fn);
		}

		$acks[] = array(
			'user_id'      => (int) $row->user_id,
			'ein'          => ($row->ein === null ? null : (string) $row->ein),
			'name'         => $crew_name,
			'call_id'      => (int) $row->call_id,
			'booking_id'   => (int) $row->booking_id,
			'call_name'    => $row->call_name,
			'booking_name' => $row->booking_name,
			'venue'        => $row->venue_name,
			'start'        => date('Y-m-d\TH:i:s', $start_unix),
			'date_iso'     => date('Y-m-d',        $date_i),
			'time'         => $time_hm,
			/* "change" | "promo"; assigned above and read directly by the counts
			/* and the list so neither re-derives it. */
			'kind'         => $kind,
			/* lead tag assigned above; the client filters on it, never on a
			/* recomputed boundary. */
			'lead_bucket'  => $lead_bucket,
			'promoted_at'  => $promoted_at,
			'prev_start'   => $prev_start,
		);
	}

	/* Both identities hold by construction: every row increments exactly one kind
	/* and exactly one lead bucket, so total = sum(kind) = sum(lead). */
	echo json_encode(array(
		'generated_at' => date('Y-m-d\TH:i:s'),
		'window'       => array('start' => $start_raw, 'end' => $end_raw),
		'counts'       => array(
			'total' => count($acks),
			'kind'  => $c_kind,
			'lead'  => $c_lead,
		),
		'acks'         => $acks,
	));

?>
