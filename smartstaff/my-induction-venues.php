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
	/* Every active induction venue (venues.active = 1 AND venues.has_induction = 1)
	/* LEFT JOINed to this user's crew_venue_induction rows, so Incomplete venues
	/* appear too.
	/*
	/* Status policy now reads per-venue validity from the induction catalogue
	/* (venue_induction_catalogue via venue_induction_covers) and uses the unified
	/* day-based arithmetic shared with THE GOAT and Crew Hub:
	/*   expiry = complete_date + round(validity_months/12*365) days
	/*   Expired       now > expiry
	/*   Expiring Soon within warn_days of expiry
	/*   Complete      otherwise
	/*   Incomplete    no completion row
	/* Venues with no catalogue row fall back to 12 months / 14-day warn.
	*/

	$userID = (int) goat_acting_user_id();

	if ($userID <= 0)
	{
		http_response_code(403);
		die('{"error":"not authorised"}');
	}

	$now = time();

	$sql = "SELECT v.id AS venue_id,
	               v.venue AS venue_name,
	               i.complete_date AS complete_date,
	               i.file AS file,
	               cat.validity_months AS validity_months,
	               cat.warn_days AS warn_days
	        FROM venues v
	        LEFT JOIN crew_venue_induction i
	               ON i.venue_id = v.id AND i.crew_id = " . $userID . "
	        LEFT JOIN venue_induction_covers cov
	               ON cov.venue_id = v.id
	        LEFT JOIN venue_induction_catalogue cat
	               ON cat.id = cov.catalogue_id
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
			$validity = ($row->validity_months !== null) ? (int) $row->validity_months : 12;
			$warn     = ($row->warn_days !== null) ? (int) $row->warn_days : 14;

			$days   = (int) round($validity / 12.0 * 365);
			$expiry = (int) $cd + ($days * 86400);

			if ($now > $expiry)
			{
				$status = 'Expired';
			}
			else if (($expiry - $now) <= ($warn * 86400))
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
