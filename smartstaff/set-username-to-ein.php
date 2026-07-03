<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SERVICE endpoint — resets a user's login name (users.username) back to
	/* their EIN. Used by Crew Hub's "forgot my login" flow: crew log in with
	/* their EIN by default, so if they've changed and forgotten it, we set it
	/* back to the one value that always identifies them. Self-scoped via
	/* goat_acting_user_id() (the Crew Hub backend asserts the userID behind the
	/* service key; the client never supplies it). Returns the EIN so Crew Hub
	/* can tell the person what their login now is.
	*/

	$userID = goat_acting_user_id();

	if ($_SERVER['REQUEST_METHOD'] !== 'POST')
	{
		http_response_code(405);
		die('{"error":"POST required"}');
	}

	/*
	/* Read the row first so we return the EIN and confirm the user is active.
	*/
	$row = mysql_query("SELECT id, ein FROM users WHERE id = " . (int) $userID . " AND active = '1' LIMIT 1");
	if ($row === false || mysql_num_rows($row) == 0)
	{
		http_response_code(404);
		die('{"error":"user not found or inactive"}');
	}

	$u   = mysql_fetch_object($row);
	$ein = (int) $u->ein;

	if ($ein <= 0)
	{
		http_response_code(409);
		die('{"error":"no EIN on record"}');
	}

	/*
	/* Set username to the EIN. EINs are unique, so this won't collide with
	/* another crew member. username is varchar; the int EIN is stored as its
	/* string form.
	*/
	$sql    = "UPDATE users SET username = '" . mysql_real_escape_string((string) $ein) . "' WHERE id = " . (int) $userID;
	$result = mysql_query($sql);

	if ($result === false)
	{
		http_response_code(500);
		die('{"error":"update failed: ' . addslashes(mysql_error()) . '"}');
	}

	echo json_encode(array(
		'ok'       => true,
		'user_id'  => (int) $userID,
		'username' => (string) $ein
	));

?>
