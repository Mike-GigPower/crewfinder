<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* ADMIN-ONLY WRITE — reinstate a cancelled call (slice R1 of
	/* BRIEF-call-reinstate.md). The reverse of cancel-call.php.
	/*
	/* 5.24.0 shipped Stand down with no way back: the only route was SQL, which
	/* means it was not a route Ops had. Design section 8.5 always said a
	/* cancellation is reversible while the call is future-dated and unlocked.
	/*
	/* WHAT THIS WRITES
	/*   call_crew_map   : status restored per the map below, prev_status cleared,
	/*                     cancel_reason / cancel_charge cleared -- per row
	/*   calls           : cancelled_at, cancelled_by -> NULL, cancel_reason and
	/*                     cancel_charge cleared, "CANCELLED - " prefix stripped
	/*   calendars       : NOTHING. See "no calendar entries" below.
	/*
	/* NOBODY COMES BACK COMMITTED
	/*
	/*   was  ->  becomes   why
	/*   5        0         said yes; must say yes again
	/*   7        7         a backup is already a conditional yes -- see below
	/*   1        1         never answered; the offer is simply live again
	/*   0        0         unchanged
	/*   6        6         they declined; re-offering is an ops decision
	/*   8        8         unchanged
	/*
	/* A Confirmed crew member returns UNCONFIRMED (0), not Pending (1), and the
	/* difference is not cosmetic. get-open-offers-bulk.php buckets on exactly this
	/* distinction: status 0 is "not_messaged", status 1 is "awaiting". Nothing is
	/* messaged on reinstatement -- there is no /api/push/cancel and no SMS fires --
	/* so status 1 would drop every restored crew member into ops' *awaiting*
	/* bucket, chasing replies to messages that never went out. Status 0 is true,
	/* and still appears in the open-offers feed, so the call is genuinely live
	/* again rather than merely un-hidden.
	/*
	/* BACKUPS STAY BACKUPS. A backup restored to 0 who then accepts becomes
	/* CONFIRMED -- silently promoting a standby into a confirmed slot and
	/* over-booking the call, with nothing in the UI showing it happened. The
	/* accept flow has no memory that someone was a backup, so this cannot be
	/* fixed downstream. Leaving them at 7 commits nobody.
	/*
	/* NO CALENDAR ENTRIES ARE WRITTEN. Nobody returns Confirmed, so nobody should
	/* hold one -- the calendar is written when they accept, by the existing accept
	/* flow. This also disposes of a problem that had no clean answer: cancel-call.php
	/* clears calendars UNCONDITIONALLY (deliberately -- CHANGELOG-3_18_0 recorded
	/* prod status-7 rows as "a mix with and without", and call 37866's backup had
	/* one), so the pre-cancellation calendar state was never recorded and could not
	/* have been reproduced. Now it does not need to be.
	/*
	/* WHAT THIS DELIBERATELY DOES NOT DO
	/*   - It does NOT notify anybody, for the same reason cancel-call.php does not:
	/*     PHP has never held the Crew Hub push trigger. It RETURNS "notify" and
	/*     app.py owns delivery. In this phase app.py sends nothing either -- there
	/*     is no /api/push/cancel -- so the UI must render that list as people who
	/*     still need ringing.
	/*   - It does NOT touch rows that are not status 9. D12 permits re-adding a
	/*     crew member to a cancelled call, which creates a fresh status-0 row with
	/*     no prev_status. Those people were added deliberately AFTER the
	/*     cancellation and must survive reinstatement unchanged.
	/*   - It does NOT restore a reduction. There is no reduction yet.
	/*
	/* IDEMPOTENT, AND ORDERED SO A PARTIAL RUN IS SAFE. MyISAM has no transactions.
	/* The crew sweep runs FIRST and ALWAYS -- even when calls.cancelled_at is
	/* already NULL -- so a half-finished reinstate can be completed by re-running,
	/* rather than refused because the call no longer looks cancelled.
	/*
	/* The order is the safety property, not a detail. Clearing the call first and
	/* then failing would leave a LIVE call carrying status-9 crew: visible in the
	/* Schedule, wrong counts, and invisible to every read that filters on
	/* cancelled_at. Failing after the crew sweep leaves the call still hidden with
	/* its crew restored -- untidy, but nobody is misled and re-running fixes it.
	/*
	/* Request:  POST ?id=<callID>
	/*   Body is optional and ignored. There is nothing to decide: unlike
	/*   cancellation, which needs a chargeable ruling and a reason, reinstating
	/*   restores a state that was already recorded.
	/*
	/* PHP 5.x -- mysql_*, array(), no null-coalescing (??), no short arrays, tabs.
	*/

	/* ---- helpers ---- */

	function send_status($code, $msg)
	{
		$proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($proto . ' ' . $code . ' ' . $msg);
	}

	/*
	/* Quote explicitly rather than relying on $db->sc(), whose callers in this
	/* codebase disagree about whether it adds the quotes itself. Matches
	/* cancel-call.php.
	*/
	function q($str)
	{
		return "'" . mysql_real_escape_string((string) $str) . "'";
	}

	/* ---- admin gate ----
	/*
	/* Same gate as cancel-call.php / update-call-times.php. goat_user_cohort()
	/* returns 'admin' only for usergroupID == 1.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		send_status(403, 'Forbidden');
		die('{"error":"Admin only"}');
	}

	/*
	/* The OPERATOR, for the response only -- there is no reinstated_by column.
	/* Read from the session, never from request input, for the same reason
	/* cancel-call.php does: goat_acting_user_id()'s service-key branch takes
	/* userID from the request.
	*/

	$actorID = (int) $_SESSION[SITE_KEY]['userID'];

	/* ---- target call ---- */

	$callID = isset($_GET['id']) ? intval($_GET['id']) : 0;

	if ($callID <= 0)
	{
		send_status(400, 'Bad Request');
		die('{"error":"Missing or invalid ?id"}');
	}

	$call = $db->selectFirst(
		'id, bookingID, call_name, required, call_locked, invoiceID,
		 invoice_generated, payslips_generated, cancelled_at,
		 start_date, start_time',
		'calls',
		'id=' . $callID
	);

	if (!$call)
	{
		send_status(404, 'Not Found');
		die('{"error":"Call not found"}');
	}

	/* ---- accounting guards ----
	/*
	/* The same four flags cancel-call.php refuses on, named individually for the
	/* same reason: a locking problem, a billing problem and a payroll problem need
	/* different follow-ups.
	/*
	/* These are checked even when the call no longer looks cancelled, because the
	/* crew sweep below writes to call_crew_map regardless -- an invoiced call must
	/* not have its roster rewritten by a re-run.
	/*
	/* WHEN D7 LANDS a fifth guard belongs here: a cancellation charge already
	/* raised in invoice_lines must block reinstatement, or the customer is billed
	/* for a call that went ahead.
	*/

	$blocked = '';

	if ((int) $call->call_locked === 1)
		$blocked = 'the call is LOCKED for accounting (call_locked)';
	else if ((int) $call->invoice_generated === 1)
		$blocked = 'an INVOICE has been generated for this call (invoice_generated)';
	else if ((int) $call->invoiceID > 0)
		$blocked = 'this call is attached to INVOICE #' . (int) $call->invoiceID;
	else if ((int) $call->payslips_generated === 1)
		$blocked = 'PAYSLIPS have been generated for this call (payslips_generated)';

	if ($blocked !== '')
	{
		send_status(409, 'Conflict');
		echo json_encode(array(
			'error'   => 'Cannot reinstate: ' . $blocked . '. A call that has been through accounting is not reinstated from THE GOAT -- speak to accounts.',
			'blocked' => true
		));
		exit;
	}

	/* ---- the call must still be in the future ----
	/*
	/* Design section 8.5: reversible while FUTURE-DATED and unlocked. A shift that
	/* has already begun cannot be reinstated -- nobody was there, and putting the
	/* call back would claim crew for hours that have already passed.
	/*
	/* start_date is a unix timestamp for the DAY and start_time is 'HH:MM:SS', so
	/* the instant has to be rebuilt from both. A missing or zero start_date
	/* resolves to 1970 and therefore refuses -- the safe direction. A call whose
	/* start we cannot establish is not one to bring back automatically.
	*/

	$startTs = strtotime(date('Y-m-d', (int) $call->start_date) . ' ' . $call->start_time);

	if ($startTs === false || $startTs <= time())
	{
		send_status(409, 'Conflict');
		echo json_encode(array(
			'error'   => 'Cannot reinstate: this call has already started (or its start time could not be read). Reinstating only applies to a call that is still in the future.',
			'blocked' => true,
			'started' => true
		));
		exit;
	}

	/* ---- restore the roster ----
	/*
	/* Runs FIRST and ALWAYS -- see the ordering note in the header. Only status-9
	/* rows are read; anything else on this call was put there deliberately after
	/* the cancellation and is left alone.
	*/

	$crew = array();
	$res  = mysql_query(
		'SELECT crewmapID, userID, status, prev_status
		   FROM call_crew_map
		  WHERE callID = ' . $callID . ' AND status = 9'
	);

	if ($res === false)
	{
		send_status(500, 'Internal Server Error');
		die('{"error":"roster read failed: ' . addslashes(mysql_error()) . '"}');
	}

	while ($row = mysql_fetch_object($res))
	{
		$crew[] = $row;
	}

	$results = array();
	$notify  = array();

	foreach ($crew as $row)
	{
		$userID = (int) $row->userID;

		/*
		/* prev_status NULL should be impossible -- cancel-call.php writes it in the
		/* same UPDATE that sets status 9. But if one exists, the worst outcome is
		/* leaving a status-9 row on a LIVE call: invisible to every read that
		/* filters on cancelled_at, and wrong in the roster.
		/*
		/* So restore it to 0 and SAY SO in the response, rather than skipping it.
		/* Ops seeing "came back in an unknown state" is recoverable; a row nobody
		/* can see is not.
		*/

		$unknownPrev = ($row->prev_status === null || $row->prev_status === '');
		$prev        = $unknownPrev ? 0 : (int) $row->prev_status;

		/* 9 in prev_status would mean a double-cancel overwrote the real value.
		/* Treat it as unknown for the same reason. */
		if ($prev === 9)
		{
			$unknownPrev = true;
			$prev        = 0;
		}

		/* THE RESTORE MAP. Only 5 moves; everything else returns as it was. */
		$new = ($prev === 5) ? 0 : $prev;

		$db->update(
			'call_crew_map',
			array(
				'status'        => $new,
				'prev_status'   => 'NULL',
				'cancel_reason' => q(''),
				'cancel_charge' => 0
			),
			'status = 9 AND callID=' . $callID . ' AND userID=' . $userID
		);

		/*
		/* 'NULL' is passed unquoted on purpose. $db->update() interpolates values
		/* raw -- which is precisely why cancel-call.php wraps its free text in q()
		/* -- so the bare word reaches SQL as the keyword, not as the string "NULL".
		*/

		if (mysql_error())
		{
			send_status(500, 'Internal Server Error');
			die('{"error":"crew restore failed: ' . addslashes(mysql_error()) . '"}');
		}

		/*
		/* Who app.py must tell. Mirrors cancel-call.php's rule: anyone holding a
		/* live assignment again. Declined (6) and no-show (8) are not contacted --
		/* they already answered, or already didn't turn up, and reinstating the
		/* call does not change that.
		*/

		if ($new === 0 || $new === 1 || $new === 7)
			$notify[] = $userID;

		$results[] = array(
			'user_id'      => $userID,
			'prev_status'  => $prev,
			'new_status'   => $new,
			'unknown_prev' => $unknownPrev,
			'changed'      => true
		);
	}

	/* ---- clear the cancellation on the call ----
	/*
	/* After the roster, never before. If this file dies above, the call is still
	/* hidden and re-running finishes the job.
	/*
	/* Strip only the prefix cancel-call.php wrote, and only at position 0, so a
	/* call genuinely named "Cancelled Show Cover" keeps its name.
	*/

	$name    = $call->call_name;
	$prefix  = 'CANCELLED - ';
	$newName = (strpos($name, $prefix) === 0) ? substr($name, strlen($prefix)) : $name;

	$wasCancelled = ($call->cancelled_at !== null && (int) $call->cancelled_at > 0);

	$db->update(
		'calls',
		array(
			'cancelled_at'  => 'NULL',
			'cancelled_by'  => 'NULL',
			'cancel_reason' => q(''),
			'cancel_charge' => 0,
			'call_name'     => q($newName)
		),
		'id=' . $callID
	);

	if (mysql_error())
	{
		send_status(500, 'Internal Server Error');
		die('{"error":"call reinstate write failed: ' . addslashes(mysql_error()) . '"}');
	}

	/* ---- respond ----
	/*
	/* "already" is true when the call was not cancelled when we arrived. It is NOT
	/* the same as "nothing happened": a half-finished reinstate leaves the call
	/* clear with status-9 crew behind it, and this run will have swept those. The
	/* crew array says what was actually done, and it is the field to read.
	*/

	/*
	/* Wall-clock ISO for the Crew Hub push. The columns were already read for
	/* the started-guard above; this only stops throwing them away.
	*/

	$startIso = ((int) $call->start_date > 0)
		? date('Y-m-d', (int) $call->start_date) . 'T' . $call->start_time
		: '';

	echo json_encode(array(
		'ok'             => true,
		'already'        => !$wasCancelled,
		'call_id'        => $callID,
		'booking_id'     => (int) $call->bookingID,
		'call_name'      => $newName,
		'start'          => $startIso,
		'reinstated_at'  => time(),
		'reinstated_by'  => $actorID,
		'restored'       => count($results),
		'crew'           => $results,
		'notify'         => $notify
	));

?>
