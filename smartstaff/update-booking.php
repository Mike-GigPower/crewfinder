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
	/* Booking edit is a write; gate it the same way create-booking.php does.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		send_status(403, 'Forbidden');
		die('{"error":"Admin only"}');
	}

	/*
	/* Edits a booking's detail fields, replicating the SAFE subset of what
	/* add-booking.php (action=edit) does: a plain single-table
	/* $db->update('bookings', ...) of the detail columns. NO cascade.
	/*
	/* add-booking.php's ONLY cascade fires when status is set to 1 (closing) ->
	/* it generates accounting/invoice data and locks the unclosed calls. We
	/* deliberately DO NOT write the status column here, so that path can never be
	/* triggered from an edit. Closing a booking stays a SmartStaff action.
	/*
	/* Field map (verified against add-booking.php action=edit):
	/*   bookings : name, creation_date (unix), customerID, userID = CONTACT,
	/*              onsiteUserID (falls back to contact), venueID, notes, reference
	/*
	/* Booking id comes in via ?id=N (mirrors get-booking.php / SmartStaff's own
	/* /bookings/edit/N). Body is JSON {booking:{...fields...}}.
	/*
	/* PHP 5.x — no null-coalescing (??), no short array syntax.
	*/

	/* ---- helpers (identical to create-booking.php) ---- */

	function P($obj, $key, $default = '')
	{
		return (isset($obj->$key) && $obj->$key !== null) ? $obj->$key : $default;
	}

	function to_unix($v)
	{
		if ($v === '' || $v === null) return 0;
		if (is_numeric($v))           return intval($v);
		$t = strtotime($v);                 /* Australia/Melbourne tz, set in global.php */
		return $t ? $t : 0;
	}

	function send_status($code, $msg)
	{
		$proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($proto . ' ' . $code . ' ' . $msg);
	}

	/* ---- target booking id ---- */

	$bookingID = isset($_GET['id']) ? intval($_GET['id']) : 0;

	if ($bookingID <= 0)
	{
		send_status(400, 'Bad Request');
		die('{"error":"Missing or invalid ?id"}');
	}

	$existing = $db->selectFirst('id', 'bookings', 'id=' . $bookingID);

	if (!$existing)
	{
		send_status(404, 'Not Found');
		die('{"error":"Booking not found"}');
	}

	/* ---- parse body ---- */

	$raw     = file_get_contents('php://input');
	$payload = json_decode($raw);

	if (!$payload || !isset($payload->booking))
	{
		send_status(400, 'Bad Request');
		die('{"error":"Invalid or missing JSON body (expected {booking})"}');
	}

	$b = $payload->booking;

	/* ---- validate (same rules as create) ---- */

	$errors = array();

	if (trim(P($b, 'name', '')) === '')   $errors[] = 'booking.name is required';
	if (!intval(P($b, 'customer_id', 0))) $errors[] = 'booking.customer_id is required';
	if (!intval(P($b, 'venue_id', 0)))    $errors[] = 'booking.venue_id is required';
	if (!intval(P($b, 'contact_id', 0)))  $errors[] = 'booking.contact_id is required';

	/* FK existence — fail loudly rather than write dangling references */
	if (intval(P($b, 'customer_id', 0)) && !$db->selectFirst('id', 'customers', 'id=' . intval($b->customer_id)))
		$errors[] = 'customer_id not found';
	if (intval(P($b, 'venue_id', 0)) && !$db->selectFirst('id', 'venues', 'id=' . intval($b->venue_id)))
		$errors[] = 'venue_id not found';
	if (intval(P($b, 'contact_id', 0)) && !$db->selectFirst('id', 'users', 'id=' . intval($b->contact_id)))
		$errors[] = 'contact_id not found';

	$onsite = intval(P($b, 'onsite_contact_id', 0));
	if ($onsite && !$db->selectFirst('id', 'users', 'id=' . $onsite))
		$errors[] = 'onsite_contact_id not found';

	if (count($errors))
	{
		send_status(422, 'Unprocessable Entity');
		echo json_encode(array('error' => 'validation failed', 'errors' => $errors));
		die();
	}

	/* ---- update booking (mirrors add-booking.php action=edit, minus status) ---- */

	if (!$onsite) $onsite = intval($b->contact_id);   /* onsite falls back to contact */

	$bookingData = array(
		'name'          => $db->sc(P($b, 'name', '')),
		'creation_date' => to_unix(P($b, 'creation_date', 0)),
		'customerID'    => intval($b->customer_id),
		'userID'        => intval($b->contact_id),
		'onsiteUserID'  => $onsite,
		'venueID'       => intval($b->venue_id),
		'notes'         => $db->sc(P($b, 'notes', '')),
		'reference'     => $db->sc(P($b, 'reference', '')),
	);

	$db->update('bookings', $bookingData, 'id=' . $bookingID);

	/*
	/* NOTE: UPDATE affected-rows is 0 when the submitted values equal what is
	/* already stored (MySQL reports CHANGED rows, not MATCHED). So we do NOT gate
	/* success on affected_rows here — we gate on mysql_error() instead, having
	/* already confirmed the row exists above.
	*/

	$err = mysql_error();

	if ($err !== '')
	{
		send_status(500, 'Internal Server Error');
		echo json_encode(array('error' => 'booking update failed', 'detail' => $err));
		die();
	}

	echo json_encode(array(
		'ok'             => true,
		'booking_id'     => $bookingID,
		'affected_rows'  => mysql_affected_rows(),
	));

?>
