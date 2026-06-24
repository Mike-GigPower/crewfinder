<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SELF endpoint — returns the logged-in user's OWN inductions only.
	/* Keyed on $_SESSION userID, so a crew member can only ever read their own
	/* records. No admin gate (cf. get-unavailabilities.php).
	*/

$userID = goat_acting_user_id();

	/*
	/* inductions
	/* crew_venue_induction (crew_id, venue_id, complete_date) -> venues (id, venue)
	/*
	/* Same shape as the per-crew "inductions" dict that list-crew-bulk.php
	/* emits: { "<venue>": { "status": "Complete", "completed": "DD Mon YYYY" } }.
	/* status is always "Complete" for a present row; the app layers Expiring /
	/* Expired on top by comparing `completed` against its expiry policy, exactly
	/* as it already does for the admin Induction Checker.
	*/

	$sql = "SELECT v.venue AS venue_name,
	               i.complete_date AS complete_date
	        FROM crew_venue_induction i
	        INNER JOIN venues v ON v.id = i.venue_id
	        WHERE i.crew_id = $userID";

	$res = mysql_query($sql);

	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"induction query failed: ' . addslashes(mysql_error()) . '"}');
	}

	$inductions = array();

	while ($row = mysql_fetch_object($res))
	{
		$inductions[$row->venue_name] = array(
			'status'    => 'Complete',
			'completed' => $row->complete_date
			                ? date('d M Y', (int) $row->complete_date)
			                : '',
		);
	}

	/* Force an empty result to encode as {} (object), not [] (array): PHP's
	/* json_encode turns an empty associative array into [], which breaks a
	/* consumer doing Object.keys() / .items() that expects an object. A
	/* populated assoc array (string venue-name keys) already encodes as {}. */
	echo json_encode(array(
		'inductions' => empty($inductions) ? new stdClass() : $inductions,
	));

?>
