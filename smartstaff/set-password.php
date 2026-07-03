<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SERVICE endpoint — sets a user's SmartStaff password WITHOUT requiring the
	/* current one. Self-scoped via goat_acting_user_id(): the trusted Crew Hub
	/* backend asserts the userID behind the service key (never the client). The
	/* proof of identity is the one-time reset link the person clicked in Crew
	/* Hub, which Crew Hub verifies server-side before this is ever called.
	/*
	/* Password scheme (matches SmartStaff's own login exactly):
	/*   salt     = fresh 40-char hex (sha1 of a random value)
	/*   password = sha1(plaintext . salt)
	/* A fresh salt is generated on every reset. The plaintext exists only long
	/* enough to compute the hash here — it is never stored, logged, or echoed.
	*/

	$userID = goat_acting_user_id();

	if ($_SERVER['REQUEST_METHOD'] !== 'POST')
	{
		http_response_code(405);
		die('{"error":"POST required"}');
	}

	$password = isset($_POST['password']) ? (string) $_POST['password'] : '';

	/* Backstop length check; the Crew Hub UI enforces its own rules too. */
	if (strlen($password) < 8)
	{
		http_response_code(400);
		die('{"error":"password too short"}');
	}

	/*
	/* Confirm the target row exists and is active BEFORE writing. A bogus userID
	/* would otherwise UPDATE zero rows and still look like success.
	*/
	$chk = mysql_query("SELECT id FROM users WHERE id = " . (int) $userID . " AND active = '1' LIMIT 1");
	if ($chk === false || mysql_num_rows($chk) == 0)
	{
		http_response_code(404);
		die('{"error":"user not found or inactive"}');
	}

	/*
	/* Fresh per-user salt (40-char hex), then sha1(plaintext . salt).
	*/
	$salt = sha1(uniqid(mt_rand(), true));
	$hash = sha1($password . $salt);

	/*
	/* OPTIONAL — also clear the login-attempts lockout counter so someone who
	/* locked themselves out is unlocked by resetting. Confirm the exact column
	/* name on your schema first, then uncomment the line below.
	/*   . ", loginAttempts = 0 "
	*/
	$sql = "UPDATE users SET "
	     . "password = '" . mysql_real_escape_string($hash) . "', "
	     . "salt = '"     . mysql_real_escape_string($salt) . "' "
	     . "WHERE id = "  . (int) $userID;

	$result = mysql_query($sql);

	/* Gate on the query result / mysql_error(), NEVER affected_rows — a no-op
	/* (new password equals current) returns 0 rows and would misread as failure. */
	if ($result === false)
	{
		http_response_code(500);
		die('{"error":"update failed: ' . addslashes(mysql_error()) . '"}');
	}

	echo json_encode(array(
		'ok'      => true,
		'user_id' => (int) $userID
	));

?>
