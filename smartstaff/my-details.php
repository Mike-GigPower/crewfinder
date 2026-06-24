<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SELF endpoint — returns the acting user's OWN editable contact details,
	/* to pre-fill the crew app's "My Details" form. Self-scoped via
	/* goat_acting_user_id() (session OR service key). Never returns salt/password.
	*/

	$userID = goat_acting_user_id();

	$sql = "SELECT mobile, phone, address, suburb, state, postcode, email,
	               emergency_contact, emergency_phone
	        FROM users
	        WHERE id = " . (int) $userID . "
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
		die('{"error":"user not found"}');
	}

	$u = mysql_fetch_object($res);

	echo json_encode(array(
		'user_id'           => (int) $userID,
		'mobile'            => $u->mobile,
		'phone'             => $u->phone,
		'address'           => $u->address,
		'suburb'            => $u->suburb,
		'state'             => $u->state,
		'postcode'          => $u->postcode,
		'email'             => $u->email,
		'emergency_contact' => $u->emergency_contact,
		'emergency_phone'   => $u->emergency_phone
	));

?>