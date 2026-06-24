<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SELF endpoint — full induction list for the logged-in / asserted user.
	/* Mirrors the crew "My Inductions" page: every active induction venue
	/* (venues.active = 1 AND venues.has_induction = 1) LEFT JOINed to this
	/* user's crew_venue_induction rows, so Incomplete venues appear too.
	/*
	/* Status policy is the SmartStaff one (see venue-inductions.php), 30-day
	/* months, so the portal matches the admin Induction Checker:
	/*   Incomplete      complete_date IS NULL
	/*   Expired         >= 12 months old
	/*   Expiring Soon   >= 11 months old
	/*   Complete        otherwise
	/*
	/* Deliberately a SEPARATE endpoint from my-inductions.php (which stays an
	/* INNER JOIN, completed-only) so the GOAT MY STATUS / bulk consumers that
	/* depend on "present row = Complete" do not regress.
	*/

	$userID = (int) goat_acting_user_id();

	if ($userID <= 0)
	{
		http_response_code(403);
		die('{"error":"not authorised"}');
	}

	$month = 2592000;
	$now   = time();

	$sql = "SELECT v.id AS venue_id,
	               v.venue AS venue_name,
	               i.complete_date AS complete_date,
	               i.file AS file
	        FROM venues v
	        LEFT JOIN crew_venue_induction i
	               ON i.venue_id = v.id AND i.crew_id = " . $userID . "
	        WHERE v.active = 1 AND v.has_induction = 1
	        ORDER BY v.id ASC";

	$res = mysql_query($sql);

	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"induction venues query failed: ' . addslashes(mysql_error()) . '"}');
	}

	$venues = array();

	while ($row = mysql_fetch_object($res))
	{
		$cd = $row->complete_date;

		if ($cd == null || (int) $cd == 0)
		{
			$status    = 'Incomplete';
			$completed = '';
			$ts        = null;
		}
		else
		{
			$months = abs((int) round(($now - (int) $cd) / $month));

			if ($months >= 12)
			{
				$status = 'Expired';
			}
			else if ($months >= 11)
			{
				$status = 'Expiring Soon';
			}
			else
			{
				$status = 'Complete';
			}

			$completed = date('d M Y', (int) $cd);
			$ts        = (int) $cd;
		}

		$venues[] = array(
			'venue_id'    => (int) $row->venue_id,
			'venue'       => $row->venue_name,
			'status'      => $status,
			'completed'   => $completed,
			'complete_ts' => $ts,
			'file'        => $row->file ? $row->file : null
		);
	}

	echo json_encode(array('venues' => $venues));

?>
