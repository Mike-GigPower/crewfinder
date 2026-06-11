<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* ADMIN ONLY.
	/*
	/* This endpoint returns the full customer / venue / contact lookup lists
	/* used by the Estimate Import matcher. Estimate Import is an admin-only
	/* feature (hidden for leadership / operations / crew), and the payload
	/* carries customer + contact PII (phone, email, address), so this must NOT
	/* widen to goat_can_read_all() the way the all-crew read endpoints do.
	/*
	/* NOTE: goat_user_cohort() resolves 'admin' only from usergroupID == 1
	/* (Super Admin). Align this gate with however the other admin-only
	/* endpoints (add-call.php, resize-event.php, relocate-event.php) gate, in
	/* case they use a dedicated helper.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		http_response_code(403);
		die('{"error":"Admin only"}');
	}

	/*
	/* Replaces three paginated HTML scrapes (ss_get_customers / ss_get_venues /
	/* ss_get_contacts) with one round trip. Each block mirrors the {id, name}
	/* shape those scrapes returned, plus the columns that already exist on the
	/* tables so the matcher can use them without further requests.
	/*
	/* Schema notes (verified against smartst_test):
	/*   customers : id, customer_name, phone, email, address, suburb, state,
	/*               postcode, active (INT -> compare = 1)
	/*   venues    : id, venue, address, suburb, state, postcode, has_induction,
	/*               active (INT -> compare = 1)
	/*   contacts  : NOT a table. Contacts are users in usergroup 4 ('Contact'),
	/*               linked to customers via customer_map. NB users.active is
	/*               VARCHAR -> compare = '1' (the long-standing users quirk;
	/*               does NOT apply to venues/customers above).
	/*   customer_map : customerID, userID, default
	/*
	/* Uses the raw mysql_* accessor for consistency with list-crew-bulk.php /
	/* get-calls-bulk.php. (The phpMyAdmin dump header shows PHP 8.4 on the box,
	/* but that's phpMyAdmin's own runtime; the app environment is the one where
	/* the existing mysql_* endpoints already run verified.)
	*/

	/* ── customers ─────────────────────────────────────────────────────────── */

	$customers = array();
	$sql = "SELECT id, customer_name, phone, email, address, suburb, state, postcode
	        FROM customers
	        WHERE active = 1
	        ORDER BY customer_name ASC";
	$res = mysql_query($sql);
	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"customers query failed: ' . addslashes(mysql_error()) . '"}');
	}
	while ($row = mysql_fetch_object($res))
	{
		$customers[] = array(
			'id'       => (int) $row->id,
			'name'     => $row->customer_name,
			'phone'    => $row->phone,
			'email'    => $row->email,
			'address'  => $row->address,
			'suburb'   => $row->suburb,
			'state'    => $row->state,
			'postcode' => $row->postcode,
		);
	}

	/* ── venues ────────────────────────────────────────────────────────────── */

	$venues = array();
	$sql = "SELECT id, venue, address, suburb, state, postcode, has_induction
	        FROM venues
	        WHERE active = 1
	        ORDER BY venue ASC";
	$res = mysql_query($sql);
	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"venues query failed: ' . addslashes(mysql_error()) . '"}');
	}
	while ($row = mysql_fetch_object($res))
	{
		$venues[] = array(
			'id'            => (int) $row->id,
			'name'          => $row->venue,
			'address'       => $row->address,
			'suburb'        => $row->suburb,
			'state'         => $row->state,
			'postcode'      => $row->postcode,
			'has_induction' => (int) $row->has_induction,
		);
	}

	/* ── contacts (users in the 'Contact' usergroup, id = 4) ───────────────── */

	$contacts = array();
	$sql = "SELECT id, firstname, lastname, mobile, email
	        FROM users
	        WHERE usergroupID = 4 AND active = '1'
	        ORDER BY lastname ASC, firstname ASC";
	$res = mysql_query($sql);
	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"contacts query failed: ' . addslashes(mysql_error()) . '"}');
	}
	while ($row = mysql_fetch_object($res))
	{
		/* "Lastname, Firstname" — matches the old /contacts scrape and the
		/* "Last, First" form that fuzzy_match already token-sorts against. */
		$display_name = trim($row->lastname);
		if (strlen(trim($row->firstname)) > 0)
			$display_name .= ', ' . trim($row->firstname);

		$contacts[] = array(
			'id'        => (int) $row->id,
			'name'      => $display_name,
			'firstname' => $row->firstname,
			'lastname'  => $row->lastname,
			'mobile'    => $row->mobile,
			'email'     => $row->email,
		);
	}

	/* ── customer <-> contact map ──────────────────────────────────────────── */
	/* Lets the matcher scope contact candidates to the chosen customer and
	/* prefer the default contact, instead of matching a name against every
	/* contact in the system. `default` is a reserved word — must be quoted in
	/* the SELECT and accessed via {'default'} on the row object. */

	$customer_map = array();
	$sql = "SELECT customerID, userID, `default` FROM customer_map";
	$res = mysql_query($sql);
	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"customer_map query failed: ' . addslashes(mysql_error()) . '"}');
	}
	while ($row = mysql_fetch_object($res))
	{
		$customer_map[] = array(
			'customer_id' => (int) $row->customerID,
			'user_id'     => (int) $row->userID,
			'default'     => (int) $row->{'default'},
		);
	}

	echo json_encode(array(
		'customers'    => $customers,
		'venues'       => $venues,
		'contacts'     => $contacts,
		'customer_map' => $customer_map,
	));

?>
