<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');
	include(dirname(__FILE__) . '/crewlist-sent-lib.php');

	header('Content-Type: application/json');

	/*
	/* READ-ALL endpoint — crew-list sent state for the Schedule, the booking
	/* dialog, the All Bookings page and (Slice C) the Ops tile
	/* (BRIEF-crewlist-sent §A2, DESIGN-crewlist-sent-v0_2 §4.2).
	/*
	/* EXACTLY ONE scope:
	/*   booking=<id>                    every call of one booking
	/*   bookings=<id,id,...>            several bookings, cap 200 ids
	/*   start=YYYY-MM-DD&end=YYYY-MM-DD every call DATED in the window, end
	/*                                   inclusive, cap 120 days
	/*
	/* RESPONSE:
	/*   calls     callID => {booking_id, state, sent_at, sent_by, sent_by_name,
	/*             diff}, ONLY for in-scope calls with a live mark. Absence is
	/*             'none'. Always a JSON object, {} when empty.
	/*   bookings  bookingID => {calls_total, calls_sent, all_sent} for every
	/*             booking in scope. Counts cover EVERY non-cancelled call of
	/*             the booking, not just the ones in a date window — D5 is about
	/*             the whole booking, and the Schedule summary must equal the
	/*             booking dialog header (Slice B acceptance).
	/*
	/* The comparison itself lives in crewlist-sent-lib.php, shared with
	/* undo-crewlist-sent.php.
	/*
	/* Gate: goat_can_read_all() — D9, everyone who can see the Schedule sees
	/* the marker. Marking and undoing are admin-only, in their own files.
	/*
	/* PHP 5.x — array(), no ??, no short arrays, tabs. PDO via goat_pdo().
	*/

	if (!goat_can_read_all())
	{
		goat_json_error(403, 'Admin, Leadership or Operations required');
		exit;
	}

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		goat_json_error(503, 'Database handle unavailable');
		exit;
	}

	/*
	/* ── INPUT ────────────────────────────────────────────────────────────────
	*/

	$has_booking  = isset($_GET['booking'])  && (string) $_GET['booking']  !== '';
	$has_bookings = isset($_GET['bookings']) && (string) $_GET['bookings'] !== '';
	$has_window   = (isset($_GET['start']) && (string) $_GET['start'] !== '')
	             || (isset($_GET['end'])   && (string) $_GET['end']   !== '');

	if (((int) $has_booking + (int) $has_bookings + (int) $has_window) !== 1)
	{
		goat_json_error(400, 'give exactly one of booking, bookings, or start and end');
		exit;
	}

	$bookingIDs = array();     /* scope for bookings{} */
	$start_ts   = null;        /* window mode only */
	$end_ts     = null;

	if ($has_booking)
	{
		$raw = trim((string) $_GET['booking']);

		if (!preg_match('/^[0-9]+$/', $raw) || (int) $raw <= 0)
		{
			goat_json_error(400, 'booking must be a positive integer');
			exit;
		}

		$bookingIDs[] = (int) $raw;
	}
	else if ($has_bookings)
	{
		$seen = array();

		foreach (explode(',', (string) $_GET['bookings']) as $p)
		{
			$p = trim($p);
			if ($p === '')
				continue;

			if (!preg_match('/^[0-9]+$/', $p) || (int) $p <= 0)
			{
				goat_json_error(400, 'bookings must be a comma-separated list of positive integers');
				exit;
			}

			$seen[(int) $p] = true;
		}

		$bookingIDs = array_keys($seen);

		if (!count($bookingIDs))
		{
			goat_json_error(400, 'bookings is empty');
			exit;
		}

		if (count($bookingIDs) > 200)
		{
			goat_json_error(400, 'at most 200 bookings');
			exit;
		}
	}
	else
	{
		$start_raw = isset($_GET['start']) ? (string) $_GET['start'] : '';
		$end_raw   = isset($_GET['end'])   ? (string) $_GET['end']   : '';

		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_raw)
		    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_raw))
		{
			goat_json_error(400, 'start and end must both be YYYY-MM-DD');
			exit;
		}

		/* start_date is a unix timestamp at LOCAL midnight; strtotime() builds
		/* the bounds so DST is handled, as red-zone.php does. */
		$start_ts = strtotime($start_raw . ' 00:00:00');
		$e        = strtotime($end_raw   . ' 00:00:00');

		if ($start_ts === false || $e === false)
		{
			goat_json_error(400, 'invalid date');
			exit;
		}

		/* end is INCLUSIVE of that whole day, so the bound is the next midnight */
		$end_ts = strtotime('+1 day', $e);

		if ($end_ts <= $start_ts)
		{
			goat_json_error(400, 'invalid date range');
			exit;
		}

		/* 120 days, plus an hour of slack for a DST change inside the window */
		if (($end_ts - $start_ts) > (120 * 86400 + 3600))
		{
			goat_json_error(400, 'window exceeds 120 days');
			exit;
		}
	}

	/*
	/* ── READ ─────────────────────────────────────────────────────────────────
	*/

	try
	{
		/* Window mode: the bookings that have a non-cancelled call dated in the
		/* window. Their OTHER calls are loaded too, for the booking counts. */
		if ($start_ts !== null)
		{
			$cancel = goat_crewlist_have_cancelled($pdo) ? 'AND cancelled_at IS NULL' : '';

			$stmt = $pdo->prepare(
				"SELECT DISTINCT bookingID
				 FROM calls
				 WHERE start_date >= :start_ts
				   AND start_date <  :end_ts
				   " . $cancel);
			$stmt->bindValue(':start_ts', (int) $start_ts, PDO::PARAM_INT);
			$stmt->bindValue(':end_ts',   (int) $end_ts,   PDO::PARAM_INT);
			$stmt->execute();

			while ($r = $stmt->fetch(PDO::FETCH_ASSOC))
			{
				if ((int) $r['bookingID'] > 0)
					$bookingIDs[] = (int) $r['bookingID'];
			}
			$stmt->closeCursor();
		}

		$calls  = goat_crewlist_calls_of_bookings($pdo, $bookingIDs);
		$states = goat_crewlist_states($pdo, $calls);
	}
	catch (PDOException $e)
	{
		error_log('get-crewlist-status: ' . $e->getMessage());
		goat_json_error(500, 'crew list status query failed');
		exit;
	}

	/*
	/* ── ASSEMBLE ─────────────────────────────────────────────────────────────
	/*
	/* calls{}: in-scope calls only. Booking scopes: every call of the booking.
	/* Window scope: only calls dated in the window, even though states were
	/* worked out for the whole booking (the booking counts need them).
	*/

	$outCalls = array();

	foreach ($states as $cid => $st)
	{
		if ($start_ts !== null)
		{
			$d = (int) $calls[$cid]['start_date'];
			if ($d < $start_ts || $d >= $end_ts)
				continue;
		}

		$outCalls[(string) $cid] = $st;
	}

	$outBookings = array();

	foreach (goat_crewlist_booking_counts($bookingIDs, $calls, $states) as $bid => $b)
		$outBookings[(string) $bid] = $b;

	/* (object) so an empty map is {} not [] — the client indexes it by id */
	$body = json_encode(array(
		'ok'       => true,
		'calls'    => count($outCalls)    ? $outCalls    : new stdClass(),
		'bookings' => count($outBookings) ? $outBookings : new stdClass()
	));

	if ($body === false)
	{
		goat_json_error(500, 'could not encode response');
		exit;
	}

	echo $body;

?>
