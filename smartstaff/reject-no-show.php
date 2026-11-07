<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include_once('time-submission-graph.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* "NOT A NO-SHOW" — OPS REJECT A CREW BOSS'S NO-SHOW REPORT
	/* (BRIEF-boss-no-show-slice-3 §2).
	/*
	/*   POST  body {callID, userID, submission_id}
	/*
	/* A report is a call_time_submissions row with no_show = 1 (slice 1). Ops
	/* resolve it one of two ways:
	/*
	/*   Confirm -> status 8, through update-crew-status.php (not here)
	/*   Reject  -> THIS: void the report row
	/*
	/* Voiding is the only change. call_time_submissions is append-only and
	/* nothing in it is ever deleted or rewritten. Voiding a row is what every
	/* reader in time-submission-graph.php already treats as "not there", so
	/* the person's live row falls back to whatever the boss sent BEFORE the
	/* report. If they sent nothing before it, the person is owed times again.
	/*
	/* REFUSES (409, with `reason` and a message the grid shows) UNLESS ALL OF:
	/*
	/*   the row exists and belongs to this call and this person   not_found
	/*   it is not already voided                                  already_rejected
	/*   it is a No-show report (no_show = 1)                      not_no_show
	/*   it is that person's LATEST live row on the call           not_latest
	/*   the person is still status 5 on the call                  already_confirmed (8)
	/*                                                             not_confirmed (other)
	/*
	/* not_latest matters. If the boss has since resent times for this person,
	/* the report is no longer the live answer, so voiding it would only rewrite
	/* history. The grid reloads instead.
	/*
	/* RETURNS THE PERSON'S LIVE ROW AFTER THE VOID, so the grid can show it
	/* without a second read:
	/*
	/*   {ok:true, callID, userID, voided_id,
	/*    restored: {submission_id, on_time, off_time, off_next_day, breaks,
	/*               note, no_show} | null}
	/*
	/* restored is not null when the boss entered times BEFORE reporting the
	/* person (the slice 1 finding, 6 Nov 2026). Those times are the best
	/* record there is, and they come back as the live row. no_show is
	/* included because the earlier row could itself be an older report.
	/*
	/* EVERY FAILED LOOKUP IS A 500, NEVER A PASS. Each one feeds either the
	/* refusal or the claim about what is live now.
	/*
	/* ADMIN ONLY, SESSION ONLY. This uses the same gate as accept-call-times.php
	/* and update-crew-status.php, which are the other two halves of resolving
	/* a report. cohort.php keeps write endpoints at usergroupID 1, and THE
	/* GOAT's route is @require_cohort("admin"). goat_user_cohort() reads only
	/* the SmartStaff session, so a service-key caller (the Crew Hub backend)
	/* is refused: a crew boss must never be able to withdraw a report through
	/* Ops' door.
	/*
	/* mysql_*, NOT mysqli_*, AND ON PURPOSE. The void is goat_void_submission()
	/* in time-submission-graph.php, which is mysql_* on SmartStaff's single
	/* connection. A mysqli_* connection here would be a second connection,
	/* with its own view of the row it has just checked. Convert this file
	/* together with time-submission-graph.php.
	/*
	/* PHP 5.6 — array(), no ??.
	*/

	function rns_status($code, $msg)
	{
		$proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($proto . ' ' . $code . ' ' . $msg);
	}

	/* every body through json_encode(): mysql_error() quotes identifiers */

	function rns_fail($code, $status, $message, $extra)
	{
		rns_status($code, $status);

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

	function rns_refuse($reason, $message)
	{
		rns_fail(409, 'Conflict', $message, array('reason' => $reason));
	}

	/* The person's LIVE row on the call: highest non-voided id, which is how
	/* goat_time_submission_for() resolves it. Written out here rather than
	/* calling that helper, because the helper returns array() on a failed
	/* query and this endpoint must tell "none" apart from "unknown".
	/* Returns false on failure, null when there is no live row, or the row. */

	function rns_live_row($callID, $userID)
	{
		$res = mysql_query("SELECT s.*
		                    FROM call_time_submissions s
		                    WHERE s.callID = " . (int) $callID . "
		                      AND s.userID = " . (int) $userID . "
		                      AND s.voided = 0
		                    ORDER BY s.id DESC
		                    LIMIT 1");

		if ($res === false)
		{
			return false;
		}

		$row = mysql_fetch_assoc($res);

		return $row ? $row : null;
	}

	/* the TIME column as HH:MM, '' when empty — my-call-times.php's mct_hhmm()
	/* (PHP 5.6: substr('', 0, 5) is FALSE) */

	function rns_hhmm($v)
	{
		$v = ($v === null) ? '' : (string) $v;

		if ($v === '')
		{
			return '';
		}

		$out = substr($v, 0, 5);

		return ($out === false) ? '' : $out;
	}

	/* ---- the gate ---- */

	if (goat_user_cohort() !== 'admin')
	{
		rns_fail(403, 'Forbidden', 'Admin only', array());
	}

	if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST')
	{
		rns_fail(405, 'Method Not Allowed', 'POST only', array());
	}

	/* ---- body ---- */

	$payload = json_decode(file_get_contents('php://input'));

	if (!$payload || !isset($payload->callID) || !isset($payload->userID) || !isset($payload->submission_id))
	{
		rns_fail(400, 'Bad Request', 'Body must be {callID, userID, submission_id}', array());
	}

	$callID = (int) $payload->callID;
	$userID = (int) $payload->userID;
	$subID  = (int) $payload->submission_id;

	if ($callID <= 0 || $userID <= 0 || $subID <= 0)
	{
		rns_fail(422, 'Unprocessable Entity', 'callID, userID and submission_id must be positive', array());
	}

	/* Before the slice 1 migration there is no no_show column, so there
	/* cannot be a report to reject. Saying "not a report" would be a guess
	/* about a schema that is not there. */

	if (!goat_ts_have_no_show())
	{
		rns_fail(500, 'Internal Server Error', 'call_time_submissions has no no_show column', array());
	}

	/* ---- the row ---- */

	$res = mysql_query("SELECT id, callID, userID, no_show, voided
	                    FROM call_time_submissions
	                    WHERE id = " . $subID . "
	                    LIMIT 1");

	if ($res === false)
	{
		rns_fail(500, 'Internal Server Error', 'report lookup failed: ' . mysql_error(), array());
	}

	$row = mysql_fetch_object($res);

	if (!$row || (int) $row->callID !== $callID || (int) $row->userID !== $userID)
	{
		rns_refuse('not_found', 'That No-show report is not on this call for this crew member.');
	}

	if ((int) $row->voided !== 0)
	{
		rns_refuse('already_rejected', 'That No-show report has already been rejected.');
	}

	if ((int) $row->no_show !== 1)
	{
		rns_refuse('not_no_show', 'That entry is the boss\'s times, not a No-show report.');
	}

	$live = rns_live_row($callID, $userID);

	if ($live === false)
	{
		rns_fail(500, 'Internal Server Error', 'live submission lookup failed: ' . mysql_error(), array());
	}

	if ($live === null || (int) $live['id'] !== $subID)
	{
		rns_refuse('not_latest', 'The crew boss has sent something newer for this crew member. Reload the times to see it.');
	}

	/* ---- the person's status ---- */

	$sres = mysql_query("SELECT status FROM call_crew_map
	                     WHERE callID = " . $callID . "
	                       AND userID = " . $userID . "
	                     LIMIT 1");

	if ($sres === false)
	{
		rns_fail(500, 'Internal Server Error', 'crew status lookup failed: ' . mysql_error(), array());
	}

	$ccm = mysql_fetch_object($sres);

	if (!$ccm)
	{
		rns_refuse('not_confirmed', 'This crew member is no longer booked on this call.');
	}

	if ((int) $ccm->status === 8)
	{
		rns_refuse('already_confirmed', 'This No-show has already been confirmed. Change their status on the call if it was wrong.');
	}

	if ((int) $ccm->status !== 5)
	{
		rns_refuse('not_confirmed', 'This crew member is no longer confirmed on this call.');
	}

	/* ---- the void ---- */

	goat_void_submission($subID);

	/* goat_void_submission() returns nothing, so read the row back. If the
	/* UPDATE failed, or the read fails, the caller is told so. They are never
	/* told the report was rejected while it might still stand. */

	$vres = mysql_query("SELECT voided FROM call_time_submissions WHERE id = " . $subID . " LIMIT 1");

	if ($vres === false)
	{
		rns_fail(500, 'Internal Server Error', 'void read-back failed: ' . mysql_error(), array());
	}

	$vrow = mysql_fetch_object($vres);

	if (!$vrow || (int) $vrow->voided !== 1)
	{
		rns_fail(500, 'Internal Server Error', 'the report could not be voided', array());
	}

	/* ---- what is live for them now ---- */

	$now = rns_live_row($callID, $userID);

	if ($now === false)
	{
		rns_fail(500, 'Internal Server Error',
		         'report rejected, but reading what replaced it failed: ' . mysql_error(),
		         array('voided_id' => $subID));
	}

	$restored = null;

	if ($now !== null)
	{
		$breaks = array();

		$bres = mysql_query("SELECT seq, start_time, start_next_day, duration_mins
		                     FROM call_time_submission_breaks
		                     WHERE submission_id = " . (int) $now['id'] . "
		                     ORDER BY seq ASC, id ASC");

		if ($bres === false)
		{
			rns_fail(500, 'Internal Server Error',
			         'report rejected, but reading the restored breaks failed: ' . mysql_error(),
			         array('voided_id' => $subID));
		}

		while ($b = mysql_fetch_assoc($bres))
		{
			$breaks[] = array(
				'seq'            => (int) $b['seq'],
				'start_time'     => rns_hhmm($b['start_time']),
				'start_next_day' => (int) $b['start_next_day'],
				'duration_mins'  => (int) $b['duration_mins']
			);
		}

		$restored = array(
			'submission_id' => (int) $now['id'],
			'on_time'       => rns_hhmm($now['on_time']),
			'off_time'      => rns_hhmm($now['off_time']),
			'off_next_day'  => (int) $now['off_next_day'],
			'breaks'        => $breaks,
			'note'          => ($now['note'] === null ? '' : $now['note']),
			'no_show'       => ((int) $now['no_show'] === 1) ? 1 : 0
		);
	}

	echo json_encode(array(
		'ok'        => true,
		'callID'    => $callID,
		'userID'    => $userID,
		'voided_id' => $subID,
		'restored'  => $restored
	));

?>
