<?php

	/*
	/* HISTORICAL RECORD -- NOT DEPLOYED. This one-off has been run on prod
	/* (calls 39023-39025) and deleted from both servers, 20 Oct 2026. Kept in
	/* the repo for the record only. Do not upload it again.
	*/

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* RUN-ONCE, ADMIN ONLY -- pay the first late cancellations
	/* (NOTE-late-cancellation-first-calls-2026-10-19, step 4).
	/*
	/* DELETE THIS FILE FROM THE SERVER AFTER IT HAS RUN.
	/*
	/* Calls 39023 (Crew Boss), 39024 (Fork) and 39025 (Load Out) were
	/* cancelled on 18 Oct under the old rule: cancel_charge = 1, no
	/* cancel_pay, nothing pre-filled. Mike decided they are late cancellations
	/* under D13 and the crew who were Confirmed are paid. Reinstating and
	/* re-cancelling cannot fix them -- reinstate turns Confirmed back into
	/* Unconfirmed and clears prev_status, which is the fact being paid on --
	/* so they get this one-off instead. It does to a call already cancelled
	/* what cancel-call.php now does at cancel time, with pay_crew = Yes.
	/*
	/* PER CALL
	/*   - refuses a call that is not found, not cancelled, locked, invoiced,
	/*     payslipped, or already has calls.cancel_pay = 1 (so running it twice
	/*     changes nothing the second time), or has no usable start to pre-fill
	/*     from. Refusals happen BEFORE any write to that call.
	/*   - rows with status = 9 AND prev_status = 5 -> cancel_pay = 1
	/*   - those rows' on/off PRE-FILLED only where both are still 00:00:00,
	/*     through cancel-call.php's own helpers in cohort.php
	/*     (goat_cancel_prefill_window / goat_cancel_prefill_row) -- one
	/*     implementation. A row that already has times (Rich keyed 09:00-17:00
	/*     on 8818 and 8865) KEEPS them, and is reported kept_existing.
	/*   - calls.cancel_pay = 1, LAST. cancel_charge (already 1) and
	/*     cancel_times_confirmed_at (NULL, so Rich's save is still the
	/*     done-tick) are left alone.
	/*
	/* ORDERED SO A PARTIAL RUN IS SAFE. MyISAM has no transactions. The call
	/* flag is written last, so a run that dies mid-call has not yet set the
	/* "already done" refusal, and re-running finishes it: the row flags are
	/* idempotent and the pre-fill never overwrites.
	/*
	/* EXPECTED ON PROD: 39023 -> 1 kept_existing, 39024 -> 1 kept_existing,
	/* 39025 -> 15 prefilled. Anything else: stop and check.
	/*
	/* Request:  GET or POST ?ids=39023,39024,39025
	/*   No ids -> 400. Non-admin -> 403. Migration absent -> 503.
	/*   A GET writes. That is deliberate for a run-once script Mike opens in a
	/*   logged-in browser, and is why the file must not stay on the server.
	/*
	/* Response: per call, the crew it is about (status 9, prev 5) with names
	/* and the on/off actually stored AFTER the run -- re-read, not echoed --
	/* and each row's result: prefilled | kept_existing.
	/*
	/* PHP 5.x -- mysql_*, array(), no null-coalescing (??), no short arrays, tabs.
	*/

	/* ---- admin gate ---- */

	if (goat_user_cohort() !== 'admin')
	{
		goat_json_error(403, 'Admin only');
	}

	/* ---- ids ---- */

	$idsRaw = isset($_GET['ids']) ? trim((string) $_GET['ids']) : '';

	if ($idsRaw === '')
	{
		goat_json_error(400, 'ids is required, e.g. ?ids=39023,39024,39025');
	}

	$ids = array();

	foreach (explode(',', $idsRaw) as $part)
	{
		$part = trim($part);

		if (!preg_match('/^\d+$/', $part) || (int) $part <= 0)
		{
			goat_json_error(400, 'ids must be a comma-separated list of call ids; could not read "' . $part . '"');
		}

		$ids[(int) $part] = true;
	}

	$ids = array_keys($ids);

	if (count($ids) > 20)
	{
		goat_json_error(422, 'at most 20 calls per run');
	}

	/* ---- migration present ---- */

	if (!goat_ccm_have_cancel_pay())
	{
		goat_json_error(503, 'The late-cancellation migration has not run on this database. Nothing was changed.');
	}

	/* ---- the stood-down Confirmed crew of one call, as stored now ---- */

	function olcp_paid_rows($callID)
	{
		$res = mysql_query(
			"SELECT ccm.userID AS user_id, ccm.`on` AS on_time, ccm.`off` AS off_time,
			        ccm.cancel_pay AS cancel_pay,
			        u.firstname AS firstname, u.lastname AS lastname, u.ein AS ein
			   FROM call_crew_map ccm
			   LEFT JOIN users u ON u.id = ccm.userID
			  WHERE ccm.callID = " . (int) $callID . "
			    AND ccm.status = 9
			    AND ccm.prev_status = 5
			  ORDER BY u.lastname ASC, u.firstname ASC"
		);

		if ($res === false)
			return mysql_error();

		$rows = array();

		while ($r = mysql_fetch_object($res))
		{
			$rows[] = $r;
		}

		return $rows;
	}

	/* ---- run ---- */

	$out   = array();
	$fatal = false;

	foreach ($ids as $callID)
	{
		$entry = array(
			'call_id'       => $callID,
			'call_name'     => '',
			'result'        => '',
			'reason'        => '',
			'prefilled'     => 0,
			'kept_existing' => 0,
			'crew'          => array()
		);

		$cres = mysql_query(
			"SELECT id, call_name, call_locked, invoiceID, invoice_generated,
			        payslips_generated, cancelled_at, cancel_pay, cancel_charge,
			        cancel_times_confirmed_at, start_date, start_time, est_length
			   FROM calls WHERE id = " . (int) $callID
		);

		if ($cres === false)
		{
			$entry['result'] = 'error';
			$entry['reason'] = 'call read failed: ' . mysql_error();
			$out[] = $entry;
			$fatal = true;
			break;
		}

		$call = mysql_fetch_object($cres);

		if (!$call)
		{
			$entry['result'] = 'refused';
			$entry['reason'] = 'call not found';
			$out[] = $entry;
			continue;
		}

		$entry['call_name'] = html_entity_decode((string) $call->call_name, ENT_QUOTES);

		/*
		/* REFUSALS -- all before any write. The four accounting flags are named
		/* individually, as cancel-call.php names them.
		*/

		$refuse = '';

		if ($call->cancelled_at === null || (int) $call->cancelled_at <= 0)
			$refuse = 'not cancelled';
		else if ((int) $call->call_locked === 1)
			$refuse = 'call is LOCKED for accounting (call_locked)';
		else if ((int) $call->invoice_generated === 1)
			$refuse = 'an INVOICE has been generated (invoice_generated)';
		else if ((int) $call->invoiceID > 0)
			$refuse = 'attached to INVOICE #' . (int) $call->invoiceID;
		else if ((int) $call->payslips_generated === 1)
			$refuse = 'PAYSLIPS have been generated (payslips_generated)';
		else if ((int) $call->cancel_pay === 1)
			$refuse = 'already done: calls.cancel_pay is 1';

		$win = ($refuse === '') ? goat_cancel_prefill_window($call) : null;

		if ($refuse === '' && $win === null)
			$refuse = 'no usable start date/time to pre-fill from';

		if ($refuse !== '')
		{
			$entry['result'] = 'refused';
			$entry['reason'] = $refuse;
			$out[] = $entry;
			continue;
		}

		/* 1. the rows: who was Confirmed when stood down */

		$rows = olcp_paid_rows($callID);

		if (is_string($rows))
		{
			$entry['result'] = 'error';
			$entry['reason'] = 'roster read failed: ' . $rows;
			$out[] = $entry;
			$fatal = true;
			break;
		}

		/* 2. cancel_pay on those rows */

		mysql_query(
			"UPDATE call_crew_map SET cancel_pay = 1
			  WHERE callID = " . (int) $callID . "
			    AND status = 9 AND prev_status = 5"
		);

		if (mysql_error() !== '')
		{
			$entry['result'] = 'error';
			$entry['reason'] = 'cancel_pay row write failed: ' . mysql_error();
			$out[] = $entry;
			$fatal = true;
			break;
		}

		/* 3. pre-fill, never overwriting */

		$resultOf = array();   /* userID -> prefilled | kept_existing */
		$failed   = '';

		foreach ($rows as $r)
		{
			$uid = (int) $r->user_id;

			if (!goat_ccm_times_blank($r->on_time, $r->off_time))
			{
				$resultOf[$uid] = 'kept_existing';
				continue;
			}

			$pf = goat_cancel_prefill_row($callID, $uid, $win);

			if (is_string($pf))
			{
				$failed = 'pre-fill failed for user ' . $uid . ': ' . $pf;
				break;
			}

			/* false = the row gained times between our read and our write;
			/* the WHERE kept them, so they are existing times */
			$resultOf[$uid] = ($pf === true) ? 'prefilled' : 'kept_existing';
		}

		if ($failed !== '')
		{
			$entry['result'] = 'error';
			$entry['reason'] = $failed . ' -- calls.cancel_pay NOT set, so re-running finishes this call';
			$out[] = $entry;
			$fatal = true;
			break;
		}

		/* 4. the call flag, LAST */

		mysql_query("UPDATE calls SET cancel_pay = 1 WHERE id = " . (int) $callID);

		if (mysql_error() !== '')
		{
			$entry['result'] = 'error';
			$entry['reason'] = 'calls.cancel_pay write failed: ' . mysql_error() . ' -- rows are done; re-running finishes this call';
			$out[] = $entry;
			$fatal = true;
			break;
		}

		/* 5. report what is STORED now, not what we meant to write */

		$after = olcp_paid_rows($callID);

		if (is_string($after))
		{
			$entry['result'] = 'error';
			$entry['reason'] = 'written, but the re-read failed: ' . $after;
			$out[] = $entry;
			$fatal = true;
			break;
		}

		foreach ($after as $r)
		{
			$uid = (int) $r->user_id;
			$res = isset($resultOf[$uid]) ? $resultOf[$uid] : 'unknown';

			if ($res === 'prefilled')     $entry['prefilled']++;
			if ($res === 'kept_existing') $entry['kept_existing']++;

			$entry['crew'][] = array(
				'user_id'    => $uid,
				'ein'        => $r->ein,
				'name'       => trim(html_entity_decode((string) $r->firstname, ENT_QUOTES) . ' ' .
				                     html_entity_decode((string) $r->lastname, ENT_QUOTES)),
				'on'         => $r->on_time,
				'off'        => $r->off_time,
				'cancel_pay' => (int) $r->cancel_pay,
				'result'     => $res
			);
		}

		$entry['result'] = 'done';

		/*
		/* Not a refusal, but worth seeing: a ticked call is already off the
		/* ops lane, so Rich would not be prompted to check it.
		*/

		if ($call->cancel_times_confirmed_at !== null)
			$entry['reason'] = 'WARNING: cancel_times_confirmed_at is already set -- this call will not appear on the ops lane';

		if ((int) $call->cancel_charge !== 1)
			$entry['reason'] = trim($entry['reason'] . ' NOTE: cancel_charge is ' . (int) $call->cancel_charge . ', so this is pay-only (D20 reminder applies)');

		$out[] = $entry;
	}

	echo json_encode(array(
		'ok'      => !$fatal,
		'stopped' => $fatal,
		'ran_at'  => date('Y-m-d\TH:i:s'),
		'calls'   => $out,
		'reminder'=> 'Delete oneoff-late-cancel-pay.php from the server now.'
	));

?>
