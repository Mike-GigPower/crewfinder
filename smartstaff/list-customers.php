<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — {id, name, active} for EVERY customer (active and
	/* inactive), for the Manage Customers browse list. The app filters by active
	/* (?active=), mirroring crew-list. Admin-gated.
	/*
	/* customers.active is INT (compare = 1), unlike the VARCHAR users.active.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		http_response_code(403);
		die('{"error":"Admin only"}');
	}

	$customers = array();
	$sql = "SELECT id, customer_name, active FROM customers ORDER BY customer_name ASC";
	$res = mysql_query($sql);
	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"customers query failed: ' . addslashes(mysql_error()) . '"}');
	}
	while ($row = mysql_fetch_object($res))
	{
		$customers[] = array(
			'id'     => (int) $row->id,
			'name'   => $row->customer_name,
			'active' => (int) $row->active
		);
	}

	echo json_encode(array('customers' => $customers));

?>
