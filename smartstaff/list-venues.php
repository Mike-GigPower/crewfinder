<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — {id, name, active} for EVERY venue (active and inactive),
	/* for the Manage Venues browse list. The app filters by active (?active=),
	/* mirroring crew-list. Admin-gated.
	/*
	/* Distinct from list-venues-bulk.php: that one feeds the Crew Finder geo
	/* cache (read-all gate, active-only, carries postcode/suburb/has_induction).
	/* This one is the admin management list — includes inactive, name + status
	/* only.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		http_response_code(403);
		die('{"error":"Admin only"}');
	}

	$venues = array();
	$sql = "SELECT id, venue, active FROM venues ORDER BY venue ASC";
	$res = mysql_query($sql);
	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"venues query failed: ' . addslashes(mysql_error()) . '"}');
	}
	while ($row = mysql_fetch_object($res))
	{
		$venues[] = array(
			'id'     => (int) $row->id,
			'name'   => $row->venue,
			'active' => (int) $row->active
		);
	}

	echo json_encode(array('venues' => $venues));

?>
