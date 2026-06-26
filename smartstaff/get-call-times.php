<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* ADMIN ONLY (same gate as update-call-times.php). */

	if (goat_user_cohort() !== 'admin')
	{
		send_status(403, 'Forbidden');
		die('{"error":"Admin only"}');
	}

	/*
	/* Read side for the call-dialog times grid. Returns every crew member booked on
	/* the call with their CURRENT actual times, paygrade, late flag and note, plus
	/* the paygrade option list for the rate dropdown -- so the grid prefills exactly
	/* what is stored. Modelled on the native call-times.php SELECT, with goat_note +
	/* late added, and each crew member's default paygrade (users.paygradeID) carried
	/* alongside the call-level callpaygradeID so the dropdown always has a sensible
	/* default and never lands on 0.
	/*
	/* Read-only. Call id via ?id=N.
	/*
	/* PHP 5.x -- no null-coalescing (??), no short array syntax.
	*/

	function send_status($code, $msg)
	{
		$proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($proto . ' ' . $code . ' ' . $msg);
	}

	$callID = isset($_GET['id']) ? intval($_GET['id']) : 0;

	if ($callID <= 0)
	{
		send_status(400, 'Bad Request');
		die('{"error":"Missing or invalid ?id"}');
	}

	$cDetails = $db->selectFirst('id, bookingID, call_name, call_locked', 'calls', 'id=' . intval($callID));

	if (!$cDetails)
	{
		send_status(404, 'Not Found');
		die('{"error":"Call not found"}');
	}

	/* crew booked on the call, with current times / paygrade / late / note.
	   Reserved-word columns (on, break, off) are written exactly as the native
	   call-times.php SELECT, which the live host accepts unbackticked. */

	$callCrew = $db->select(
		'users.firstname, users.lastname, users.id AS userID, users.ein, users.paygradeID AS user_paygradeID, call_crew_map.status, call_crew_map.on, call_crew_map.break, call_crew_map.break_night, call_crew_map.off, call_crew_map.callpaygradeID, call_crew_map.late, call_crew_map.goat_note',
		'call_crew_map LEFT JOIN users ON call_crew_map.userID=users.id',
		'callID=' . intval($callID),
		'users.lastname ASC'
	);

	$crew = array();

	if (is_array($callCrew))
	{
		foreach ($callCrew as $c)
		{
			$noteVal = ($c->goat_note === null) ? '' : $c->goat_note;
			$lateVal = ($c->late === '1') ? 1 : 0;

			$crew[] = array(
				'user_id'          => intval($c->userID),
				'firstname'        => $c->firstname,
				'lastname'         => $c->lastname,
				'ein'              => $c->ein,
				'status'           => intval($c->status),
				'on'               => $c->on,
				'break'            => $c->break,
				'break_night'      => $c->break_night,
				'off'              => $c->off,
				'callpaygradeID'   => intval($c->callpaygradeID),
				'user_paygradeID'  => intval($c->user_paygradeID),
				'late'             => $lateVal,
				'note'             => $noteVal
			);
		}
	}

	/* paygrade options for the rate dropdown */

	$pgRows = $db->select('id, day_desc, rate', 'paygrades', NULL, '`order` ASC');

	$paygrades = array();

	if (is_array($pgRows))
	{
		foreach ($pgRows as $p)
		{
			$paygrades[] = array(
				'id'       => intval($p->id),
				'day_desc' => $p->day_desc,
				'rate'     => $p->rate
			);
		}
	}

	echo json_encode(array(
		'ok'          => true,
		'call_id'     => intval($callID),
		'booking_id'  => intval($cDetails->bookingID),
		'call_name'   => $cDetails->call_name,
		'call_locked' => (intval($cDetails->call_locked) === 1) ? 1 : 0,
		'crew'        => $crew,
		'paygrades'   => $paygrades
	));

?>
