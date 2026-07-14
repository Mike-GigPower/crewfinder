<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — deletes one customer (admin-gated, POST-only).
	/* GUARDED HARD DELETE: refuses if the customer still has bookings
	/* (bookings.customerID) — real work history must not be orphaned (MyISAM has
	/* no FK enforcement, so nothing stops an orphan but this check). The
	/* customer_map join rows (contact links) carry no meaning once the customer
	/* is gone, so they are auto-cleaned, not blocked on. Delete success gated on
	/* mysql_error(), never affected_rows.
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

	/* Confirm the customer exists before doing anything. */
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

	/* DEPENDENCY GUARD: block if any bookings reference this customer. */
	$dep = mysql_query("SELECT COUNT(*) AS n FROM bookings WHERE customerID = " . $id);

	if ($dep === false)
	{
		http_response_code(500);
		die('{"error":"dependency check failed: ' . addslashes(mysql_error()) . '"}');
	}

	$deprow = mysql_fetch_object($dep);
	$booking_count = (int) $deprow->n;

	if ($booking_count > 0)
	{
		http_response_code(409);
		die(json_encode(array(
			'error'    => "Can't delete — " . $booking_count . " booking(s) still linked to this customer.",
			'bookings' => $booking_count
		)));
	}

	/* Auto-clean the contact-link join rows (no meaning once the customer is gone). */
	mysql_query("DELETE FROM customer_map WHERE customerID = " . $id);

	if (mysql_error() !== '')
	{
		http_response_code(500);
		die('{"error":"contact-link cleanup failed: ' . addslashes(mysql_error()) . '"}');
	}

	$unlinked = mysql_affected_rows();

	/* Delete the customer. Gate on mysql_error(), not affected_rows. */
	mysql_query("DELETE FROM customers WHERE id = " . $id);

	if (mysql_error() !== '')
	{
		http_response_code(500);
		die('{"error":"delete failed: ' . addslashes(mysql_error()) . '"}');
	}

	echo json_encode(array(
		'ok'                => true,
		'id'                => $id,
		'deleted'           => 'customer',
		'contacts_unlinked' => $unlinked
	));

?>
