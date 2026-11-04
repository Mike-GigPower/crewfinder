<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include_once('supervision-graph.php');
	include_once('time-submission-graph.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* A CREW MEMBER SENDS THEIR OWN TIMES — slice D of BRIEF-crew-self-times
	/* (§5.2), on calls of tier `self`: no Crew Boss and no Team Lead, so
	/* nobody else is there to enter them (D1, D6).
	/*
	/*   POST ?userID=<actor>   body {callID, on_time, off_time, off_next_day,
	/*                                breaks[], note, confirm}
	/*
	/* THE SUBJECT IS ALWAYS THE ACTOR. There is no userID in the body and no
	/* rows array; either is refused (422) rather than ignored, so a client
	/* built against the boss contract learns it at once instead of having a
	/* field silently dropped.
	/*
	/* SERVICE KEY ONLY — STRICTER THAN THE BOSS ENDPOINT, ON PURPOSE.
	/* submit-call-times.php reaches its actor through goat_acting_user_id(),
	/* which accepts a logged-in SmartStaff session as well as the service key
	/* — so the boss path does NOT enforce "only through the Edge Function";
	/* it relies on its scope gate, which lives entirely in this database.
	/* This endpoint cannot: the per-call Ops switch ("Crew send own times")
	/* lives in Supabase (D7) and only the self-times Edge Function checks it
	/* before forwarding. A session path here would be a way round the
	/* switch, so there is none — the key is required first, and only then is
	/* the userID it asserts read — directly, never through a session (R7).
	/*
	/* GATES, IN ORDER, EACH THE SAME 403 BODY (so a caller cannot tell which
	/* one it failed by reading the message):
	/*
	/*   1  the actor is confirmed (status 5) on the call
	/*   2  the actor is not promo-pending on it
	/*   3  goat_call_tier() says `self`
	/*   4  the call has started (start date + start time <= now, Melbourne —
	/*      global.php sets the zone; goat_call_window() does the arithmetic)
	/*
	/* Then: times_filled set (Ops have approved) is a 409 with its own
	/* message. Then validation and the append-only write through
	/* time-submission-graph.php — the SAME functions the boss endpoint uses
	/* — with source = 'self' and submitted_by = the actor.
	/*
	/* RESUBMISSION SUPERSEDES, as for bosses: the new row points at whatever
	/* is live for the actor, including a row a boss entered.
	/*
	/* NOTHING HERE TOUCHES call_crew_map. A self submission is a claim for
	/* Ops to review, exactly like a boss one (design §6).
	/*
	/* PHP 5.x — mysql_*, no ??, no short array syntax.
	*/

	function send_status($code, $msg)
	{
		$proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($proto . ' ' . $code . ' ' . $msg);
	}

	/* every failure body through json_encode() — see submit-call-times.php's
	   sct_fail() for why concatenation is not safe here */

	function smt_fail($code, $status, $message, $extra)
	{
		send_status($code, $status);

		$body = array('ok' => false, 'error' => $message);

		if (is_array($extra))
		{
			foreach ($extra as $k => $v)
			{
				$body[$k] = $v;
			}
		}

		$out = json_encode($body);

		if ($out === false)
		{
			$out = '{"ok":false,"error":"request failed"}';
		}

		die($out);
	}

	function smt_refuse()
	{
		smt_fail(403, 'Forbidden', 'You cannot send your own times for this call', array());
	}

	/* ---- trust: the Edge Function, and only the Edge Function ---- */

	$goat_key = isset($_SERVER['HTTP_X_GOAT_SERVICE_KEY'])
	          ? $_SERVER['HTTP_X_GOAT_SERVICE_KEY'] : '';

	if (!goat_service_key_ok($goat_key))
	{
		smt_fail(403, 'Forbidden', 'service key required', array());
	}

	/*
	/* THE ACTOR IS THE userID THE EDGE FUNCTION ASSERTS, READ DIRECTLY
	/* (review R7). goat_acting_user_id() is NOT used: it checks a SmartStaff
	/* session FIRST, so a request carrying the key plus a session cookie would
	/* act as the session's user, not the one the Edge Function verified.
	/* Here only the key path exists, so only its userID counts. Same
	/* $_GET-then-$_POST order as goat_acting_user_id()'s service branch.
	*/

	$actor = 0;

	if (isset($_GET['userID']))
	{
		$actor = (int) $_GET['userID'];
	}
	else if (isset($_POST['userID']))
	{
		$actor = (int) $_POST['userID'];
	}

	if ($actor <= 0)
	{
		smt_fail(400, 'Bad Request', 'userID required', array());
	}

	/* ---- body ---- */

	$payload = json_decode(file_get_contents('php://input'));

	if (!$payload || !isset($payload->callID))
	{
		smt_fail(400, 'Bad Request', 'Invalid or missing JSON body', array());
	}

	if (isset($payload->rows) || isset($payload->userID))
	{
		smt_fail(422, 'Unprocessable Entity',
		         'rows and userID are not accepted — this endpoint records the caller\'s own times only', array());
	}

	$callID  = (int) $payload->callID;
	$confirm = (isset($payload->confirm) && $payload->confirm) ? true : false;

	if ($callID <= 0)
	{
		smt_fail(422, 'Unprocessable Entity', 'callID required', array());
	}

	/* ---- GATE 1 — confirmed on the call. A failed lookup is a 500. ---- */

	$cres = mysql_query("SELECT 1 AS n FROM call_crew_map
	                     WHERE callID = " . $callID . "
	                       AND userID = " . (int) $actor . "
	                       AND status = 5
	                     LIMIT 1");

	if ($cres === false)
	{
		smt_fail(500, 'Internal Server Error', 'confirmed lookup failed: ' . mysql_error(), array());
	}

	if (!mysql_fetch_object($cres))
	{
		smt_refuse();
	}

	/* ---- GATE 2 — not an unanswered promotion ---- */

	$pres = mysql_query("SELECT 1 AS n FROM call_promo_ack
	                     WHERE callID = " . $callID . "
	                       AND userID = " . (int) $actor . "
	                       AND acked_at IS NULL
	                     LIMIT 1");

	if ($pres === false)
	{
		smt_fail(500, 'Internal Server Error', 'promotion lookup failed: ' . mysql_error(), array());
	}

	if (mysql_fetch_object($pres))
	{
		smt_refuse();
	}

	/* ---- GATE 3 — the call is tier `self` ---- */

	$t = goat_call_tier($callID);

	if (!$t['ok'])
	{
		smt_fail(500, 'Internal Server Error', $t['error'], array());
	}

	if ($t['tier'] === null || $t['tier']['tier'] !== 'self')
	{
		smt_refuse();
	}

	/* ---- GATE 4 — the call has started ---- */

	$kres = mysql_query("SELECT start_date, start_time, est_length, times_filled
	                     FROM calls WHERE id = " . $callID . " LIMIT 1");

	if ($kres === false)
	{
		smt_fail(500, 'Internal Server Error', 'call lookup failed: ' . mysql_error(), array());
	}

	$call = mysql_fetch_object($kres);

	if (!$call)
	{
		smt_refuse();
	}

	/*
	/* NO USABLE START, NO SUBMISSION (review R6). start_date <= 0 means the
	/* call has no date at all, and goat_call_window() would place it at the
	/* 1970 epoch — "started" by any test. A start strtotime() cannot parse
	/* comes back false, and false <= time() is true in PHP. Either way the
	/* call's timing is unknown, so it is refused rather than treated as past.
	*/

	if ((int) $call->start_date <= 0)
	{
		smt_refuse();
	}

	$win = goat_call_window($call);

	if ($win['start'] === false || (int) $win['start'] <= 0 || $win['start'] > time())
	{
		smt_refuse();
	}

	/*
	/* ---- APPROVED: refused ----
	/*
	/* times_filled is THE GOAT's and SmartStaff's done-tick. Cast in PHP,
	/* never compared in SQL: SmartStaff's own form writes it as a string.
	/* ANY NON-ZERO VALUE IS APPROVED (review R8): refusing a resubmission on
	/* an approved call matters more than which non-zero value SmartStaff
	/* happened to write.
	*/

	if ((int) $call->times_filled !== 0)
	{
		smt_fail(409, 'Conflict',
		         'Your times for this call have already been approved. Contact Gig Power Operations.', array());
	}

	/*
	/* ---- validate, through the shared function ----
	/*
	/* The row is BUILT HERE from the actor and their typed times: unbooked 0,
	/* no covering_for, no EIN. Nothing the client sends can name someone else.
	/* The confirmed and promo sets are the actor's own, already proved by
	/* gates 1 and 2.
	*/

	$row = new stdClass();

	$row->userID       = (int) $actor;
	$row->unbooked     = 0;
	$row->covering_for = 0;
	$row->on_time      = isset($payload->on_time)      ? $payload->on_time      : '';
	$row->off_time     = isset($payload->off_time)     ? $payload->off_time     : '';
	$row->off_next_day = isset($payload->off_next_day) ? $payload->off_next_day : 0;
	$row->note         = isset($payload->note)         ? $payload->note         : '';
	$row->breaks       = (isset($payload->breaks) && is_array($payload->breaks)) ? $payload->breaks : array();

	$v = goat_validate_submission_row($row, 0, 'times', array((int) $actor => true), array());

	if ($v['fatal'] !== null)
	{
		smt_fail($v['fatal'][0], $v['fatal'][1], $v['fatal'][2], $v['fatal'][3]);
	}

	if (count($v['errors']))
	{
		smt_fail(422, 'Unprocessable Entity', $v['errors'][0], array('errors' => $v['errors']));
	}

	$clean = $v['clean'];

	/* ---- the 60-minute warning: the same 409 contract as the boss form ---- */

	$warnings = goat_submission_long_break_warnings(array($clean));

	if (count($warnings) && !$confirm)
	{
		send_status(409, 'Conflict');
		echo json_encode(array('ok' => false, 'needs_confirm' => true, 'warnings' => $warnings));
		die();
	}

	/* ---- write ---- */

	$w = goat_write_submission($callID, $clean, $actor, 'self');

	if (!$w['ok'])
	{
		smt_fail($w['code'], $w['status'], $w['error'], $w['extra']);
	}

	echo json_encode(array(
		'ok'            => true,
		'callID'        => $callID,
		'submission_id' => $w['submission_id'],
		'supersedes_id' => $w['supersedes_id'],
		'breaks'        => $w['breaks']
	));

?>
