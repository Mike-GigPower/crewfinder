<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');
	include(dirname(__FILE__) . '/crew-hours-lib.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — one crew member's hours, worked vs scheduled, and their
	/* 144-hour probation runs. Powers the Hours tab on the GOAT crew record.
	/*
	/* Request:  GET ?id=<userID>
	/*
	/* Every figure comes from goat_crew_hours_build() in crew-hours-lib.php — the
	/* SAME function list-probation-progress.php calls — so the tab and the Today
	/* lane cannot disagree about who crossed 144, or when
	/* (BRIEF-crew-hours-probation.md §3.4).
	/*
	/* This file's only jobs are the gate, the query and the output. Eligibility
	/* lives in the SQL (§2.1): confirmed (status 5), booking not hidden, a real
	/* call date, dated today or earlier. Recorded / awaiting / excluded is the
	/* library's call.
	/*
	/* Gate: admin only, the get-crew-shifts.php pattern. The crew record itself
	/* is admin-only (/api/admin/crew/<id>), and this is a tab on it.
	/*
	/* PHP 5.x — array(), no ??, no short arrays, tabs. PDO via goat_pdo(), never
	/* mysql_* (red-zone.php / list-licence-holders.php house pattern).
	*/

	if (goat_user_cohort() !== 'admin')
	{
		goat_json_error(403, 'Admin only');
		exit;
	}

	$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

	if ($id <= 0)
	{
		goat_json_error(400, 'id required');
		exit;
	}

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		goat_json_error(503, 'Database handle unavailable');
		exit;
	}

	/*
	/* start_date is a unix timestamp at LOCAL midnight, so "today or earlier" is
	/* <= today's local midnight, built in PHP with strtotime() (one clock).
	/*
	/* `on`, `off`, `break` are reserved words — backticked, and aliased to plain
	/* names so nothing downstream has to quote them.
	*/
	$today_ts = strtotime('today');

	$sql = "
		SELECT
			c.id            AS call_id,
			c.bookingID     AS booking_id,
			c.call_name     AS call_name,
			b.name          AS booking_name,
			c.start_date    AS start_date,
			c.start_time    AS start_time,
			c.est_length    AS est_length,
			ccm.`on`        AS on_time,
			ccm.`off`       AS off_time,
			ccm.`break`     AS break_time,
			ccm.break_night AS break_night_time
		FROM call_crew_map ccm
		JOIN calls    c ON c.id = ccm.callID
		JOIN bookings b ON b.id = c.bookingID
		WHERE ccm.userID   = :uid
		  AND ccm.status   = 5
		  AND b.hidden     = 0
		  AND c.start_date > 0
		  AND c.start_date <= :today
	";

	$raw = array();

	try
	{
		$stmt = $pdo->prepare($sql);
		$stmt->execute(array(':uid' => $id, ':today' => $today_ts));

		while ($r = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$raw[] = array(
				'call_id'      => $r['call_id'],
				'booking_id'   => $r['booking_id'],
				'call_name'    => $r['call_name'],
				'booking_name' => $r['booking_name'],
				'start_date'   => $r['start_date'],
				'start_time'   => $r['start_time'],
				'est_length'   => $r['est_length'],
				'on'           => $r['on_time'],
				'off'          => $r['off_time'],
				'break'        => $r['break_time'],
				'break_night'  => $r['break_night_time']
			);
		}
	}
	catch (PDOException $e)
	{
		error_log('get-crew-hours: ' . $e->getMessage());
		goat_json_error(500, 'crew hours query failed');
		exit;
	}

	/* Lane group from the UNROUNDED figures — rounding first could lift 119.996
	   to 120.00 and put the tab at odds with the lane. Round once, after. */
	$exact = goat_crew_hours_build($raw);
	$lane  = goat_crew_hours_lane_group($exact, date('Y-m-d', $today_ts));
	$built = goat_crew_hours_round($exact);

	/*
	/* Names are returned AS STORED. SmartStaff keeps them HTML-encoded, and the
	/* client runs decodeEntities() before escapeHtml() on every SmartStaff
	/* string (house rule) — decoding here as well would double-decode.
	*/
	$body = json_encode(array(
		'ok'           => true,
		'generated_at' => date('Y-m-d\TH:i:s'),
		'as_at'        => date('Y-m-d', $today_ts),
		'user_id'      => $id,
		'constants'    => goat_crew_hours_constants(),
		'shifts'       => $built['shifts'],
		'runs'         => $built['runs'],
		'current_run'  => $built['current_run'],
		'lane_group'   => $lane,
		'totals'       => $built['totals'],
		'data_quality' => $built['data_quality']
	));

	if ($body === false)
	{
		goat_json_error(500, 'could not encode response');
		exit;
	}

	echo $body;

?>
