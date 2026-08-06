<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* ADMIN ONLY (same gate as dismiss-promo-ack.php / update-crew-status.php).
	/* Rich and Monty are usergroupID == 1, so admin-only still lets Operations
	/* acknowledge — see cohort.php: write endpoints keep their own strict check.
	/*
	/* Ops acknowledge a fatigue/travel WARNING (Rule 2 or 3) raised against one
	/* crew member on one specific PAIR of calls. The warning then renders muted
	/* instead of amber. This is NOT suppression — the arrangement stays visible.
	/*
	/* Contract: POST, JSON body. DELIBERATELY no ?id= query param, unlike
	/* dismiss-promo-ack.php — this endpoint acts on a PAIR of calls, so there is
	/* no single call id to put in the query string.
	/*
	/*   {"userID": 412, "callA": 38202, "callB": 38201, "rule": 2,
	/*    "fingerprint": "…", "note": "rolling into own load-out",
	/*    "action": "ack"}
	/*
	/* action is "ack" (default when absent) or "undo".
	/*
	/* Only Rules 2 and 3 are acknowledgeable. Rule 1 (a real overlap) must be
	/* resolved; Rule 4 (24hr ceiling) has no counterpart call. Both are rejected
	/* with 400 HERE — the endpoint is the enforcement point, not the UI.
	/*
	/* acked_by is ALWAYS the session userID, never read from the request body —
	/* the client does not get to claim who signed it off.
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

	/* ---- parse body ----
	/*
	/* JSON via php://input, matching dismiss-promo-ack.php exactly rather than
	/* reading $_POST. One parsing convention across the family. Do NOT
	/* "simplify" this back to $_POST. */

	$raw     = file_get_contents('php://input');
	$payload = json_decode($raw);

	if (!$payload)
	{
		send_status(400, 'Bad Request');
		die('{"error":"Invalid or missing JSON body"}');
	}

	$userID = intval(P($payload, 'userID', 0));
	$callA  = intval(P($payload, 'callA',  0));
	$callB  = intval(P($payload, 'callB',  0));
	$rule   = intval(P($payload, 'rule',   0));
	$action = P($payload, 'action', 'ack');

	/* ---- validation, in order (§5) ---- */

	/* 1. ids present and distinct */
	if ($userID <= 0 || $callA <= 0 || $callB <= 0 || $callA === $callB)
	{
		send_status(400, 'Bad Request');
		die('{"error":"userID, callA, callB required and callA != callB"}');
	}

	/* 2. rule is exactly 2 or 3 — Rules 1 and 4 are NOT acknowledgeable */
	if ($rule !== 2 && $rule !== 3)
	{
		send_status(400, 'Bad Request');
		die('{"error":"rule must be 2 or 3"}');
	}

	/* 3. normalise the pair to a fixed numeric order */
	$lo = min($callA, $callB);
	$hi = max($callA, $callB);

	/* ---- action: undo — hard DELETE on the key, no fingerprint needed ---- */

	if ($action === 'undo')
	{
		mysql_query(
			'DELETE FROM call_clash_ack' .
			' WHERE userID='   . $userID .
			' AND callID_a='   . $lo .
			' AND callID_b='   . $hi .
			' AND rule_no='    . $rule
		);

		if (mysql_error())
		{
			send_status(500, 'Internal Server Error');
			die('{"error":"undo failed: ' . addslashes(mysql_error()) . '"}');
		}

		echo json_encode(array(
			'ok'      => true,
			'removed' => (mysql_affected_rows() > 0)
		));
		exit;
	}

	/* ---- action: ack ---- */

	/* 4. read both calendars rows (type=2). Either missing -> business no-op.
	/*
	/* Columns are ALIAS-QUALIFIED (cal.call, cal.start, cal.end) exactly as
	/* get-booked-crew-bulk.php / get-shifts-bulk.php do. `call` is a MySQL
	/* reserved word (CALL) and is only exempt from quoting when it follows a
	/* period in a qualified name — unqualified `call` is a syntax error.
	/*
	/* A query that returns false is a HARD FAILURE (500), NOT a missing row.
	/* Folding false into $rowLo === null would report a SQL error as the
	/* business no-op "assignment not found", sending anyone debugging to the
	/* data instead of the query. */

	$rowLo = null;
	$rowHi = null;

	$resLo = mysql_query(
		'SELECT cal.start AS start_dt, cal.end AS end_dt FROM calendars cal' .
		' WHERE cal.type = 2 AND cal.user = ' . $userID .
		' AND cal.call = ' . $lo . ' LIMIT 1'
	);
	if ($resLo === false)
	{
		send_status(500, 'Internal Server Error');
		die('{"error":"calendar lookup failed: ' . addslashes(mysql_error()) . '"}');
	}
	if (mysql_num_rows($resLo) > 0)
		$rowLo = mysql_fetch_object($resLo);

	$resHi = mysql_query(
		'SELECT cal.start AS start_dt, cal.end AS end_dt FROM calendars cal' .
		' WHERE cal.type = 2 AND cal.user = ' . $userID .
		' AND cal.call = ' . $hi . ' LIMIT 1'
	);
	if ($resHi === false)
	{
		send_status(500, 'Internal Server Error');
		die('{"error":"calendar lookup failed: ' . addslashes(mysql_error()) . '"}');
	}
	if (mysql_num_rows($resHi) > 0)
		$rowHi = mysql_fetch_object($resHi);

	if (!$rowLo || !$rowHi)
	{
		echo json_encode(array('ok' => false, 'reason' => 'assignment not found'));
		exit;
	}

	/* 5. rebuild the fingerprint per design doc §6 and compare constant-time.
	/*    Canonical, pipe-delimited, no spaces:
	/*      <userID>|<lo>|<startLo>|<endLo>|<hi>|<startHi>|<endHi>|<rule>
	/*    Timestamps via date('Y-m-d\TH:i:s', strtotime(...)) — IDENTICAL to
	/*    get-booked-crew-bulk.php's emit and to Python's isoformat(). */

	$startLo = date('Y-m-d\TH:i:s', strtotime($rowLo->start_dt));
	$endLo   = date('Y-m-d\TH:i:s', strtotime($rowLo->end_dt));
	$startHi = date('Y-m-d\TH:i:s', strtotime($rowHi->start_dt));
	$endHi   = date('Y-m-d\TH:i:s', strtotime($rowHi->end_dt));

	$canon = $userID . '|' . $lo . '|' . $startLo . '|' . $endLo .
	         '|' . $hi . '|' . $startHi . '|' . $endHi . '|' . $rule;

	$computed = sha1($canon);
	$supplied = (string) P($payload, 'fingerprint', '');

	if (!goat_hash_equals($computed, $supplied))
	{
		echo json_encode(array(
			'ok'     => false,
			'reason' => 'stale — times have changed since this was raised'
		));
		exit;
	}

	/* 6. note: trim, cap 255, escape. Empty -> SQL NULL, not empty string. */

	$note    = substr(trim((string) P($payload, 'note', '')), 0, 255);
	$noteSql = ($note === '') ? 'NULL' : $db->sc($note);

	/* ---- write: DELETE-then-INSERT (§5). acked_by is the session user. ---- */

	$ackedBy = (int) $_SESSION[SITE_KEY]['userID'];

	/* Pin one timestamp: the row stored and the value reported must agree, so
	/* the client is never told an acked_at a second off what actually landed. */
	$now = time();

	mysql_query(
		'DELETE FROM call_clash_ack' .
		' WHERE userID='   . $userID .
		' AND callID_a='   . $lo .
		' AND callID_b='   . $hi .
		' AND rule_no='    . $rule
	);

	mysql_query(
		'INSERT INTO call_clash_ack' .
		' (userID, callID_a, callID_b, rule_no, fingerprint, acked_at, acked_by, note)' .
		' VALUES (' .
			$userID . ', ' .
			$lo . ', ' .
			$hi . ', ' .
			$rule . ', ' .
			$db->sc($computed) . ', ' .
			$now . ', ' .
			$ackedBy . ', ' .
			$noteSql .
		')'
	);

	if (mysql_error())
	{
		send_status(500, 'Internal Server Error');
		die('{"error":"ack failed: ' . addslashes(mysql_error()) . '"}');
	}

	echo json_encode(array(
		'ok'       => true,
		'user_id'  => $userID,
		'call_a'   => $lo,
		'call_b'   => $hi,
		'rule'     => $rule,
		'acked_at' => $now,
		'acked_by' => $ackedBy
	));

?>
