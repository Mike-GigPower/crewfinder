<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN ONLY — feeds THE GOAT's Administration > "Add User" (crew) form:
	/* the crew-group checklist and the next EIN to assign. Adding crew is an
	/* admin-only write action, so this lookup is gated to admins
	/* (usergroupID == 1) too. It must NOT widen to goat_can_read_all().
	/*
	/* Style mirrors import-lookups-bulk.php / manage-elevators.php: raw mysql_*,
	/* PHP 5.x-safe (no ?? operator, no short-array []), http_response_code() for
	/* error status, JSON body.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		http_response_code(403);
		die('{"error":"Admin only"}');
	}

	/* ── crew groups (id, group_name) ──────────────────────────────────────── */
	/* The bookable skill/role tags. crew/add writes membership into
	/* crew_groups_map (userID, groupID); these are the available groupIDs. */

	$crew_groups = array();
	$sql = "SELECT id, group_name FROM crew_groups ORDER BY group_name ASC";
	$res = mysql_query($sql);
	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"crew_groups query failed: ' . addslashes(mysql_error()) . '"}');
	}
	while ($row = mysql_fetch_object($res))
	{
		$crew_groups[] = array(
			'id'   => (int) $row->id,
			'name' => $row->group_name,
		);
	}

	/* ── next EIN — max(ein)+1, mirroring crew/add's server-side prefill ────── */

	$next_ein = 0;
	$res = mysql_query("SELECT MAX(ein) AS max_ein FROM users");
	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"ein query failed: ' . addslashes(mysql_error()) . '"}');
	}
	$row = mysql_fetch_object($res);
	$next_ein = ((int) $row->max_ein) + 1;

	echo json_encode(array(
		'crew_groups' => $crew_groups,
		'next_ein'    => $next_ein,
	));

?>
