<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN ONLY — assign a crew member's cohort (Operations / Leadership / Crew)
	/* and list who is currently in a non-default cohort.
	/*
	/* The cohort column can grant Leadership or Operations but NEVER Admin —
	/* admin is usergroupID == 1, resolved in cohort.php, and a column value of
	/* 'admin' resolves to 'crew'. So 'admin' is rejected here. All writes are
	/* scoped to usergroupID == 3 so a same-EIN admin/contact row is never touched.
	/*
	/* NOTE: 'cohort' is a shared identity value also read by the Gig Power
	/* website, so a change here changes that user's website privileges too.
	/* Style mirrors manage-elevators.php (raw mysql_*, PHP 5.x-safe,
	/* http_response_code for status, JSON body).
	*/

	if (goat_user_cohort() !== 'admin')
	{
		http_response_code(403);
		die('{"error":"Admin only"}');
	}

	$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'list';

	/*
	/* SET — assign one crew (usergroupID 3) user's cohort. Whitelisted to
	/* {operations, leadership, crew}; the validated value is one of three safe
	/* literals, so direct interpolation is safe.
	*/

	if ($action === 'set')
	{
		$ein    = isset($_REQUEST['ein'])    ? (int) $_REQUEST['ein'] : 0;
		$cohort = isset($_REQUEST['cohort']) ? strtolower(trim($_REQUEST['cohort'])) : '';

		if ($ein <= 0)
		{
			http_response_code(400);
			die('{"error":"missing or invalid ein"}');
		}

		if ($cohort !== 'operations' && $cohort !== 'leadership' && $cohort !== 'crew')
		{
			http_response_code(400);
			die('{"error":"cohort must be operations, leadership or crew"}');
		}

		$chk = mysql_query("SELECT id FROM users WHERE ein = $ein AND usergroupID = 3 LIMIT 1");
		if ($chk === false || mysql_num_rows($chk) == 0)
		{
			http_response_code(404);
			die('{"error":"no crew record with that EIN"}');
		}

		mysql_query("UPDATE users SET cohort = '$cohort' WHERE ein = $ein AND usergroupID = 3");

		/* Gate success on mysql_error(), not affected_rows — a no-op save
		/* (same cohort re-applied) returns 0 affected rows but is not a failure. */
		if (mysql_error())
		{
			http_response_code(500);
			die('{"error":"update failed: ' . addslashes(mysql_error()) . '"}');
		}

		echo json_encode(array('ok' => true, 'ein' => $ein, 'cohort' => $cohort));
		return;
	}

	/*
	/* LIST (default) — crew currently in a non-default cohort (operations or
	/* leadership), with a "Lastname, Firstname" display name. Plain crew default
	/* to 'crew' and are not listed (the assign picker covers promoting them).
	*/

	$sql = "
		SELECT ein,
		       TRIM(CONCAT(TRIM(lastname), ', ', TRIM(firstname))) AS name,
		       LOWER(TRIM(cohort)) AS cohort
		FROM users
		WHERE usergroupID = 3
		  AND LOWER(TRIM(cohort)) IN ('operations', 'leadership')
		ORDER BY name ASC
	";

	$res = mysql_query($sql);

	$members = array();
	if ($res !== false)
	{
		while ($r = mysql_fetch_object($res))
		{
			$members[] = array(
				'ein'    => (int) $r->ein,
				'name'   => isset($r->name)   ? $r->name   : '',
				'cohort' => isset($r->cohort) ? $r->cohort : '',
			);
		}
	}

	echo json_encode(array('members' => $members));

?>
