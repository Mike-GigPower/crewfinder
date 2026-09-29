<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* ADMIN ONLY.
	/*
	/* Booking creation is a write and currently only the (admin-only) Estimate
	/* Import uses it, so gate it strictly. If the Booking tab is later opened to
	/* operations / leadership, widen this then.
	/*
	/* NOTE: align with the same admin gate used by import-lookups-bulk.php.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		send_status(403, 'Forbidden');
		die('{"error":"Admin only"}');
	}

	/*
	/* Creates a booking and its calls in one call, replicating exactly what the
	/* add-booking.php / add-call.php form handlers do for a NEW booking/call:
	/* both are plain single-table inserts via $db->insert() with no side effects
	/* (no calendar rows, no call_crew_map — those only happen on crew
	/* assignment/confirm, which is a separate workflow).
	/*
	/* Field maps (verified against the form handlers):
	/*   bookings : name, creation_date (unix), status (0=open), customerID,
	/*              userID = booking CONTACT, onsiteUserID (falls back to
	/*              contact), venueID, notes, reference
	/*   calls    : bookingID, call_name, start_date (unix), start_time,
	/*              est_length (<- 'length'), notes, required, call_locked=0,
	/*              times_filled, users_can_edit_times, is_pubhol,
	/*              is_pubhol_tomorrow
	/*
	/* MyISAM has no transactions, so we validate the whole payload up front and
	/* only then insert. If a call insert fails after the booking is created, the
	/* booking and earlier calls remain; the failed call indices are returned so
	/* the caller can surface / clean them up.
	/*
	/* PHP 5.x — no null-coalescing (??), no short array syntax.
	*/

	/* ---- helpers ---- */

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

	/* http_response_code() is PHP 5.4+; this host is EOL 5.x, so set the status
	/* line directly off the request protocol for 5.0+ safety. */

	function send_status($code, $msg)
	{
		$proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($proto . ' ' . $code . ' ' . $msg);
	}

	/* ---- parse body ---- */

	$raw     = file_get_contents('php://input');
	$payload = json_decode($raw);

	if (!$payload || !isset($payload->booking))
	{
		send_status(400, 'Bad Request');
		die('{"error":"Invalid or missing JSON body (expected {booking, calls})"}');
	}

	$b     = $payload->booking;
	$calls = (isset($payload->calls) && is_array($payload->calls)) ? $payload->calls : array();

	/* ---- validate up front (no transaction = validate before any insert) ---- */

	$errors = array();

	if (trim(P($b, 'name', '')) === '') $errors[] = 'booking.name is required';
	if (!intval(P($b, 'customer_id', 0))) $errors[] = 'booking.customer_id is required';
	if (!intval(P($b, 'venue_id', 0)))    $errors[] = 'booking.venue_id is required';
	if (!intval(P($b, 'contact_id', 0)))  $errors[] = 'booking.contact_id is required';

	/* FK existence — fail loudly rather than create dangling references */
	if (intval(P($b, 'customer_id', 0)) && !$db->selectFirst('id', 'customers', 'id=' . intval($b->customer_id)))
		$errors[] = 'customer_id not found';
	if (intval(P($b, 'venue_id', 0)) && !$db->selectFirst('id', 'venues', 'id=' . intval($b->venue_id)))
		$errors[] = 'venue_id not found';
	if (intval(P($b, 'contact_id', 0)) && !$db->selectFirst('id', 'users', 'id=' . intval($b->contact_id)))
		$errors[] = 'contact_id not found';

	$onsite = intval(P($b, 'onsite_contact_id', 0));
	if ($onsite && !$db->selectFirst('id', 'users', 'id=' . $onsite))
		$errors[] = 'onsite_contact_id not found';

	foreach ($calls as $i => $c)
	{
		if (trim(P($c, 'call_name', '')) === '') $errors[] = "calls[$i].call_name is required";
		if (to_unix(P($c, 'start_date', '')) <= 0) $errors[] = "calls[$i].start_date is required/invalid";
	}

	if (count($errors))
	{
		send_status(422, 'Unprocessable Entity');
		echo json_encode(array('error' => 'validation failed', 'errors' => $errors));
		die();
	}

	/* ---- insert booking (mirrors add-booking.php) ---- */

	if (!$onsite) $onsite = intval($b->contact_id);   /* onsite falls back to contact */

	$bookingData = array(
		'name'          => $db->sc(P($b, 'name', '')),
		'creation_date' => to_unix(P($b, 'creation_date', 0)),
		'status'        => intval(P($b, 'status', 0)),
		'customerID'    => intval($b->customer_id),
		'userID'        => intval($b->contact_id),
		'onsiteUserID'  => $onsite,
		'venueID'       => intval($b->venue_id),
		'notes'         => $db->sc(P($b, 'notes', '')),
		'reference'     => $db->sc(P($b, 'reference', '')),
	);

	$db->insert('bookings', $bookingData);

	if (mysql_affected_rows() !== 1)
	{
		send_status(500, 'Internal Server Error');
		echo json_encode(array('error' => 'booking insert failed', 'detail' => mysql_error()));
		die();
	}

	$bookingID = (int) $db->insert_id();

	/* ---- insert calls (mirrors add-call.php new-call branch) ---- */

	$callIDs    = array();
	$callErrors = array();

	foreach ($calls as $i => $c)
	{
		$callData = array(
			'bookingID'            => $bookingID,
			'call_name'            => $db->sc(P($c, 'call_name', '')),
			'start_date'           => to_unix(P($c, 'start_date', 0)),
			'start_time'           => $db->sc(trim(P($c, 'start_time', '')) !== '' ? $c->start_time : '00:00:00'),
			'est_length'           => $db->sc(P($c, 'length', 0)),
			'notes'                => $db->sc(P($c, 'notes', '')),
			'required'             => intval(P($c, 'required', 0)),
			'call_locked'          => 0,
			'times_filled'         => intval(P($c, 'times_filled', 0)),
			'users_can_edit_times' => !empty($c->users_can_edit_times) ? 1 : 0,
			'is_pubhol'            => !empty($c->is_pubhol) ? 1 : 0,
			'is_pubhol_tomorrow'   => !empty($c->is_pubhol_tomorrow) ? 1 : 0,
		);

		$db->insert('calls', $callData);

		if (mysql_affected_rows() === 1)
			$callIDs[] = (int) $db->insert_id();
		else
			$callErrors[] = array('index' => $i, 'detail' => mysql_error());
	}

	echo json_encode(array(
		'booking_id'  => $bookingID,
		'call_ids'    => $callIDs,
		'call_errors' => $callErrors,
	));

?>
