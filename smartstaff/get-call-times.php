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
	/* ADMIN ONLY (same gate as update-call-times.php). */
	/* (send_status is declared further down; PHP hoists top-level functions.) */

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

	/*
	/* Late-cancellation fields (BRIEF-late-cancellation-pay). Capability-
	/* checked: before the migration the grid reads every cancelled row as
	/* not paid -- the D8 rule, and what update-call-times.php then enforces.
	/* cancelled_at predates this (5.24.0) and is checked the same way so the
	/* grid never 500s on an environment that lacks it.
	*/

	$haveCancelled = false;
	$ccr = mysql_query("SHOW COLUMNS FROM calls LIKE 'cancelled_at'");
	if ($ccr !== false && mysql_num_rows($ccr) > 0)
		$haveCancelled = true;

	$havePay = goat_ccm_have_cancel_pay();

	$callSel = 'id, bookingID, call_name, call_locked';
	if ($haveCancelled) $callSel .= ', cancelled_at, cancel_charge';
	if ($havePay)       $callSel .= ', cancel_pay';

	$cDetails = $db->selectFirst($callSel, 'calls', 'id=' . intval($callID));

	if (!$cDetails)
	{
		send_status(404, 'Not Found');
		die('{"error":"Call not found"}');
	}

	/* crew booked on the call, with current times / paygrade / late / note.
	   Reserved-word columns (on, break, off) are written exactly as the native
	   call-times.php SELECT, which the live host accepts unbackticked.

	   call_promo_ack is joined so the grid can LOCK an unanswered promotion: a
	   backup promoted to 5 who has not accepted is not known to be working, and
	   must not have times entered (and so be paid) until they accept in Crew Hub
	   or ops dismiss the flag. The WHERE is qualified call_crew_map.callID because
	   call_promo_ack has a callID column too -- unqualified it is ambiguous. */

	$callCrew = $db->select(
		'users.firstname, users.lastname, users.id AS userID, users.ein, users.paygradeID AS user_paygradeID, call_crew_map.status' . ($haveCancelled ? ', call_crew_map.prev_status' : '') . ($havePay ? ', ' . goat_ccm_cancel_editable_sql('call_crew_map', 'ccx') . ' AS cancel_paid' : '') . ', call_crew_map.on, call_crew_map.break, call_crew_map.break_night, call_crew_map.off, call_crew_map.callpaygradeID, call_crew_map.late, call_crew_map.goat_note, cpa.promoted_at AS promo_at, cpa.acked_at AS promo_acked_at',
		'call_crew_map LEFT JOIN users ON call_crew_map.userID=users.id LEFT JOIN call_promo_ack cpa ON cpa.callID=call_crew_map.callID AND cpa.userID=call_crew_map.userID' . ($havePay ? ' INNER JOIN calls ccx ON ccx.id=call_crew_map.callID' : ''),
		'call_crew_map.callID=' . intval($callID),
		'users.lastname ASC'
	);

	$crew = array();

	if (is_array($callCrew))
	{
		foreach ($callCrew as $c)
		{
			$noteVal = ($c->goat_note === null) ? '' : $c->goat_note;
			$lateVal = ($c->late === '1') ? 1 : 0;

			/* Same test as get-booking.php's promo_pending: guarded to status 5,
			   so a stale row on someone since declined never locks anything. */
			$promoPending = (intval($c->status) === 5 && $c->promo_at !== null && $c->promo_acked_at === null) ? 1 : 0;

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
				'note'             => $noteVal,
				'promo_pending'    => $promoPending,
				/* what they WERE when stood down; null on live rows */
				'prev_status'      => (isset($c->prev_status) && $c->prev_status !== null) ? intval($c->prev_status) : null,
				/* paid-cancellation row that may still be keyed: editable in Enter
				   Times, saved as ordinary hours. The EDITABLE test, so a call
				   settled under the old rule (cancel_times_confirmed_at =
				   cancelled_at) stays locked -- same rule update-call-times.php
				   enforces. */
				'cancel_paid'      => (isset($c->cancel_paid) && intval($c->cancel_paid) === 1)
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
		'cancelled'     => (isset($cDetails->cancelled_at) && $cDetails->cancelled_at !== null && intval($cDetails->cancelled_at) > 0),
		'cancel_pay'    => isset($cDetails->cancel_pay)    ? intval($cDetails->cancel_pay)    : 0,
		'cancel_charge' => isset($cDetails->cancel_charge) ? intval($cDetails->cancel_charge) : 0,
		'crew'        => $crew,
		'paygrades'   => $paygrades
	));

?>
