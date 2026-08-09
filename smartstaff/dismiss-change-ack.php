<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* ADMIN ONLY (same gate as dismiss-promo-ack.php).
	/*
	/* Ops dismiss the "timing changed — not re-confirmed" flag from the Ops
	/* landing page when they have already re-confirmed the crew member by phone.
	/*
	/* Action is a DELETE of the call_change_ack row, NOT a stamped ack — and that
	/* is deliberate. respond-to-change.php already DELETEs the row when the crew
	/* member answers, so the row's PRESENCE is the pending test everywhere it is
	/* read (get-calls-bulk.php, get-booking.php, my-shifts.php). Adding acked_at /
	/* acked_src columns here would mean changing that pending test in all three at
	/* once — a wide, risky change for an audit trail this feature does not yet
	/* need. KNOWN COST: unlike promo acks there is no acked_src, so a change ack
	/* cleared by ops is indistinguishable from one the crew member answered. A
	/* deliberate trade, recorded — not an oversight.
	/*
	/* Contract: POST ?id=<callID> with JSON body {userID}. call_crew_map.status is
	/* NEVER touched — the crew member stays confirmed (5) / backup (7); only the
	/* outstanding QUESTION is cleared.
	/*
	/* PHP 5.x — mysql_*, no null-coalescing (??), no short array syntax.
	*/

	/*
	/* Safe property read off a json_decode()'d object — same helper as
	/* dismiss-promo-ack.php / update-crew-status.php. */

	function P($obj, $key, $default)
	{
		return (isset($obj->$key) && $obj->$key !== null) ? $obj->$key : $default;
	}

	if (goat_user_cohort() !== 'admin')
	{
		goat_json_error(403, 'Admin only');
	}

	/* ---- target call id ---- */

	$callID = isset($_GET['id']) ? intval($_GET['id']) : 0;

	if ($callID <= 0)
	{
		goat_json_error(400, 'Missing or invalid ?id');
	}

	/* ---- parse body ----
	/*
	/* JSON via php://input, matching dismiss-promo-ack.php / update-crew-status.php
	/* exactly rather than reading $_POST. One parsing convention across the
	/* endpoint family is the whole point; do NOT "simplify" this back to $_POST. */

	$raw     = file_get_contents('php://input');
	$payload = json_decode($raw);

	if (!$payload)
	{
		goat_json_error(400, 'Invalid or missing JSON body (expected {userID})');
	}

	$userID = intval(P($payload, 'userID', 0));

	if ($userID <= 0)
	{
		goat_json_error(400, 'userID is required');
	}

	mysql_query(
		'DELETE FROM call_change_ack WHERE callID=' . intval($callID) .
		' AND userID=' . intval($userID)
	);

	/* Gate success on mysql_error(), NOT affected_rows: a dismiss of an already-
	/* cleared row (the crew member answered first, or a second admin clicked)
	/* deletes zero rows and is a business no-op, not a failure. */

	if (mysql_error())
	{
		goat_json_error(500, 'dismiss failed: ' . mysql_error());
	}

	/* A real DELETE returns ok:true; a no-op returns HTTP 200 with ok:false, per
	/* house convention — the caller treats 200 as success and refetches either
	/* way, so a stale double-click never raises an error banner. */

	$cleared = (mysql_affected_rows() > 0);

	echo json_encode(array(
		'ok'      => $cleared,
		'call_id' => $callID,
		'user_id' => $userID,
		'cleared' => $cleared
	));

?>
