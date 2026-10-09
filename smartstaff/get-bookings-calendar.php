<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN ONLY — the Calendar view of the All Bookings tab
	/* (DESIGN-all-bookings-calendar-v0_1 D6, Q4). Same gate as
	/* get-bookings-bulk.php, which feeds the List view of the same tab.
	/*
	/* WHY A NEW FILE: the list endpoint is booking-level and paginated
	/* newest-first, so it can neither draw a bar from a booking's first call
	/* to its last (D1) nor jump to an old month without loading every booking
	/* since. This reads one date window. get-bookings-bulk.php is untouched.
	/*
	/* PARAMS:
	/*   start=YYYY-MM-DD  end=YYYY-MM-DD   inclusive, cap 62 days
	/*   q=<text>                           optional booking-name search
	/*
	/* IN SCOPE: every booking with at least one NON-CANCELLED call dated in
	/* the window, plus every booking with NO live calls whose creation_date is
	/* in the window (drawn as a one-day bar on that date, D7).
	/*
	/* RESPONSE:
	/*   { ok, start, end, truncated,
	/*     bookings: [{ booking_id, name, status, status_id, customer, venue,
	/*                  creation_day, first_day, last_day, call_count,
	/*                  cancelled_only,
	/*                  calls: [{ call_id, call_name, day, start_time,
	/*                            required, confirmed }] }] }
	/*
	/*   first_day / last_day   YYYY-MM-DD across ALL of the booking's live
	/*                          calls, not just the in-window ones, so a bar
	/*                          that crosses the window edge is drawn with a
	/*                          "continues" end. null when it has no live calls.
	/*   calls[]                in-window live calls only, oldest first.
	/*                          confirmed = call_crew_map.status 5.
	/*   cancelled_only         true when the booking has calls but every one
	/*                          is cancelled (D7: greyed, struck through).
	/*
	/* Overnight calls count on their START day only (D8) — start_date is the
	/* only date the table stores.
	/*
	/* PHP 5.x — array(), no ??, no short arrays, tabs. PDO via goat_pdo().
	/* Integers come back from this driver as strings: cast on the way out.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		goat_json_error(403, 'Admin only');
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

	$start_raw = isset($_GET['start']) ? (string) $_GET['start'] : '';
	$end_raw   = isset($_GET['end'])   ? (string) $_GET['end']   : '';
	$q         = isset($_GET['q'])     ? trim((string) $_GET['q']) : '';

	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_raw)
	    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_raw))
	{
		goat_json_error(400, 'start and end must both be YYYY-MM-DD');
		exit;
	}

	/* start_date is a unix timestamp at LOCAL midnight; strtotime() builds the
	/* bounds so DST is handled, as get-crewlist-status.php does. */
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

	/* 62 days, plus an hour of slack for a DST change inside the window */
	if (($end_ts - $start_ts) > (62 * 86400 + 3600))
	{
		goat_json_error(400, 'window exceeds 62 days');
		exit;
	}

	if (strlen($q) > 100)
		$q = substr($q, 0, 100);

	/* A month in peak is a few hundred bookings. The cap only exists so a
	/* runaway window can never build an unbounded IN () list. */
	$MAX_BOOKINGS = 800;

	/*
	/* ── READ ─────────────────────────────────────────────────────────────────
	*/

	try
	{
		/* cancelled_at is live on prod (calls-awaiting-times.php reads it
		/* unguarded), but the guard costs one query and keeps test boxes safe. */
		$hasCancel = false;
		$r = $pdo->query("SHOW COLUMNS FROM calls LIKE 'cancelled_at'");
		if ($r !== false)
		{
			$hasCancel = ($r->fetch() !== false);
			$r->closeCursor();
		}
		$liveCa  = $hasCancel ? ' AND ca.cancelled_at IS NULL' : '';
		$liveC2  = $hasCancel ? ' AND c2.cancelled_at IS NULL' : '';

		$nameSql = ($q !== '') ? ' AND b.name LIKE :q' : '';
		$qLike   = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $q) . '%';

		$ids = array();

		/* 1. bookings with a live call dated in the window */
		$stmt = $pdo->prepare(
			"SELECT DISTINCT ca.bookingID AS id
			 FROM calls ca
			 JOIN bookings b ON b.id = ca.bookingID
			 WHERE ca.start_date >= :s AND ca.start_date < :e" . $liveCa . $nameSql);
		$stmt->bindValue(':s', (int) $start_ts, PDO::PARAM_INT);
		$stmt->bindValue(':e', (int) $end_ts,   PDO::PARAM_INT);
		if ($q !== '')
			$stmt->bindValue(':q', $qLike, PDO::PARAM_STR);
		$stmt->execute();
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			if ((int) $row['id'] > 0)
				$ids[(int) $row['id']] = true;
		}
		$stmt->closeCursor();

		/* 2. bookings with NO live call, placed by their booking date */
		$stmt = $pdo->prepare(
			"SELECT b.id
			 FROM bookings b
			 WHERE b.creation_date >= :s AND b.creation_date < :e" . $nameSql . "
			   AND NOT EXISTS (SELECT 1 FROM calls c2 WHERE c2.bookingID = b.id" . $liveC2 . ")");
		$stmt->bindValue(':s', (int) $start_ts, PDO::PARAM_INT);
		$stmt->bindValue(':e', (int) $end_ts,   PDO::PARAM_INT);
		if ($q !== '')
			$stmt->bindValue(':q', $qLike, PDO::PARAM_STR);
		$stmt->execute();
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			if ((int) $row['id'] > 0)
				$ids[(int) $row['id']] = true;
		}
		$stmt->closeCursor();

		$ids       = array_keys($ids);
		sort($ids);
		$truncated = false;

		if (count($ids) > $MAX_BOOKINGS)
		{
			$ids       = array_slice($ids, 0, $MAX_BOOKINGS);
			$truncated = true;
		}

		$bookings = array();
		$order    = array();

		if (count($ids))
		{
			/* ids are integers we built ourselves — safe to inline */
			$in = implode(',', array_map('intval', $ids));

			/* 3. booking detail + span across ALL its live calls */
			$res = $pdo->query(
				"SELECT b.id, b.name, b.status, b.creation_date,
				        cu.customer_name, v.venue,
				        MIN(ca.start_date) AS first_ts,
				        MAX(ca.start_date) AS last_ts,
				        COUNT(ca.id)       AS live_calls,
				        (SELECT COUNT(*) FROM calls c3 WHERE c3.bookingID = b.id) AS all_calls
				 FROM bookings b
				 LEFT JOIN customers cu ON b.customerID = cu.id
				 LEFT JOIN venues v     ON b.venueID    = v.id
				 LEFT JOIN calls ca     ON ca.bookingID = b.id" . $liveCa . "
				 WHERE b.id IN (" . $in . ")
				 GROUP BY b.id, b.name, b.status, b.creation_date, cu.customer_name, v.venue");

			$bStatusMap = array(0 => 'Active', 1 => 'Closed');

			while ($b = $res->fetch(PDO::FETCH_ASSOC))
			{
				$bid  = (int) $b['id'];
				$sid  = (int) $b['status'];
				$live = (int) $b['live_calls'];
				$cd   = (int) $b['creation_date'];

				$bookings[$bid] = array(
					'booking_id'     => $bid,
					'name'           => $b['name'],
					'status'         => (isset($bStatusMap[$sid]) ? $bStatusMap[$sid] : ('Status ' . $sid)),
					'status_id'      => $sid,
					'customer'       => $b['customer_name'],
					'venue'          => $b['venue'],
					'creation_day'   => ($cd > 0 ? date('Y-m-d', $cd) : null),
					'first_day'      => ($live > 0 ? date('Y-m-d', (int) $b['first_ts']) : null),
					'last_day'       => ($live > 0 ? date('Y-m-d', (int) $b['last_ts'])  : null),
					'call_count'     => $live,
					'cancelled_only' => ($live === 0 && (int) $b['all_calls'] > 0),
					'calls'          => array()
				);
			}
			$res->closeCursor();

			/* 4. in-window live calls, with confirmed counts */
			$stmt = $pdo->prepare(
				"SELECT ca.id, ca.bookingID, ca.call_name, ca.start_date, ca.start_time, ca.required,
				        (SELECT COUNT(*) FROM call_crew_map m
				         WHERE m.callID = ca.id AND m.status = 5) AS confirmed
				 FROM calls ca
				 WHERE ca.bookingID IN (" . $in . ")
				   AND ca.start_date >= :s AND ca.start_date < :e" . $liveCa . "
				 ORDER BY ca.start_date ASC, ca.start_time ASC, ca.id ASC");
			$stmt->bindValue(':s', (int) $start_ts, PDO::PARAM_INT);
			$stmt->bindValue(':e', (int) $end_ts,   PDO::PARAM_INT);
			$stmt->execute();

			while ($c = $stmt->fetch(PDO::FETCH_ASSOC))
			{
				$bid = (int) $c['bookingID'];
				if (!isset($bookings[$bid]))
					continue;

				$bookings[$bid]['calls'][] = array(
					'call_id'    => (int) $c['id'],
					'call_name'  => $c['call_name'],
					'day'        => date('Y-m-d', (int) $c['start_date']),
					'start_time' => $c['start_time'],
					'required'   => (int) $c['required'],
					'confirmed'  => (int) $c['confirmed']
				);
			}
			$stmt->closeCursor();
		}
	}
	catch (PDOException $ex)
	{
		error_log('get-bookings-calendar: ' . $ex->getMessage());
		goat_json_error(500, 'bookings calendar query failed');
		exit;
	}

	$body = json_encode(array(
		'ok'        => true,
		'start'     => $start_raw,
		'end'       => $end_raw,
		'q'         => $q,
		'truncated' => $truncated,
		'bookings'  => array_values($bookings)
	));

	if ($body === false)
	{
		goat_json_error(500, 'could not encode response');
		exit;
	}

	echo $body;

?>
