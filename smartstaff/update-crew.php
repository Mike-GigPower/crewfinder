<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — updates one crew member's record (admin-gated).
	/* Whitelisted text fields are quoted as strings so leading zeros on
	/* phones/postcodes survive. active is validated to '1'/'0'; rating is
	/* cast to int. firstname/lastname are intentionally NOT writable here yet
	/* (their stored entity-encoding needs verifying first). Password, when a
	/* non-blank value is supplied, is hashed in PHP with SmartStaff's
	/* sha1($pw . $salt) scheme using a freshly generated salt, so it matches
	/* login byte-for-byte and sidesteps the latin1/utf8mb4 collation error.
	/* Blank password leaves salt/password untouched.
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

	/* Confirm the target is a crew record (usergroupID 3). This tool must not
	/* be used to edit admin / operator login accounts. */
	$chk = mysql_query("SELECT usergroupID FROM users WHERE id = " . $id . " LIMIT 1");

	if ($chk === false)
	{
		http_response_code(500);
		die('{"error":"lookup failed: ' . addslashes(mysql_error()) . '"}');
	}

	if (mysql_num_rows($chk) == 0)
	{
		http_response_code(404);
		die('{"error":"crew member not found"}');
	}

	$chkrow = mysql_fetch_object($chk);

	if ((int) $chkrow->usergroupID !== 3)
	{
		http_response_code(403);
		die('{"error":"not a crew record"}');
	}

	/* Whitelisted text fields. Only those actually present are written, so a
	/* partial save touches only what was sent. */
	$fields = array('firstname', 'lastname', 'mobile', 'phone', 'address', 'suburb', 'state',
	                'postcode', 'email', 'emergency_contact', 'emergency_phone',
	                'notes');

	$set = array();

	foreach ($fields as $f)
	{
		if (isset($_POST[$f]))
			$set[] = $f . " = '" . mysql_real_escape_string(trim($_POST[$f])) . "'";
	}

	/* dob: stored as a unix timestamp; the form sends YYYY-MM-DD (or blank).
	/* Pin to Australia/Melbourne so the date round-trips regardless of the
	/* server's default timezone. */
	if (isset($_POST['dob']))
	{
		$dobRaw = trim($_POST['dob']);
		if ($dobRaw === '')
		{
			$set[] = "dob = 0";
		}
		else
		{
			try
			{
				$dt = new DateTime($dobRaw . ' 00:00:00', new DateTimeZone('Australia/Melbourne'));
				$set[] = "dob = " . (int) $dt->getTimestamp();
			}
			catch (Exception $e) { /* unparseable date -> skip rather than corrupt */ }
		}
	}

	/* active: only '1' or '0' */
	if (isset($_POST['active']))
	{
		$active = ($_POST['active'] === '1') ? '1' : '0';
		$set[] = "active = '" . $active . "'";
	}

	/* rating: integer */
	if (isset($_POST['rating']) && $_POST['rating'] !== '')
		$set[] = "rating = " . (int) $_POST['rating'];

	/* password: only when a non-blank value is supplied */
	if (isset($_POST['password']) && trim($_POST['password']) !== '')
	{
		$pw   = trim($_POST['password']);
		$salt = sha1(uniqid(mt_rand(), true));
		$hash = sha1($pw . $salt);
		$set[] = "salt = '" . mysql_real_escape_string($salt) . "'";
		$set[] = "password = '" . mysql_real_escape_string($hash) . "'";
	}

	if (empty($set) && !isset($_POST['groups']))
	{
		http_response_code(400);
		die('{"error":"no fields to update"}');
	}

	if (!empty($set))
	{
		$sql = "UPDATE users SET " . implode(", ", $set) . " WHERE id = " . $id;

		$result = mysql_query($sql);

		/* Gate on result / mysql_error(), NEVER affected_rows (returns 0 on a
		/* no-op save where the submitted values equal what is already stored). */
		if ($result === false)
		{
			http_response_code(500);
			die('{"error":"update failed: ' . addslashes(mysql_error()) . '"}');
		}
	}

	/* group membership: full-replace from the posted CSV of crew_groups ids.
	/* Mirrors SmartStaff's own checkbox form -- client sends the complete set. */
	if (isset($_POST['groups']))
	{
		$want = array();
		foreach (explode(',', trim($_POST['groups'])) as $g)
		{
			$gid = (int) trim($g);
			if ($gid > 0) $want[$gid] = $gid;
		}

		$valid = array();
		if (!empty($want))
		{
			$vres = mysql_query("SELECT id FROM crew_groups");
			if ($vres !== false)
			{
				while ($v = mysql_fetch_object($vres))
					if (isset($want[(int) $v->id])) $valid[] = (int) $v->id;
			}
		}

		mysql_query("DELETE FROM crew_groups_map WHERE userID = " . $id);
		if (mysql_error() !== '')
		{
			http_response_code(500);
			die('{"error":"group clear failed: ' . addslashes(mysql_error()) . '"}');
		}

		if (!empty($valid))
		{
			$rows = array();
			foreach ($valid as $gid)
				$rows[] = "(" . $id . ", " . $gid . ")";
			mysql_query("INSERT INTO crew_groups_map (userID, groupID) VALUES " . implode(", ", $rows));
			if (mysql_error() !== '')
			{
				http_response_code(500);
				die('{"error":"group insert failed: ' . addslashes(mysql_error()) . '"}');
			}
		}
	}

	echo json_encode(array(
		'ok'      => true,
		'id'      => $id,
		'updated' => count($set)
	));

?>
