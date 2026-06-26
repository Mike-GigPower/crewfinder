<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — returns one customer's editable fields to pre-fill the
	/* GOAT admin edit form. Admin-gated. Returns inactive customers too, so they
	/* can be reactivated from the form (no active filter here).
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

	$sql = "SELECT id, customer_name, phone, email, address, suburb,
	               state, postcode, active
	        FROM customers
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
		die('{"error":"customer not found"}');
	}

	$c = mysql_fetch_object($res);

	/* associated contacts (users in usergroup 4 linked via customer_map), for the
	/* read-only contacts table in the customer edit view. `default` backticked;
	/* the default contact sorts first. Names are entity-encoded -> decoded. */
	$contacts = array();
	$ctres = mysql_query("SELECT u.id, u.firstname, u.lastname, u.mobile, u.email, u.active, cm.`default` AS is_default
	                      FROM customer_map cm
	                      JOIN users u ON u.id = cm.userID
	                      WHERE cm.customerID = " . $id . " AND u.usergroupID = 4
	                      ORDER BY cm.`default` DESC, u.lastname ASC, u.firstname ASC");
	if ($ctres !== false)
	{
		while ($ctrow = mysql_fetch_object($ctres))
		{
			$cname = trim(html_entity_decode($ctrow->firstname, ENT_QUOTES) . ' ' . html_entity_decode($ctrow->lastname, ENT_QUOTES));
			$contacts[] = array(
				'id'         => (int) $ctrow->id,
				'name'       => $cname,
				'mobile'     => $ctrow->mobile,
				'email'      => $ctrow->email,
				'active'     => (int) $ctrow->active,
				'is_default' => (int) $ctrow->is_default
			);
		}
	}

	echo json_encode(array(
		'id'            => (int) $c->id,
		'customer_name' => $c->customer_name,
		'phone'         => $c->phone,
		'email'         => $c->email,
		'address'       => $c->address,
		'suburb'        => $c->suburb,
		'state'         => $c->state,
		'postcode'      => $c->postcode,
		'active'        => (int) $c->active,
		'contacts'      => $contacts
	));

?>
