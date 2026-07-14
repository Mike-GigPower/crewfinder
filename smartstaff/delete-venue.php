<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — deletes one venue (admin-gated, POST-only).
	/* GUARDED HARD DELETE: refuses if the venue is still referenced by any of
	/* three tables — bookings.venueID (work history), crew_venue_induction.venue_id
	/* (crew induction records), or user_licenses.venue (induction certificate rows;
	/* the venue column is the induction discriminator on that shared table). MyISAM
	/* has no FK enforcement, so these checks are the only thing preventing an
	/* orphan. Venue has no pure-join rows, so nothing is auto-cleaned — block-only.
	/* Delete success gated on mysql_error(), never affected_rows.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		http_response_code(403);
		die('{"error":"Admin only"}');
	}

	if ($_SERVER['REQUEST_METHOD'] !== 'POST')
	{
		http_response_code(405);
		die('{"error":"POST required"}');
	}

	$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

	if ($id <= 0)
	{
		http_response_code(400);
		die('{"error":"id required"}');
	}

	/* Confirm the venue exists before doing anything. */
	$chk = mysql_query("SELECT id FROM venues WHERE id = " . $id . " LIMIT 1");

	if ($chk === false)
	{
		http_response_code(500);
		die('{"error":"lookup failed: ' . addslashes(mysql_error()) . '"}');
	}

	if (mysql_num_rows($chk) == 0)
	{
		http_response_code(404);
		die('{"error":"venue not found"}');
	}

	/* DEPENDENCY GUARD: three referencing tables, all block. */
	$reasons = array();

	$b = mysql_query("SELECT COUNT(*) AS n FROM bookings WHERE venueID = " . $id);
	if ($b === false)
	{
		http_response_code(500);
		die('{"error":"booking check failed: ' . addslashes(mysql_error()) . '"}');
	}
	$brow    = mysql_fetch_object($b);
	$bookings = (int) $brow->n;
	if ($bookings > 0)
		$reasons[] = $bookings . " booking(s)";

	$ci = mysql_query("SELECT COUNT(*) AS n FROM crew_venue_induction WHERE venue_id = " . $id);
	if ($ci === false)
	{
		http_response_code(500);
		die('{"error":"induction check failed: ' . addslashes(mysql_error()) . '"}');
	}
	$cirow      = mysql_fetch_object($ci);
	$inductions = (int) $cirow->n;
	if ($inductions > 0)
		$reasons[] = $inductions . " crew induction record(s)";

	$ul = mysql_query("SELECT COUNT(*) AS n FROM user_licenses WHERE venue = " . $id);
	if ($ul === false)
	{
		http_response_code(500);
		die('{"error":"licence check failed: ' . addslashes(mysql_error()) . '"}');
	}
	$ulrow     = mysql_fetch_object($ul);
	$cert_rows = (int) $ulrow->n;
	if ($cert_rows > 0)
		$reasons[] = $cert_rows . " induction certificate row(s)";

	if (!empty($reasons))
	{
		http_response_code(409);
		die(json_encode(array(
			'error'      => "Can't delete — still linked: " . implode(", ", $reasons) . ".",
			'bookings'   => $bookings,
			'inductions' => $inductions,
			'cert_rows'  => $cert_rows
		)));
	}

	/* No dependents, no join rows to clean — delete the venue. */
	mysql_query("DELETE FROM venues WHERE id = " . $id);

	if (mysql_error() !== '')
	{
		http_response_code(500);
		die('{"error":"delete failed: ' . addslashes(mysql_error()) . '"}');
	}

	echo json_encode(array(
		'ok'      => true,
		'id'      => $id,
		'deleted' => 'venue'
	));

?>
