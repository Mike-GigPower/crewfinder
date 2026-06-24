<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* VERIFY-CREDENTIALS — service-only identity check for the Crew API's
	/* verify-then-mint login. Confirms an EIN + password against SmartStaff's
	/* own password scheme and returns identity + resolved cohort. Mints NO
	/* session and stores nothing: the password is used once, then the caller
	/* discards it. Gated SOLELY by the service secret (X-Goat-Service-Key) —
	/* there is no user session here.
	*/

	$key = isset($_SERVER['HTTP_X_GOAT_SERVICE_KEY'])
	     ? $_SERVER['HTTP_X_GOAT_SERVICE_KEY'] : '';

	if (!goat_service_key_ok($key))
	{
		http_response_code(403);
		die('{"error":"Forbidden"}');
	}

	$ein = isset($_POST['ein'])      ? trim($_POST['ein']) : '';
	$pw  = isset($_POST['password']) ? $_POST['password']  : '';

	if ($ein === '' || $pw === '')
	{
		http_response_code(400);
		die('{"error":"ein and password required"}');
	}

	$ein_esc = $db->sc($ein);   /* same escaping helper the other endpoints use */

	$sql = "SELECT id, ein, firstname, lastname, usergroupID, salt, password, active
	        FROM users
	        WHERE ein = $ein_esc
	        LIMIT 1";

	$res = mysql_query($sql);

	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"lookup failed"}');
	}

	if (mysql_num_rows($res) == 0)
	{
		http_response_code(401);
		die('{"error":"Invalid credentials"}');
	}

	$u = mysql_fetch_object($res);

	/*
	/* SmartStaff scheme (class.user.php): sha1(password . salt) must equal the
	/* stored hash, account must be active. Constant-time compare on the hash.
	*/
	$ok = ((int) $u->active === 1)
	      && goat_hash_equals($u->password, sha1($pw . $u->salt));

	if (!$ok)
	{
		http_response_code(401);
		die('{"error":"Invalid credentials"}');
	}

	$cohort = goat_cohort_for_user((int) $u->id);

	echo json_encode(array(
		'user_id'   => (int) $u->id,
		'ein'       => $u->ein,
		'firstname' => $u->firstname,
		'lastname'  => $u->lastname,
		'cohort'    => $cohort,
	));

?>