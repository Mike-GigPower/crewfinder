<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — updates one contact (users, usergroup 4). Admin-gated and
	/* guarded to usergroupID == 4 so it can never touch a crew or admin account.
	/*
	/* - core text fields quoted as strings.
	/* - username editable, validated unique across users (login identity).
	/* - active is VARCHAR '1'/'0' (the users quirk; NOT the INT that
	/*   customers/venues use).
	/* - password, when non-blank, hashed sha1($pw . $salt) with a fresh salt,
	/*   matching SmartStaff login byte-for-byte (same scheme as update-crew.php).
	/* - customer_id sets the contact's DEFAULT customer in customer_map
	/*   non-destructively: clears existing default flags, then upserts the chosen
	/*   customer as default (no rows deleted). customer_id = 0 clears the default.
	/* Write success gated on mysql_error()/result, never affected_rows.
	/* `default` is a reserved word -> backticked.
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

	/* Confirm the target is a contact (usergroupID 4). */
	$chk = mysql_query("SELECT usergroupID FROM users WHERE id = " . $id . " LIMIT 1");

	if ($chk === false)
	{
		http_response_code(500);
		die('{"error":"lookup failed: ' . addslashes(mysql_error()) . '"}');
	}

	if (mysql_num_rows($chk) == 0)
	{
		http_response_code(404);
		die('{"error":"contact not found"}');
	}

	$chkrow = mysql_fetch_object($chk);

	if ((int) $chkrow->usergroupID !== 4)
	{
		http_response_code(403);
		die('{"error":"not a contact record"}');
	}

	$set = array();

	/* username: editable, must be non-blank and unique across all users. */
	if (isset($_POST['username']))
	{
		$un = trim($_POST['username']);
		if ($un === '')
		{
			http_response_code(400);
			die('{"error":"username required"}');
		}
		$unEsc = mysql_real_escape_string($un);
		$dup = mysql_query("SELECT id FROM users WHERE username = '" . $unEsc . "' AND id != " . $id . " LIMIT 1");
		if ($dup !== false && mysql_num_rows($dup) > 0)
		{
			http_response_code(409);
			die('{"error":"username already in use"}');
		}
		$set[] = "username = '" . $unEsc . "'";
	}

	/* core text fields. */
	$fields = array('firstname', 'lastname', 'mobile', 'phone', 'email', 'notes');

	foreach ($fields as $f)
	{
		if (isset($_POST[$f]))
			$set[] = $f . " = '" . mysql_real_escape_string(trim($_POST[$f])) . "'";
	}

	/* active: VARCHAR '1'/'0' */
	if (isset($_POST['active']))
	{
		$active = ($_POST['active'] === '1') ? '1' : '0';
		$set[] = "active = '" . $active . "'";
	}

	/* password: only when a non-blank value is supplied */
	if (isset($_POST['password']) && trim($_POST['password']) !== '')
	{
		$pw   = trim($_POST['password']);
		$salt = sha1(uniqid(mt_rand(), true));
		$hash = sha1($pw . $salt);
		$set[] = "salt = '" . mysql_real_escape_string($salt) . "'";
		$set[] = "password = '" . mysql_real_escape_string($hash) . "'";
	}

	if (empty($set) && !isset($_POST['customer_id']))
	{
		http_response_code(400);
		die('{"error":"no fields to update"}');
	}

	if (!empty($set))
	{
		$sql = "UPDATE users SET " . implode(", ", $set) . " WHERE id = " . $id;
		$result = mysql_query($sql);
		if ($result === false)
		{
			http_response_code(500);
			die('{"error":"update failed: ' . addslashes(mysql_error()) . '"}');
		}
	}

	/* customer link: set the contact's DEFAULT customer non-destructively. */
	if (isset($_POST['customer_id']))
	{
		$cid = (int) $_POST['customer_id'];

		mysql_query("UPDATE customer_map SET `default` = 0 WHERE userID = " . $id);
		if (mysql_error() !== '')
		{
			http_response_code(500);
			die('{"error":"customer link clear failed: ' . addslashes(mysql_error()) . '"}');
		}

		if ($cid > 0)
		{
			$cv = mysql_query("SELECT id FROM customers WHERE id = " . $cid . " LIMIT 1");
			if ($cv === false)
			{
				http_response_code(500);
				die('{"error":"customer lookup failed: ' . addslashes(mysql_error()) . '"}');
			}
			if (mysql_num_rows($cv) == 0)
			{
				http_response_code(400);
				die('{"error":"customer not found"}');
			}

			$ex = mysql_query("SELECT customerID FROM customer_map WHERE userID = " . $id . " AND customerID = " . $cid . " LIMIT 1");
			if ($ex !== false && mysql_num_rows($ex) > 0)
				mysql_query("UPDATE customer_map SET `default` = 1 WHERE userID = " . $id . " AND customerID = " . $cid);
			else
				mysql_query("INSERT INTO customer_map (customerID, userID, `default`) VALUES (" . $cid . ", " . $id . ", 1)");

			if (mysql_error() !== '')
			{
				http_response_code(500);
				die('{"error":"customer link write failed: ' . addslashes(mysql_error()) . '"}');
			}
		}
	}

	echo json_encode(array(
		'ok'      => true,
		'id'      => $id,
		'updated' => count($set)
	));

?>
