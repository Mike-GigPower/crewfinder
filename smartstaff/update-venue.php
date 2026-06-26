<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — updates one venue's record (admin-gated). Whitelisted
	/* text fields are quoted as strings so a leading zero on postcode survives.
	/* active and has_induction are INT flags written as 1/0. Only fields actually
	/* present in the POST are written, so a partial save touches only what was
	/* sent. Update success is gated on mysql_error()/result, NEVER affected_rows
	/* (which returns 0 on a no-op save where submitted values equal what is
	/* already stored).
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

	/* Confirm the venue exists before writing. */
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

	$set = array();

	/* venue name: writable, but must not be blanked out. */
	if (isset($_POST['venue']))
	{
		$venue = trim($_POST['venue']);
		if ($venue === '')
		{
			http_response_code(400);
			die('{"error":"venue name required"}');
		}
		$set[] = "venue = '" . mysql_real_escape_string($venue) . "'";
	}

	/* Whitelisted text fields. Quoted as strings so leading zeros survive. */
	$fields = array('address', 'suburb', 'state', 'postcode');

	foreach ($fields as $f)
	{
		if (isset($_POST[$f]))
			$set[] = $f . " = '" . mysql_real_escape_string(trim($_POST[$f])) . "'";
	}

	/* active: INT flag, only 1 or 0 */
	if (isset($_POST['active']))
	{
		$active = ($_POST['active'] === '1') ? 1 : 0;
		$set[] = "active = " . $active;
	}

	/* has_induction: INT flag, only 1 or 0 */
	if (isset($_POST['has_induction']))
	{
		$has_ind = ($_POST['has_induction'] === '1') ? 1 : 0;
		$set[] = "has_induction = " . $has_ind;
	}

	if (empty($set))
	{
		http_response_code(400);
		die('{"error":"no fields to update"}');
	}

	$sql = "UPDATE venues SET " . implode(", ", $set) . " WHERE id = " . $id;

	$result = mysql_query($sql);

	if ($result === false)
	{
		http_response_code(500);
		die('{"error":"update failed: ' . addslashes(mysql_error()) . '"}');
	}

	echo json_encode(array(
		'ok'      => true,
		'id'      => $id,
		'updated' => count($set)
	));

?>
