<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SELF endpoint — updates the acting user's OWN contact details. Self-scoped
	/* via goat_acting_user_id(). Only whitelisted fields that are actually
	/* present in the request are written, so a partial save touches only what
	/* was sent. Privileged columns (usergroupID, cohort, ein, active, salt,
	/* password) are deliberately excluded. Password change is handled separately.
	*/

	$userID = goat_acting_user_id();

	if ($_SERVER['REQUEST_METHOD'] !== 'POST')
	{
		http_response_code(405);
		die('{"error":"POST required"}');
	}

	$fields = array('mobile', 'phone', 'address', 'suburb', 'state', 'postcode',
	                'email', 'emergency_contact', 'emergency_phone');

	$set = array();
	foreach ($fields as $f)
	{
		/* Quote as a string explicitly: $db->sc() coerces all-digit values to
		/* numbers, which strips leading zeros from phones and 0-prefixed
		/* postcodes (NT/ACT). These columns are all text. */
		if (isset($_POST[$f]))
			$set[] = $f . " = '" . mysql_real_escape_string(trim($_POST[$f])) . "'";
	}

	if (empty($set))
	{
		http_response_code(400);
		die('{"error":"no fields to update"}');
	}

	$sql = "UPDATE users SET " . implode(", ", $set) . " WHERE id = " . (int) $userID;

	$result = mysql_query($sql);

	/* Gate on the query result / mysql_error(), NEVER affected_rows — that
	/* returns 0 on a no-op save (submitted values equal what's already stored)
	/* and would be misread as failure. */
	if ($result === false)
	{
		http_response_code(500);
		die('{"error":"update failed: ' . addslashes(mysql_error()) . '"}');
	}

	echo json_encode(array(
		'ok'      => true,
		'user_id' => (int) $userID,
		'updated' => count($set)
	));

?>