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
	/* THE FIRST ENDPOINT THAT LETS A NON-ADMIN WRITE DATA ABOUT OTHER PEOPLE.
	/*
	/* Phase 1's my-boss-calls.php let a crew boss READ their crew. This one
	/* lets them record the hours those people will be paid for. Nothing here
	/* reaches call_crew_map — Ops accept in slice 7 — but a submission is the
	/* INPUT TO PAYROLL and is built as such.
	/*
	/* AUTH — goat_acting_user_id(), then a scope gate.
	/*
	/* This is the opposite of call-supervision.php and the same as
	/* my-boss-calls.php. call-supervision.php is admin maintenance whose
	/* created_by is an audit column, so the actor must be the real signed-in
	/* operator and the session is the only trust path. This is a crew-facing
	/* self-scoped WRITE called by Crew Hub through the Edge Function, where
	/* the service key IS the trust anchor: the backend has already
	/* authenticated the crew member and asserts their userID.
	/*
	/* goat_time_entry_scope() IS RECOMPUTED PER REQUEST. No scope is cached, passed
	/* in, or trusted from the client, and there is no parameter that widens
	/* it. The only question this endpoint asks is "is this call in the scope
	/* the database says you have, right now".
	/*
	/* APPEND-ONLY. There is no UPDATE of a submission's contents and no DELETE
	/* of a break row anywhere in this file. A correction INSERTs a new
	/* submission with supersedes_id pointing at the row it replaces, plus a
	/* fresh set of break rows. The single UPDATE involved sets voided = 1 and
	/* is the failure path in section 5.2 — it changes no typed value. Since
	/* slice D it lives in time-submission-graph.php (goat_void_submission()),
	/* with the rest of the write.
	/*
	/* ---- UNBOOKED CREW ARE IDENTIFIED BY EIN (decision 21) ----
	/*
	/* An unbooked row supplies the person's EIN. It is resolved to a userID
	/* here and the userID is stored. There is NO fallback to a typed name, and
	/* unbooked_name is no longer accepted at all — the column stays in the
	/* schema, nullable and unused.
	/*
	/* EIN IS NOT THE userID AND NEVER HAS BEEN. EIN 5925 is userID 9734 — the
	/* v3.4.10 regression. Resolve, then store what resolved.
	/*
	/* WHY AN UNRESOLVED EIN IS REJECTED, AND WHY THAT IS A CONTROL RATHER THAN
	/* FUSSY VALIDATION. From Mike, 26 Aug 2026: "All workers will have an EIN,
	/* we cannot legally engage workers who are not onboarded, it voids our
	/* workcover." So every person legitimately on site HAS an EIN, by
	/* definition. A rejection therefore means one of exactly two things: the
	/* number was mistyped, or somebody worked who should not have been there.
	/* Both want stopping at the point of entry, and neither is helped by
	/* accepting a typed name instead.
	/*
	/* DO NOT ADD A NAME FALLBACK BACK IN "to be helpful". It would convert the
	/* second case — an un-onboarded worker on site — from a visible rejection
	/* into a silently accepted payroll row.
	/*
	/* THIS CLOSES Q34. An unbooked row now carries a real userID, so
	/* goat_time_submission_for($callID, $userID) finds its predecessor and sets
	/* supersedes_id exactly as for booked crew. The three live unbooked rows
	/* seen in slice 2 testing were a consequence of userID = 0 and cannot
	/* recur. Slice 1's userID <= 0 guard stays correct; it simply stops being
	/* reachable through this path.
	/*
	/* An EIN that resolves to someone already CONFIRMED on the call is allowed.
	/* It is arguably a booked row submitted the wrong way, but it is harmless:
	/* callID + userID is the supersede key, so it chains rather than
	/* duplicating, and refusing it would mean guessing at the boss's intent.
	/*
	/* PHP 5.x — mysql_*, no ??, no short array syntax.
	*/

	function send_status($code, $msg)
	{
		$proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($proto . ' ' . $code . ' ' . $msg);
	}

	/*
	/* Every failure body is built with json_encode(), NEVER by concatenating
	/* into a string literal. unbooked_name and note are the first
	/* user-supplied strings this workstream stores, and the house idiom
	/* die('{"error":"' . addslashes(...) . '"}') emits \' for an apostrophe —
	/* which is not a JSON escape sequence. "O'Brien" in an error message would
	/* produce a body the client cannot parse, and the client would report a
	/* parser complaint instead of the actual validation failure.
	*/

	function sct_fail($code, $status, $message, $extra)
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

	/*
	/* sct_time(), sct_minutes() and sct_void() MOVED to time-submission-graph.php
	/* in slice D of BRIEF-crew-self-times (Nov 2026), as goat_sub_time(),
	/* goat_sub_minutes() and goat_void_submission(), together with the per-row
	/* validation, the 60-minute warning and the append-only write below. The
	/* crew self-submit endpoint (submit-my-times.php) uses the same functions,
	/* so payroll validation has one copy. Behaviour here is unchanged: same
	/* errors in the same order, same 422 / 409 / 500 bodies.
	*/

	/* ---- identity ---- */

	$actor = goat_acting_user_id();   /* owns its own 400 / 401 */

	/* ---- body ---- */

	$raw     = file_get_contents('php://input');
	$payload = json_decode($raw);

	if (!$payload || !isset($payload->callID))
	{
		sct_fail(400, 'Bad Request', 'Invalid or missing JSON body', array());
	}

	$callID  = (int) $payload->callID;
	$confirm = (isset($payload->confirm) && $payload->confirm) ? true : false;

	if ($callID <= 0)
	{
		sct_fail(422, 'Unprocessable Entity', 'callID required', array());
	}

	/*
	/* ---- SCOPE GATE ----
	/*
	/* goat_time_entry_scope(), NOT goat_boss_scope() — CHANGED in slice B of
	/* BRIEF-crew-self-times (Nov 2026). Seeing a call's crew and entering its
	/* times stopped being the same right: a Team Lead on a call that has a
	/* Crew Boss keeps the crew list (D18) and loses entry, and on a dedicated
	/* Crew Boss call only the ticked person enters (D21). The tier rules live
	/* in goat_call_tiers(), supervision-graph.php; nothing here restates them.
	/*
	/* goat_time_entry_scope_for() tiers THIS call only (review R2), and a
	/* failed lookup is a 500, never the 403 below (review R1): "you are not
	/* the person entering times" must only ever mean exactly that.
	*/

	$es = goat_time_entry_scope_for($actor, array($callID));

	if (!$es['ok'])
	{
		sct_fail(500, 'Internal Server Error', 'time-entry scope lookup failed: ' . $es['error'], array());
	}

	if (!in_array($callID, $es['call_ids']))
	{
		sct_fail(403, 'Forbidden', 'You are not the person entering times for this call', array());
	}

	if (!isset($payload->rows) || !is_array($payload->rows) || count($payload->rows) === 0)
	{
		sct_fail(422, 'Unprocessable Entity', 'rows must contain at least one entry', array());
	}

	/*
	/* Confirmed crew on this call. A FAILED LOOKUP IS A 500, NOT AN EMPTY SET.
	/* Degrading to empty would make every row fail validation with "not
	/* confirmed on this call", which reads as the boss having picked the wrong
	/* people rather than as a broken query — the same failure that hid the
	/* ccm.id / crewmapID bug in call-supervision.php on 18 Aug.
	*/

	$confirmed = array();

	$cres = mysql_query("SELECT userID FROM call_crew_map
	                     WHERE callID = " . $callID . "
	                       AND status = 5");

	if ($cres === false)
	{
		sct_fail(500, 'Internal Server Error', 'confirmed-crew lookup failed: ' . mysql_error(), array());
	}

	while ($crow = mysql_fetch_object($cres))
	{
		$confirmed[(int) $crow->userID] = true;
	}

	/*
	/* UNANSWERED PROMOTIONS (15 Oct 2026). A backup promoted to 5 has a
	/* call_promo_ack row with acked_at NULL until they accept in Crew Hub or
	/* Ops dismiss the flag. Until then nobody knows they are working, so no
	/* row may carry their time -- booked, or added by EIN, which would
	/* otherwise be a way round the lock. Same rule as update-call-times.php.
	/* A FAILED LOOKUP IS A 500: it feeds a refusal, so it must not degrade to
	/* "nobody is pending".
	*/

	$promoPending = array();

	$pres = mysql_query("SELECT userID FROM call_promo_ack
	                     WHERE callID = " . $callID . "
	                       AND acked_at IS NULL");

	if ($pres === false)
	{
		sct_fail(500, 'Internal Server Error', 'promotion lookup failed: ' . mysql_error(), array());
	}

	while ($prow = mysql_fetch_object($pres))
	{
		$promoPending[(int) $prow->userID] = true;
	}

	/* ---- validate every row before writing any of them ---- */

	$errors = array();
	$clean  = array();

	foreach ($payload->rows as $i => $row)
	{
		$v = goat_validate_submission_row($row, $i, 'row ' . $i, $confirmed, $promoPending);

		/* a FAILED lookup inside validation stops the request at once, as it
		/* always did — it must never read as a validation error */

		if ($v['fatal'] !== null)
		{
			sct_fail($v['fatal'][0], $v['fatal'][1], $v['fatal'][2], $v['fatal'][3]);
		}

		foreach ($v['errors'] as $e)
		{
			$errors[] = $e;
		}

		if ($v['clean'] !== null)
		{
			$clean[] = $v['clean'];
		}
	}

	if (count($errors))
	{
		sct_fail(422, 'Unprocessable Entity', $errors[0], array('errors' => $errors));
	}

	/*
	/* ---- WARNINGS — overridable with confirm:true ----
	/*
	/* 409 IS A RESULT, NOT AN ERROR. Same contract as call-feeds.php and
	/* call-supervision.php. What trips it is goat_submission_long_break_warnings().
	*/

	$warnings = goat_submission_long_break_warnings($clean);

	if (count($warnings) && !$confirm)
	{
		send_status(409, 'Conflict');
		echo json_encode(array('ok' => false, 'needs_confirm' => true, 'warnings' => $warnings));
		die();
	}

	/* ---- write — append-only, breaks counted back, through the shared helper ---- */

	$submitted = array();

	foreach ($clean as $c)
	{
		$w = goat_write_submission($callID, $c, $actor, 'boss');

		if (!$w['ok'])
		{
			sct_fail($w['code'], $w['status'], $w['error'], $w['extra']);
		}

		$submitted[] = array(
			'row'           => $c['index'],
			'submission_id' => $w['submission_id'],
			'userID'        => (int) $c['userID'],
			'unbooked'      => (int) $c['unbooked'],
			'supersedes_id' => $w['supersedes_id'],
			'breaks'        => $w['breaks']
		);
	}

	/*
	/* A request that dies partway leaves the rows already written in place.
	/* That is deliberate and safe: partial submission is a valid state by
	/* design, and a retry supersedes those rows correctly rather than
	/* duplicating them, because supersedes_id is resolved per row at write
	/* time.
	*/

	echo json_encode(array(
		'ok'        => true,
		'callID'    => $callID,
		'count'     => count($submitted),
		'submitted' => $submitted
	));

?>
