<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* ADMIN ONLY (same gate as update-crew-status.php).
	/*
	/* Ops dismiss the "promoted — not confirmed" pill when they have already
	/* spoken to the crew member. This is NOT a cosmetic dismiss: it is ops
	/* asserting that consent was obtained by phone, recorded as acked_src='ops'.
	/*
	/* Why this exists: ops promote from phone conversations as often as cold.
	/* update-crew-status.php cannot tell the two apart — the ↑ Promote button
	/* and the dialog dropdown send an identical {userID, status:5} body — so
	/* every 7 -> 5 raises the flag and this endpoint clears the ones that were
	/* already agreed. It also covers the crew member with no push subscription,
	/* who will never clear it themselves.
	/*
	/* Contract: POST ?id=<callID> with JSON body {userID}. Status is NEVER touched.
	/*
	/* PHP 5.x — mysql_*, no null-coalescing (??), no short array syntax.
	*/

	/*
	/* Safe property read off a json_decode()'d object — same helper as
	/* update-crew-status.php. */

	function P($obj, $key, $default)
	{
		return (isset($obj->$key) && $obj->$key !== null) ? $obj->$key : $default;
	}

	function send_status($code, $msg)
	{
		$proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($proto . ' ' . $code . ' ' . $msg);
	}

	if (goat_user_cohort() !== 'admin')
	{
		send_status(403, 'Forbidden');
		die('{"error":"Admin only"}');
	}

	/* ---- target call id ---- */

	$callID = isset($_GET['id']) ? intval($_GET['id']) : 0;

	if ($callID <= 0)
	{
		send_status(400, 'Bad Request');
		die('{"error":"Missing or invalid ?id"}');
	}

	/* ---- parse body ----
	/*
	/* JSON via php://input, matching update-crew-status.php exactly rather than
	/* reading $_POST. These endpoints are rewritten together when the GOAT moves
	/* to its own database; one parsing convention across the family is the whole
	/* point. Do NOT "simplify" this back to $_POST. */

	$raw     = file_get_contents('php://input');
	$payload = json_decode($raw);

	if (!$payload)
	{
		send_status(400, 'Bad Request');
		die('{"error":"Invalid or missing JSON body (expected {userID})"}');
	}

	$userID = intval(P($payload, 'userID', 0));

	if ($userID <= 0)
	{
		send_status(400, 'Bad Request');
		die('{"error":"userID is required"}');
	}

	mysql_query(
		'UPDATE call_promo_ack SET acked_at=' . time() . ", acked_src='ops'" .
		' WHERE callID=' . intval($callID) . ' AND userID=' . intval($userID) .
		' AND acked_at IS NULL'
	);

	if (mysql_error())
	{
		send_status(500, 'Internal Server Error');
		die('{"error":"dismiss failed: ' . addslashes(mysql_error()) . '"}');
	}

	$cleared = (mysql_affected_rows() > 0);

	echo json_encode(array(
		'ok'      => true,
		'call_id' => $callID,
		'user_id' => $userID,
		'cleared' => $cleared
	));

?>
