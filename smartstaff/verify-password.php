<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SELF endpoint — verify the acting user's CURRENT password, for the crew
	/* app's logged-in "change password" flow. Self-scoped via
	/* goat_acting_user_id() (session OR service key), same as my-details.php.
	/*
	/* Never returns the stored hash or salt — only a boolean. Wrong password is
	/* a normal { "valid": false } (HTTP 200), NOT an error, so the app can tell
	/* "wrong password" apart from an outage.
	/*
	/* Params:  password=<current password>   (POST)
	/* Returns: { "ok": true, "valid": true|false }
	*/

	$userID = goat_acting_user_id();

	$password = isset($_POST['password']) ? $_POST['password'] : '';

	if ($password === '')
	{
		echo json_encode(array('ok' => true, 'valid' => false));
		die();
	}

	$sql = "SELECT password, salt
	        FROM users
	        WHERE id = " . (int) $userID . " AND active = '1'
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
		die('{"error":"user not found or inactive"}');
	}

	$u = mysql_fetch_object($res);

	/*
	/* HASHING — matches set-password.php / SmartStaff login exactly:
	/*   password = sha1(plaintext . salt)   (plaintext FIRST, then salt)
	*/

	$computed = sha1($password . $u->salt);

	$valid = hash_equals((string) $u->password, (string) $computed);

	echo json_encode(array('ok' => true, 'valid' => $valid));

?>
