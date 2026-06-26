<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — one contact's (users, usergroup 4) editable fields plus
	/* its DEFAULT customer link, to pre-fill the GOAT edit form. Admin-gated.
	/* 404 if the id is not a contact (usergroupID != 4). Names are stored
	/* HTML-entity-encoded; decoded here. active is VARCHAR. `default` backticked.
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

	$sql = "SELECT id, username, firstname, lastname, mobile, phone, email,
	               active, notes, usergroupID
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
		die('{"error":"contact not found"}');
	}

	$u = mysql_fetch_object($res);

	if ((int) $u->usergroupID !== 4)
	{
		http_response_code(403);
		die('{"error":"not a contact record"}');
	}

	/* default customer link (customer_map.default = 1), if any */
	$customer_id   = null;
	$customer_name = '';
	$cres = mysql_query("SELECT c.id, c.customer_name
	                     FROM customer_map cm
	                     JOIN customers c ON c.id = cm.customerID
	                     WHERE cm.userID = " . $id . " AND cm.`default` = 1
	                     LIMIT 1");
	if ($cres !== false && mysql_num_rows($cres) > 0)
	{
		$crow = mysql_fetch_object($cres);
		$customer_id   = (int) $crow->id;
		$customer_name = $crow->customer_name;
	}

	echo json_encode(array(
		'id'            => (int) $u->id,
		'username'      => $u->username,
		'firstname'     => html_entity_decode($u->firstname, ENT_QUOTES),
		'lastname'      => html_entity_decode($u->lastname, ENT_QUOTES),
		'mobile'        => $u->mobile,
		'phone'         => $u->phone,
		'email'         => $u->email,
		'active'        => $u->active,
		'notes'         => $u->notes,
		'customer_id'   => $customer_id,
		'customer_name' => $customer_name
	));

?>
