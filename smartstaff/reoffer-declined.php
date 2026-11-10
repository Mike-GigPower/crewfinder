<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include('call-graph.php');
	include_once(dirname(__FILE__) . '/goat-db.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* RE-OFFER A DECLINED CREW MEMBER (DESIGN-reoffer-declined v0.1 §3).
	/*
	/* Ops change someone from Declined to Unconfirmed in THE GOAT and choose
	/* "Send offer". This endpoint re-opens the declined or No Response row on
	/* this call AND every declined or No Response row in its locked package
	/* (goat_reoffer_scope), so a package declined as one unit comes back as
	/* one offer. THE GOAT then sends the offer push; nothing here talks to
	/* Crew Hub.
	/*
	/*   ?id={callID}   body {userID, dry_run}
	/*
	/*   dry_run=1 : what a re-offer would touch, nothing written
	/*               {ok, dry_run:true, call_id, user_id, responded_at,
	/*                calls:[{call_id, call_name, start, started, cancelled,
	/*                        full, required, confirmed}], refused}
	/*   apply     : refuse on started / cancelled (409, nothing written);
	/*               otherwise 6/10 -> 0 on every package row still at 6 or 10
	/*               {ok, dry_run:false, ..., calls, reopened:[call ids]}
	/*
	/* 409 not_declined when the seed row is missing or not status 6 or 10 -- a
	/* stale dialog, or someone else already moved it.
	/*
	/* NO CALENDAR CHANGE: nobody becomes confirmed. NO responded_src TAGGING:
	/* arm 2 of the ccm_responded_at_upd trigger resets offered_at and clears
	/* responded_at / responded_src on 6 -> 0, so the re-offer counts as a
	/* fresh offer in the response stats.
	/*
	/* update-crew-status.php is left as it is. "Change without sending" in
	/* THE GOAT still goes through it: one row, no push.
	/*
	/* ACCESSOR: this file's own queries are mysqli_*, on a connection opened
	/* with the credentials goat-db.php resolves. The call-graph.php helpers
	/* (goat_reoffer_scope, goat_feed_step) are mysql_* on the
	/* legacy connection. No transaction spans the two; the
	/* `AND status = 6` guard on the UPDATE is what keeps a row that moved
	/* since the read from being rewritten.
	/*
	/* PHP 5.6 -- array(), no null-coalescing, no short array syntax. */

	/* ---- helpers ---- */

	function send_status($code, $msg)
	{
		$proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($proto . ' ' . $code . ' ' . $msg);
	}

	function reoffer_fail($code, $msg, $body)
	{
		send_status($code, $msg);
		$out = json_encode($body);
		if ($out === false)
			$out = '{"ok":false,"error":"request failed"}';
		die($out);
	}

	/*
	/* A mysqli link with the same credentials goat_pdo() resolves. No
	/* charset is set, deliberately: the database default is latin1 and the
	/* legacy connection every other call-name reader uses sets none either,
	/* so the names come back as the same bytes get-booking.php emits. */

	function reoffer_mysqli()
	{
		$host = goat_db_find_setting(array(
			'GOAT_EMERGENCY_DB_HOST','GOAT_DB_HOST','DB_HOST','DBHOST','MYSQL_HOST','SQL_HOST','HOST'));
		$user = goat_db_find_setting(array(
			'GOAT_EMERGENCY_DB_USER','GOAT_DB_USER','DB_USER','DBUSER','DB_USERNAME','MYSQL_USER','SQL_USER','USER'));
		$pass = goat_db_find_setting(array(
			'GOAT_EMERGENCY_DB_PASS','GOAT_DB_PASS','DB_PASS','DBPASS','DB_PASSWORD','DBPASSWORD','MYSQL_PASS','SQL_PASS','PASS','PASSWORD'));
		$name = goat_db_find_setting(array(
			'GOAT_EMERGENCY_DB_NAME','GOAT_DB_NAME','DB_NAME','DBNAME','MYSQL_DB','SQL_DB','DB_DATABASE','DATABASE'));

		if ($host === null) { $host = 'localhost'; }

		if ($user === null || $pass === null || $name === null)
		{
			error_log('reoffer-declined: could not resolve DB credentials');
			return null;
		}

		mysqli_report(MYSQLI_REPORT_OFF);
		$link = @mysqli_connect($host, $user, $pass, $name);

		if (!$link)
		{
			error_log('reoffer-declined: connect failed: ' . mysqli_connect_error());
			return null;
		}

		return $link;
	}

	function reoffer_has_column($link, $table, $column)
	{
		$r = mysqli_query($link, "SHOW COLUMNS FROM `" . $table . "` LIKE '" . mysqli_real_escape_string($link, $column) . "'");
		return ($r !== false && mysqli_num_rows($r) > 0);
	}

	/* ---- admin only (same gate as update-crew-status.php) ---- */

	if (goat_user_cohort() !== 'admin')
	{
		reoffer_fail(403, 'Forbidden', array('error' => 'Admin only'));
	}

	/* ---- input ---- */

	$callID = isset($_GET['id']) ? intval($_GET['id']) : 0;

	if ($callID <= 0)
	{
		reoffer_fail(400, 'Bad Request', array('error' => 'Missing or invalid ?id'));
	}

	$payload = json_decode(file_get_contents('php://input'));

	if (!$payload)
	{
		reoffer_fail(400, 'Bad Request', array('error' => 'Invalid or missing JSON body (expected {userID, dry_run})'));
	}

	$userID = isset($payload->userID) ? intval($payload->userID) : 0;
	$dryRun = (isset($payload->dry_run) && $payload->dry_run) ? true : false;

	if ($userID <= 0)
	{
		reoffer_fail(422, 'Unprocessable Entity', array('error' => 'userID is required'));
	}

	$link = reoffer_mysqli();

	if (!$link)
	{
		reoffer_fail(500, 'Internal Server Error', array('error' => 'Database unavailable'));
	}

	/* ---- the seed row must exist and be DECLINED ---- */

	$hasResponded = reoffer_has_column($link, 'call_crew_map', 'responded_at');
	$respSel      = $hasResponded ? ", DATE_FORMAT(responded_at, '%Y-%m-%dT%H:%i:%s') AS responded_at" : '';

	$sres = mysqli_query($link, "SELECT status" . $respSel . " FROM call_crew_map
	                             WHERE callID = " . $callID . " AND userID = " . $userID . " LIMIT 1");

	if ($sres === false)
	{
		reoffer_fail(500, 'Internal Server Error', array('error' => 'Row lookup failed'));
	}

	$seed = mysqli_fetch_object($sres);

	if (!$seed || !in_array((int) $seed->status, array(6, 10), true))
	{
		reoffer_fail(409, 'Conflict', array(
			'ok'     => false,
			'error'  => 'not_declined',
			'status' => $seed ? (int) $seed->status : null
		));
	}

	$respondedAt = ($hasResponded && isset($seed->responded_at)) ? $seed->responded_at : null;

	/* ---- the package (§2.1) ---- */

	$ids = array();

	foreach (goat_reoffer_scope($userID, $callID) as $c)
	{
		$c = (int) $c;
		if ($c > 0)
			$ids[] = $c;
	}

	if (!in_array($callID, $ids))
		$ids[] = $callID;

	$idList = implode(',', $ids);

	/* ---- per call: name, start, started, cancelled, full ---- */

	$cancelSel = reoffer_has_column($link, 'calls', 'cancelled_at') ? ', cancelled_at' : '';

	$cres = mysqli_query($link, "SELECT id, call_name, start_date, start_time, required" . $cancelSel . "
	                             FROM calls WHERE id IN (" . $idList . ")
	                             ORDER BY start_date ASC, start_time ASC");

	if ($cres === false)
	{
		reoffer_fail(500, 'Internal Server Error', array('error' => 'Call lookup failed'));
	}

	/*
	/* Full: the same predicate as goat_rtc_root_is_full in respond-to-call.php
	/* -- confirmed (status 5) >= calls.required. A call with required = 0 is
	/* therefore always full, exactly as an accept would find it. */

	$confirmed = array();
	$fres = mysqli_query($link, "SELECT callID, COUNT(status) AS cnt FROM call_crew_map
	                             WHERE status = 5 AND callID IN (" . $idList . ")
	                             GROUP BY callID");

	if ($fres === false)
	{
		reoffer_fail(500, 'Internal Server Error', array('error' => 'Capacity lookup failed'));
	}

	while ($frow = mysqli_fetch_object($fres))
	{
		$confirmed[(int) $frow->callID] = (int) $frow->cnt;
	}

	/* "Has it started?" in Melbourne wall-clock, as my-call-offers.php and
	/* goat_call_start_ts measure it. */

	$melTz   = new DateTimeZone('Australia/Melbourne');
	$now     = time();
	$calls   = array();
	$refused = null;

	while ($row = mysqli_fetch_object($cres))
	{
		$cid     = (int) $row->id;
		$dateStr = date('Y-m-d', (int) $row->start_date);

		$startTs = false;
		try {
			$dt      = new DateTime($dateStr . ' ' . $row->start_time, $melTz);
			$startTs = $dt->getTimestamp();
		} catch (Exception $e) {
			$startTs = false;
		}

		$started   = ($startTs !== false && $startTs <= $now) ? true : false;
		$cancelled = (isset($row->cancelled_at) && $row->cancelled_at !== null) ? true : false;
		$required  = (int) $row->required;
		$conf      = isset($confirmed[$cid]) ? $confirmed[$cid] : 0;

		$calls[] = array(
			'call_id'   => $cid,
			'call_name' => $row->call_name,
			'start'     => date('Y-m-d\TH:i:s', strtotime($dateStr . ' ' . $row->start_time)),
			'started'   => $started,
			'cancelled' => $cancelled,
			'full'      => ($conf >= $required) ? true : false,
			'required'  => $required,
			'confirmed' => $conf
		);

		/* §2.2: the first refusal in start order is the one shown. Cancelled
		/* before started: a cancelled call that has also started is better
		/* explained as cancelled. */

		if ($refused === null && ($cancelled || $started))
		{
			$refused = array(
				'reason'    => $cancelled ? 'cancelled' : 'started',
				'call_id'   => $cid,
				'call_name' => $row->call_name
			);
		}
	}

	$base = array(
		'ok'           => true,
		'dry_run'      => $dryRun,
		'call_id'      => $callID,
		'user_id'      => $userID,
		'responded_at' => $respondedAt,
		'calls'        => $calls,
		'refused'      => $refused
	);

	/* ---- dry run: nothing written ---- */

	if ($dryRun)
	{
		$out = json_encode($base);
		if ($out === false)
			reoffer_fail(500, 'Internal Server Error', array('error' => 'Could not encode response'));
		echo $out;
		exit;
	}

	/* ---- apply ---- */

	if ($refused !== null)
	{
		$base['ok']    = false;
		$base['error'] = $refused['reason'];
		reoffer_fail(409, 'Conflict', $base);
	}

	/*
	/* `AND status = 6` IS LOAD-BEARING. A row that moved since the read above
	/* (accepted, cancelled, set by another operator) is left exactly as it
	/* is. Success is gated on the mysqli error, not affected_rows: zero rows
	/* changed is a valid outcome of that guard. */

	mysqli_query($link, "UPDATE call_crew_map SET status = 0
	                     WHERE userID = " . $userID . "
	                       AND callID IN (" . $idList . ")
	                       AND status IN (6, 10)");

	if (mysqli_error($link) !== '')
	{
		error_log('reoffer-declined: update failed: ' . mysqli_error($link));
		reoffer_fail(500, 'Internal Server Error', array('error' => 'Update failed'));
	}

	/* re-read: the rows actually at 0 now are the ones THE GOAT pushes for */

	$reopened = array();
	$rres = mysqli_query($link, "SELECT callID FROM call_crew_map
	                             WHERE userID = " . $userID . "
	                               AND callID IN (" . $idList . ")
	                               AND status = 0");

	if ($rres !== false)
	{
		while ($rrow = mysqli_fetch_object($rres))
		{
			$reopened[] = (int) $rrow->callID;
		}
	}

	$base['reopened'] = $reopened;

	$out = json_encode($base);
	if ($out === false)
		$out = '{"ok":true,"reopened":' . json_encode($reopened) . '}';
	echo $out;

?>
