<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* ADMIN ONLY (same gate as update-call.php / update-booking.php).
	/*
	/* Crew Boss status changes (e.g. no-show from the floor) are deferred to a
	/* later phase; for now every status edit from THE GOAT is admin-gated, which
	/* is at least as strict as SmartStaff's native callsheet (confirm/decline are
	/* checkPermissions(1); no-show is callboss-or-admin). */

	if (goat_user_cohort() !== 'admin')
	{
		send_status(403, 'Forbidden');
		die('{"error":"Admin only"}');
	}

	/*
	/* Sets a single crew member's status on a call, replicating add-call.php's
	/* native confirm / decline / no-show handlers:
	/*
	/*   status 5 (confirmed)   : UPDATE status=5  + $sss->addToCalendar(callID,userID)
	/*   status 6 (declined)    : UPDATE status=6  (NO calendar change)
	/*   status 7 (backup)      : UPDATE status=7  (NO calendar change; promoting
	/*                            a backup means setting status 5, which adds it)
	/*   status 8 (no-show)     : UPDATE status=8  (NO calendar change)
	/*   status 1 (pending)     : UPDATE status=1  (NO calendar change; NO SMS sent)
	/*   status 0 (unconfirmed) : UPDATE status=0  (NO calendar change)
	/*
	/* Calendar parity with SmartStaff:
	/*   - confirm ADDS a calendars row, via SmartStaff's own $sss->addToCalendar
	/*     (global, from global.php), so behaviour is byte-identical; we do NOT
	/*     reimplement calendar math.
	/*   - decline / no-show DELIBERATELY leave any existing calendars row in place.
	/*     This matches native add-call.php (only the separate 'remove' action ever
	/*     deletes calendar rows) AND is the desired behaviour: a declined entry at
	/*     a given time tells the operator not to re-offer that resource a clashing
	/*     call.
	/*
	/* No SMS is ever sent. Native status 1 (pending) is only set as a side-effect
	/* of sendsms; here we set it directly with no message.
	/*
	/* Call id via ?id=N. Body JSON {userID:N, status:M}. One crew member per call.
	/*
	/* PHP 5.x -- no null-coalescing (??), no short array syntax. */

	/* ---- helpers (identical to update-call.php) ---- */

	function P($obj, $key, $default = '')
	{
		return (isset($obj->$key) && $obj->$key !== null) ? $obj->$key : $default;
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

	if (!$payload)
	{
		send_status(400, 'Bad Request');
		die('{"error":"Invalid or missing JSON body (expected {userID, status})"}');
	}

	/*
	/* mysql_* and JSON both hand us strings sometimes ("5" vs 5); intval folds
	/* them together so the status === 5 calendar test below is reliable. */

	$userID = intval(P($payload, 'userID', 0));
	$status = intval(P($payload, 'status', -1));

	/* ---- validate ---- */

	$errors = array();

	if ($userID <= 0)
		$errors[] = 'userID is required';

	$allowedStatuses = array(0, 1, 5, 6, 7, 8);   /* 0 unconfirmed, 1 pending, 5 confirmed, 6 declined, 7 backup, 8 no-show */

	if (!in_array($status, $allowedStatuses))
		$errors[] = 'status must be one of 0, 1, 5, 6, 7, 8';

	if (count($errors))
	{
		send_status(422, 'Unprocessable Entity');
		echo json_encode(array('error' => 'validation failed', 'errors' => $errors));
		die();
	}

	/* ---- the crew member must already be assigned to this call ----
	/*
	/* We UPDATE call_crew_map; we never INSERT. If the row is absent the UPDATE is
	/* a silent no-op, so confirm it exists up front. This also lets us gate
	/* success on mysql_error() rather than affected_rows -- a re-save of the same
	/* status changes 0 rows but is NOT a failure. */

	$existingRow = $db->selectFirst('userID', 'call_crew_map', 'callID=' . $callID . ' AND userID=' . $userID);

	if (!$existingRow)
	{
		send_status(404, 'Not Found');
		die('{"error":"Crew member is not assigned to this call"}');
	}

	/* ---- update status ---- */

	$db->update('call_crew_map', array('status' => $db->sc($status)), 'callID=' . $callID . ' AND userID=' . $userID);

	$err         = mysql_error();
	$updAffected = mysql_affected_rows();   /* capture NOW; the calendar call below runs its own queries */

	if ($err !== '')
	{
		send_status(500, 'Internal Server Error');
		echo json_encode(array('error' => 'status update failed', 'detail' => $err));
		die();
	}

	/* ---- calendar parity: confirm ADDS, everything else leaves it alone ----
	/*
	/* Mirrors add-call.php action=confirm: on status 5 we re-sync the crew
	/* member's calendar via SmartStaff's own $sss->addToCalendar (global, from
	/* global.php). Decline / no-show / pending / unconfirmed do not touch the
	/* calendar, matching native and keeping declined entries visible. */

	$calendarSynced = 0;

	if ($status === 5)
	{
		$sss->addToCalendar($callID, $userID);
		$calendarSynced = 1;
	}

	echo json_encode(array(
		'ok'              => true,
		'call_id'         => $callID,
		'booking_id'      => $bookingID,
		'user_id'         => $userID,
		'status'          => $status,
		'calendar_synced' => $calendarSynced,
		'affected_rows'   => $updAffected,
	));

?>
