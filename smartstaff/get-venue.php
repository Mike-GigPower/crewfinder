<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — returns one venue's editable fields to pre-fill the GOAT
	/* admin edit form. Admin-gated. Returns inactive venues too, so they can be
	/* reactivated from the form (no active filter here, unlike list-venues-bulk).
	*/

	if (goat_user_cohort() !== 'admin')
	{
		http_response_code(403);
		die('{"error":"Admin only"}');
	}

	$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

	if ($id <= 0)
	{
		http_response_code(400);
		die('{"error":"id required"}');
	}

	$sql = "SELECT id, venue, address, suburb, state, postcode,
	               has_induction, active
	        FROM venues
	        WHERE id = " . $id . "
	        LIMIT 1";

	$res = mysql_query($sql);

	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"query failed: ' . addslashes(mysql_error()) . '"}');
	}

	if (mysql_num_rows($res) == 0)
	{
		http_response_code(404);
		die('{"error":"venue not found"}');
	}

	$v = mysql_fetch_object($res);

	/* venue names are stored raw (not HTML-entity-encoded like users.firstname),
	/* so they are returned as-is. */
	echo json_encode(array(
		'id'            => (int) $v->id,
		'venue'         => $v->venue,
		'address'       => $v->address,
		'suburb'        => $v->suburb,
		'state'         => $v->state,
		'postcode'      => $v->postcode,
		'has_induction' => (int) $v->has_induction,
		'active'        => (int) $v->active
	));

?>
