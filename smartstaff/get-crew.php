<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — returns one crew member's editable fields to pre-fill
	/* the GOAT admin edit form. Admin-gated. Never returns salt/password.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		http_response_code(403);
		die('{"error":"Admin only"}');
	}

	$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

	if ($id <= 0)
	{
		http_response_code(400);
		die('{"error":"id required"}');
	}

	$sql = "SELECT id, firstname, lastname, ein, mobile, phone, dob, address,
	               suburb, state, postcode, email, emergency_contact,
	               emergency_phone, active, rating, notes
	        FROM users
	        WHERE id = " . $id . "
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
		die('{"error":"crew member not found"}');
	}

	$u = mysql_fetch_object($res);

	/* Names are stored HTML-entity-encoded; decode for display in the form. */
	$dobOut = '';
	if ($u->dob !== '' && (int) $u->dob != 0)
	{
		$dt = new DateTime('@' . (int) $u->dob);   /* @ts is UTC */
		$dt->setTimezone(new DateTimeZone('Australia/Melbourne'));
		$dobOut = $dt->format('Y-m-d');
	}

	/* group memberships + master list for the edit-form checkboxes */
	$allGroups = array();
	$gres = mysql_query("SELECT id, group_name FROM crew_groups ORDER BY group_name ASC");
	if ($gres !== false)
	{
		while ($grow = mysql_fetch_object($gres))
			$allGroups[] = array('id' => (int) $grow->id, 'name' => $grow->group_name);
	}
	$groupIds = array();
	$mres = mysql_query("SELECT groupID FROM crew_groups_map WHERE userID = " . $id);
	if ($mres !== false)
	{
		while ($mrow = mysql_fetch_object($mres))
			$groupIds[] = (int) $mrow->groupID;
	}

	echo json_encode(array(
		'id'                => (int) $u->id,
		'firstname'         => html_entity_decode($u->firstname, ENT_QUOTES),
		'lastname'          => html_entity_decode($u->lastname, ENT_QUOTES),
		'ein'               => $u->ein,
		'mobile'            => $u->mobile,
		'phone'             => $u->phone,
		'dob'               => $dobOut,
		'address'           => $u->address,
		'suburb'            => $u->suburb,
		'state'             => $u->state,
		'postcode'          => $u->postcode,
		'email'             => $u->email,
		'emergency_contact' => $u->emergency_contact,
		'emergency_phone'   => $u->emergency_phone,
		'active'            => $u->active,
		'rating'            => (int) $u->rating,
		'notes'             => $u->notes,
		'all_groups'        => $allGroups,
		'group_ids'         => $groupIds
	));

?>
