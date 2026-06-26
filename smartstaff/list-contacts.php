<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — {id, name, active, customer_id, customer_name} for every
	/* CONTACT, for the Manage Contacts browse list. The app filters by active
	/* (?active=). Admin-gated.
	/*
	/* Contacts are users in usergroup 4 ('Contact'). This scopes strictly to
	/* usergroupID = 4 — a genuine improvement over SmartStaff's native /contacts
	/* page, which also lists crew. customer_name is the contact's DEFAULT customer
	/* (customer_map.default = 1), if any. `default` is a reserved word -> backticked.
	/* NB users.active is VARCHAR; cast to int here for the app's active filter.
	/* Names are stored HTML-entity-encoded; decoded for display.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		http_response_code(403);
		die('{"error":"Admin only"}');
	}

	$contacts = array();
	$sql = "SELECT u.id, u.firstname, u.lastname, u.active,
	               c.id AS customer_id, c.customer_name
	        FROM users u
	        LEFT JOIN customer_map cm ON cm.userID = u.id AND cm.`default` = 1
	        LEFT JOIN customers c ON c.id = cm.customerID
	        WHERE u.usergroupID = 4
	        ORDER BY u.lastname ASC, u.firstname ASC";
	$res = mysql_query($sql);
	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"contacts query failed: ' . addslashes(mysql_error()) . '"}');
	}
	while ($row = mysql_fetch_object($res))
	{
		$name = trim(html_entity_decode($row->firstname, ENT_QUOTES) . ' ' . html_entity_decode($row->lastname, ENT_QUOTES));
		$contacts[] = array(
			'id'            => (int) $row->id,
			'name'          => $name,
			'active'        => (int) $row->active,
			'customer_id'   => $row->customer_id ? (int) $row->customer_id : null,
			'customer_name' => $row->customer_name ? $row->customer_name : ''
		);
	}

	echo json_encode(array('contacts' => $contacts));

?>
