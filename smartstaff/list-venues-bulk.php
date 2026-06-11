<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* READ-ALL (admin / leadership / operations).
	/*
	/* Venue location data (postcode / suburb / state) is not PII the way the
	/* customer + contact lists in import-lookups-bulk.php are, and Crew Finder's
	/* distance search runs in leadership sessions too. So this uses the same
	/* goat_can_read_all() gate as list-crew-bulk.php / get-calls-bulk.php, not
	/* the admin-only gate.
	/*
	/* NOTE: align with the exact helper the other read-all endpoints call.
	*/

	if (!goat_can_read_all())
	{
		http_response_code(403);
		die('{"error":"Admin, Leadership or Operations only"}');
	}

	/*
	/* All active venues with their geo fields, for the app-side venue-geo cache
	/* (venue_cache.json) that backs Crew Finder distance search. Replaces the
	/* hand-maintained VENUE_POSTCODES table (~22 venues, half VERIFY-flagged)
	/* with real data for every active venue.
	/*
	/* Schema notes (verified against smartst_test):
	/*   venues : id, venue, address, suburb, state, postcode, has_induction,
	/*            active (INT -> compare = 1).
	/*   postcode is populated on ~67% of active venues, suburb on ~98%; the app
	/*   prefers postcode and falls back to suburb+state.
	/*
	/* Raw mysql_* accessor for consistency with list-crew-bulk.php /
	/* get-calls-bulk.php.
	*/

	$venues = array();
	$sql = "SELECT id, venue, postcode, suburb, state, has_induction
	        FROM venues
	        WHERE active = 1
	        ORDER BY venue ASC";
	$res = mysql_query($sql);
	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"venues query failed: ' . addslashes(mysql_error()) . '"}');
	}
	while ($row = mysql_fetch_object($res))
	{
		$venues[] = array(
			'id'            => (int) $row->id,
			'name'          => $row->venue,
			'postcode'      => $row->postcode,
			'suburb'        => $row->suburb,
			'state'         => $row->state,
			'has_induction' => (int) $row->has_induction,
		);
	}

	echo json_encode(array('venues' => $venues));

?>
