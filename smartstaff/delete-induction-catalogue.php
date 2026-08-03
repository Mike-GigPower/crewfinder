<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — deletes one induction catalogue row (admin-gated,
	/* POST-only). Modeled on delete-venue.php: a GUARDED HARD DELETE.
	/*
	/* GUARD: refuse if any crew completion exists against a venue this row
	/* covers — deleting would orphan those crew_venue_induction records from
	/* their catalogue definition. n > 0 -> 409 {"error":"in use","count":n};
	/* the UI offers Unpublish + remove-from-Checker instead of a delete.
	/*
	/* With no completions, DELETE the row. venue_induction_covers has an
	/* ON DELETE CASCADE FK to the catalogue, so its covers rows clear
	/* automatically — no manual join-row cleanup here.
	/*
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

	/* Confirm the row exists before doing anything. */
	$chk = mysql_query("SELECT id FROM venue_induction_catalogue WHERE id = " . $id . " LIMIT 1");

	if ($chk === false)
	{
		http_response_code(500);
		die('{"error":"lookup failed: ' . addslashes(mysql_error()) . '"}');
	}

	if (mysql_num_rows($chk) == 0)
	{
		http_response_code(404);
		die('{"error":"catalogue row not found"}');
	}

	/* DEPENDENCY GUARD: any crew completion against a covered venue blocks. */
	$g = mysql_query("SELECT COUNT(*) AS n
	                  FROM crew_venue_induction cvi
	                  JOIN venue_induction_covers cov ON cov.venue_id = cvi.venue_id
	                  WHERE cov.catalogue_id = " . $id);

	if ($g === false)
	{
		http_response_code(500);
		die('{"error":"in-use check failed: ' . addslashes(mysql_error()) . '"}');
	}

	$grow  = mysql_fetch_object($g);
	$count = (int) $grow->n;

	if ($count > 0)
	{
		http_response_code(409);
		die(json_encode(array(
			'error' => 'in use',
			'count' => $count
		)));
	}

	/* No completions — hard delete. Covers clear via ON DELETE CASCADE. */
	mysql_query("DELETE FROM venue_induction_catalogue WHERE id = " . $id);

	if (mysql_error() !== '')
	{
		http_response_code(500);
		die('{"error":"delete failed: ' . addslashes(mysql_error()) . '"}');
	}

	echo json_encode(array(
		'ok'      => true,
		'id'      => $id,
		'deleted' => 'catalogue'
	));

?>
