<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — updates one customer's record (admin-gated). Whitelisted
	/* text fields are quoted as strings so a leading zero on postcode survives.
	/* active is an INT flag written as 1/0 (customers.active is INT, unlike the
	/* VARCHAR users.active). Only fields actually present in the POST are written,
	/* so a partial save touches only what was sent. Update success is gated on
	/* mysql_error()/result, NEVER affected_rows (which returns 0 on a no-op save
	/* where submitted values equal what is already stored).
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

	/* Confirm the customer exists before writing. */
	$chk = mysql_query("SELECT id FROM customers WHERE id = " . $id . " LIMIT 1");

	if ($chk === false)
	{
		http_response_code(500);
		die('{"error":"lookup failed: ' . addslashes(mysql_error()) . '"}');
	}

	if (mysql_num_rows($chk) == 0)
	{
		http_response_code(404);
		die('{"error":"customer not found"}');
	}

	$set = array();

	/* customer name: writable, but must not be blanked out. */
	if (isset($_POST['customer_name']))
	{
		$name = trim($_POST['customer_name']);
		if ($name === '')
		{
			http_response_code(400);
			die('{"error":"customer name required"}');
		}
		$set[] = "customer_name = '" . mysql_real_escape_string($name) . "'";
	}

	/* Whitelisted text fields. Quoted as strings so leading zeros survive. */
	$fields = array('phone', 'email', 'address', 'suburb', 'state', 'postcode');

	foreach ($fields as $f)
	{
		if (isset($_POST[$f]))
			$set[] = $f . " = '" . mysql_real_escape_string(trim($_POST[$f])) . "'";
	}

	/* active: INT flag, only 1 or 0 */
	if (isset($_POST['active']))
	{
		$active = ($_POST['active'] === '1') ? 1 : 0;
		$set[] = "active = " . $active;
	}

	if (empty($set))
	{
		http_response_code(400);
		die('{"error":"no fields to update"}');
	}

	$sql = "UPDATE customers SET " . implode(", ", $set) . " WHERE id = " . $id;

	$result = mysql_query($sql);

	if ($result === false)
	{
		http_response_code(500);
		die('{"error":"update failed: ' . addslashes(mysql_error()) . '"}');
	}

	echo json_encode(array(
		'ok'      => true,
		'id'      => $id,
		'updated' => count($set)
	));

?>
