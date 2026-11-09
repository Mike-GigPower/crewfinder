<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');
	include_once(dirname(__FILE__) . '/crew-hours-lib.php');   /* goat_crew_hours_date_iso() */

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — EVERYONE's last worked shift, in one query, for the Last
	/* shift column on Crew › Records (BRIEF-records-last-shift.md). Bulk sibling
	/* of get-crew-hours.php, so Records does not make one SmartStaff call per
	/* crew member (per-session PHP lock — they queue).
	/*
	/* "Last shift" is the Hours tab rule exactly (D1): call_crew_map.status = 5,
	/* booking not hidden, start_date > 0 and <= today's local midnight. Calls
	/* still awaiting times count. Paid cancellations (9), no-shows (8),
	/* declines (6) and offers (0/1) never do. Do not widen to 9 — see the D18
	/* note in get-crew-hours.php.
	/*
	/* ADMIN ONLY: Records is admin-only, so nothing new is exposed.
	/*
	/* One derived-table GROUP BY, no correlated subquery (list-crew-bulk.php §4
	/* records one timing out). NO users join: on test (10 Nov 2026) it cost
	/* ~2 s of a 3.7 s query; without it the inner query ran in 1.7 s. A few
	/* non-crew ids come back as a result and are harmless — Records only
	/* renders crew rows, so nothing reads them. :today appears ONCE — goat_pdo() runs with
	/* EMULATE_PREPARES off, which rejects a reused named placeholder.
	/*
	/* Two calls on the same last date: later start_time wins, tie -> higher
	/* call_id.
	/*
	/* Response: {"ok":true,"last_shifts":{"<user id>":{date_iso, start_time,
	/*   call_id, booking_id, call_name, booking_name}},"timing_ms":N}
	/*   - keyed by user id; someone with no qualifying shift is simply absent
	/*   - names pass through RAW (SmartStaff HTML-encodes them); the UI decodes
	/*   - (object) on emit so an empty result is {} not []
	/*
	/* Read-only. PHP 5.x — array(), no ??, no short arrays, tabs. PDO via
	/* goat_pdo().
	*/

	$t0 = microtime(true);

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

	$today_ts = strtotime('today');   /* one clock — same as get-crew-hours.php */

	$sql = "
		SELECT
			m.user_id     AS user_id,
			c.id          AS call_id,
			c.bookingID   AS booking_id,
			c.call_name   AS call_name,
			b.name        AS booking_name,
			c.start_date  AS start_date,
			c.start_time  AS start_time
		FROM (
			SELECT ccm.userID AS user_id, MAX(c.start_date) AS last_date
			FROM call_crew_map ccm
			JOIN calls    c ON c.id = ccm.callID
			JOIN bookings b ON b.id = c.bookingID
			WHERE ccm.status    = 5
			  AND b.hidden      = 0
			  AND c.start_date  > 0
			  AND c.start_date <= :today
			GROUP BY ccm.userID
		) m
		JOIN call_crew_map ccm ON ccm.userID = m.user_id AND ccm.status = 5
		JOIN calls    c ON c.id = ccm.callID AND c.start_date = m.last_date
		JOIN bookings b ON b.id = c.bookingID AND b.hidden = 0
	";

	$last = array();

	try
	{
		$stmt = $pdo->prepare($sql);
		$stmt->execute(array(':today' => $today_ts));

		while ($r = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$uid  = (int) $r['user_id'];
			$cand = array(
				'date_iso'     => goat_crew_hours_date_iso((int) $r['start_date']),
				'start_time'   => $r['start_time'],
				'call_id'      => (int) $r['call_id'],
				'booking_id'   => (int) $r['booking_id'],
				'call_name'    => $r['call_name'],
				'booking_name' => $r['booking_name']
			);

			if (!isset($last[$uid]))
			{
				$last[$uid] = $cand;
				continue;
			}

			/* Same last date: later start_time wins; tie -> higher call_id. */
			$cur = $last[$uid];
			$cmp = strcmp((string) $cand['start_time'], (string) $cur['start_time']);

			if ($cmp > 0 || ($cmp === 0 && $cand['call_id'] > $cur['call_id']))
				$last[$uid] = $cand;
		}

		$stmt->closeCursor();
	}
	catch (PDOException $e)
	{
		error_log('admin-list-last-shift-bulk: ' . $e->getMessage());
		goat_json_error(500, 'last shift query failed');
		exit;
	}

	echo json_encode(array(
		'ok'          => true,
		'last_shifts' => (object) $last,
		'timing_ms'   => (int) round((microtime(true) - $t0) * 1000)
	));

?>
