<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* ADMIN ONLY (same gate as create-booking.php / update-booking.php). */

	if (goat_user_cohort() !== 'admin')
	{
		send_status(403, 'Forbidden');
		die('{"error":"Admin only"}');
	}

	/*
	/* Edits a call's detail fields, replicating add-call.php (action=edit):
	/*
	/*   1. $db->update('calls', dataArray, 'id=N')
	/*   2. select all call_crew_map rows for the call
	/*   3. for each assigned crew member: $sss->addToCalendar($callID, $userID)
	/*      -- re-syncs that crew member's calendar entry to the call's (possibly
	/*         changed) date/time/length. This is REQUIRED: without it, booked
	/*         crew keep stale calendar times after a time edit. We reuse
	/*         SmartStaff's own $sss->addToCalendar (global, from global.php) so the
	/*         calendar behaviour is byte-identical to a SmartStaff edit; we do not
	/*         reimplement calendar math.
	/*
	/* We DELIBERATELY do not write call_locked (or times_filled / edit_times /
	/* pubhol flags). add-call.php only runs the accounting cascade
	/* ($accounting->generateCallData) on a call_locked 0->1 transition; by never
	/* touching call_locked we can never trigger it, and a partial $db->update
	/* leaves those columns untouched.
	/*
	/* Editable field map:
	/*   calls : call_name, start_date (unix), start_time, est_length (<- 'length'),
	/*           required, notes
	/*
	/* Call id via ?id=N. Body JSON {call:{...fields...}}.
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

	/* ---- target call id ---- */

	$callID = isset($_GET['id']) ? intval($_GET['id']) : 0;

	if ($callID <= 0)
	{
		send_status(400, 'Bad Request');
		die('{"error":"Missing or invalid ?id"}');
	}

	$existingCall = $db->selectFirst('id, bookingID', 'calls', 'id=' . $callID);

	if (!$existingCall)
	{
		send_status(404, 'Not Found');
		die('{"error":"Call not found"}');
	}

	$bookingID = (int) $existingCall->bookingID;

	/* ---- parse body ---- */

	$raw     = file_get_contents('php://input');
	$payload = json_decode($raw);

	if (!$payload || !isset($payload->call))
	{
		send_status(400, 'Bad Request');
		die('{"error":"Invalid or missing JSON body (expected {call})"}');
	}

	$c = $payload->call;

	/* ---- validate ---- */

	$errors = array();

	if (trim(P($c, 'call_name', '')) === '')    $errors[] = 'call.call_name is required';
	if (to_unix(P($c, 'start_date', '')) <= 0)  $errors[] = 'call.start_date is required/invalid';

	if (count($errors))
	{
		send_status(422, 'Unprocessable Entity');
		echo json_encode(array('error' => 'validation failed', 'errors' => $errors));
		die();
	}

	/* ---- update call (mirrors add-call.php action=edit, editable subset) ---- */

	$callData = array(
		'call_name'   => $db->sc(P($c, 'call_name', '')),
		'start_date'  => to_unix(P($c, 'start_date', 0)),
		'start_time'  => $db->sc(trim(P($c, 'start_time', '')) !== '' ? $c->start_time : '00:00:00'),
		'est_length'  => $db->sc(P($c, 'length', 0)),
		'required'    => intval(P($c, 'required', 0)),
		'notes'       => $db->sc(P($c, 'notes', '')),
	);

	$db->update('calls', $callData, 'id=' . $callID);

	$err          = mysql_error();
	$updAffected  = mysql_affected_rows();   /* capture NOW; the calendar loop below runs its own queries */

	if ($err !== '')
	{
		send_status(500, 'Internal Server Error');
		echo json_encode(array('error' => 'call update failed', 'detail' => $err));
		die();
	}

	/* ---- re-sync calendars for assigned crew (mirrors add-call.php edit) ---- */

	$synced   = 0;
	$callCrew = $db->select('*', 'call_crew_map', 'callID = ' . $callID);

	if (is_array($callCrew) && count($callCrew) > 0)
	{
		foreach ($callCrew as $crew)
		{
			$sss->addToCalendar($callID, intval($crew->userID));
			$synced++;
		}
	}

	echo json_encode(array(
		'ok'            => true,
		'call_id'       => $callID,
		'booking_id'    => $bookingID,
		'crew_synced'   => $synced,
		'affected_rows' => $updAffected,
	));

?>
