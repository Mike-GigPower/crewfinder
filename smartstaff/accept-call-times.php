<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* ADMIN ONLY (same gate as update-call-times.php / update-call.php). */

	if (goat_user_cohort() !== 'admin')
	{
		send_status(403, 'Forbidden');
		die('{"error":"Admin only"}');
	}

	/*
	/* THE REVIEW TICK, AND NOTHING ELSE. Sets calls.times_filled = '1' on one
	/* call. It is the second half of accepting times in THE GOAT: the numbers
	/* go in through update-call-times.php exactly as they always have, and
	/* then this says a human has reviewed them.
	/*
	/* WHY IT IS A SEPARATE ENDPOINT. update-call-times.php documents that it
	/* never touches times_filled, and that is the right rule for it: an import
	/* or a partial correction is not a review. Widening it would make every
	/* times write an acceptance. Keeping the tick here means the one place
	/* that claims "Ops have reviewed this" is the one place a person clicked
	/* Accept.
	/*
	/* IT NEVER WRITES call_locked, AND THAT IS THE POINT.
	/*
	/* call_locked gates invoicing and payslip eligibility in four accounting
	/* queries, and add-call.php runs the accounting cascade
	/* ($accounting->generateCallData) on its 0 -> 1 transition. update-call.php
	/* stays away from it deliberately and so does this. The response returns
	/* call_locked as it was read BEFORE the write and again AFTER it, so a
	/* caller — and the smoke test — can assert the two are equal rather than
	/* trusting this comment.
	/*
	/* REFUSES ON A LOCKED CALL, matching update-call-times.php: a locked call's
	/* times could not have been edited in the first place, so accepting them
	/* would tick a review of numbers nobody could change. 409, the same code
	/* and the same reason.
	/*
	/* IDEMPOTENT. Accepting an already-accepted call writes the same value and
	/* reports already_filled, rather than failing — two Ops clicking Accept on
	/* the same call is a race, not an error.
	/*
	/* times_filled IS WRITTEN AS THE STRING '1' and read back with an (int)
	/* cast in PHP, never compared in SQL. SmartStaff's own callsheet form
	/* posts these checkbox columns as strings; the same discipline
	/* is_call_boss needs.
	/*
	/* Call id via ?id=N. No body.
	/*
	/* PHP 5.x — no null-coalescing (??), no short array syntax.
	*/

	function send_status($code, $msg)
	{
		$proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($proto . ' ' . $code . ' ' . $msg);
	}

	/* ---- target call ---- */

	$callID = isset($_GET['id']) ? intval($_GET['id']) : 0;

	if ($callID <= 0)
	{
		send_status(400, 'Bad Request');
		die('{"error":"Missing or invalid ?id"}');
	}

	$before = $db->selectFirst('id, bookingID, times_filled, call_locked', 'calls', 'id=' . $callID);

	if (!$before)
	{
		send_status(404, 'Not Found');
		die('{"error":"Call not found"}');
	}

	$lockedBefore = (int) $before->call_locked;
	$filledBefore = (int) $before->times_filled;

	if ($lockedBefore === 1)
	{
		send_status(409, 'Conflict');
		die('{"error":"Call is locked; unlock it in SmartStaff before accepting times"}');
	}

	/* ---- the tick ---- */

	$db->update('calls', array('times_filled' => $db->sc('1')), 'id=' . $callID);

	$err = mysql_error();

	if ($err !== '')
	{
		send_status(500, 'Internal Server Error');
		echo json_encode(array('error' => 'accept failed', 'detail' => $err));
		die();
	}

	/*
	/* Read back rather than assuming. The whole value of this endpoint is that
	/* it can be trusted about which column it moved.
	*/

	$after = $db->selectFirst('id, bookingID, times_filled, call_locked', 'calls', 'id=' . $callID);

	if (!$after)
	{
		send_status(500, 'Internal Server Error');
		die('{"error":"call vanished during accept"}');
	}

	echo json_encode(array(
		'ok'                  => true,
		'call_id'             => $callID,
		'booking_id'          => (int) $after->bookingID,
		'already_filled'      => ($filledBefore === 1),
		'times_filled_before' => $filledBefore,
		'times_filled'        => (int) $after->times_filled,
		'call_locked_before'  => $lockedBefore,
		'call_locked'         => (int) $after->call_locked
	));

?>
