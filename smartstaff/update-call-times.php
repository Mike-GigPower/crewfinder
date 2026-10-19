<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* ADMIN ONLY (same gate as update-call.php / create-booking.php). */

	if (goat_user_cohort() !== 'admin')
	{
		send_status(403, 'Forbidden');
		die('{"error":"Admin only"}');
	}

	/*
	/* Writes per-crew ACTUAL TIMES for a call into call_crew_map, replicating the
	/* native admin save-times path (add-call.php action=savetimes / call-times.php),
	/* which writes -- in ONE update per crew -- on, break, break_night, off,
	/* callpaygradeID and the four derived rate columns copied from the paygrades row.
	/* We replicate that copy exactly so payroll sees the same data as a SmartStaff
	/* save (callrate defaults to 0, so a times-only write that skipped the paygrade
	/* copy could leave a crew member un-rated -- that is why rate is bundled here).
	/*
	/* We ADD two columns the native grid doesn't carry:
	/*   - goat_note : free-text per-crew note (GOAT-only column; native UI ignores it)
	/*   - late      : '1'/'0' (the native LATE button only ever sets '1'; the GOAT
	/*                  grid prefills from the stored value and round-trips it)
	/*
	/* PARTIAL WRITES: each row writes ONLY the keys it actually sends. A row that
	/* omits callpaygradeID leaves the grade and all four rate columns untouched (so a
	/* times-only import can never zero a rate); a row that omits note/late leaves those
	/* untouched. Send an empty string to clear a note.
	/*
	/* We DELIBERATELY never write:
	/*   - status              (no-show stays on update-crew-status.php, status=8)
	/*   - user_entered_times  (that flag means the CREW self-reported via the portal --
	/*                          set only by user-times.php; the admin path never sets it)
	/*   - calls.times_filled / calls.call_locked  (human review + the accounting lock
	/*                          stay a SmartStaff action; we never trip generateCallData)
	/* and we run NO addToCalendar loop -- actual worked times don't change the
	/* scheduled calendar entry.
	/*
	/* Refuses a row for a crew member whose promotion off backup is unanswered
	/* (call_promo_ack.acked_at IS NULL) -- see the check in the row loop.
	/*
	/* CANCELLED CREW (status 9) -- REFUSED UNLESS PAID (BRIEF-late-cancellation-
	/* pay, slice B). A stood-down row is writable ONLY when it passes
	/* goat_ccm_cancel_editable_sql (cohort.php): Confirmed when the call was
	/* cancelled, ops ticked pay or charge, AND the call is not settled under
	/* the old rule (cancel_times_confirmed_at = cancelled_at -- every call
	/* cancelled before this feature, and every legacy chargeable-only
	/* cancel). Every other status-9 row is refused per row with code
	/* "cancelled"; the rest of the
	/* save still lands. Until this, the grid refused cancelled crew only
	/* incidentally (THE GOAT never sent them); now the server says so. A
	/* failed check FAILS CLOSED, as the promo_pending guard does.
	/*
	/* DONE-TICK. On a cancelled call, once every paid-cancellation row on the
	/* call has saved in one request with none refused, this sets
	/* calls.cancel_times_confirmed_at -- the condition that takes the call off
	/* the ops Times outstanding lane. Pre-filled times make the call look
	/* keyed, so "keyed" cannot be the done-condition; Rich's save is.
	/*
	/* Refuses to write when the call is locked (closed/invoiced) to protect accounting.
	/* Each crew member must already be booked on the call; a row for a crew member not
	/* on the call is reported and skipped, never inserted. Re-running is safe
	/* (UPDATE by callID+userID; last write wins).
	/*
	/* Call id via ?id=N. Body JSON:
	/*   { "rows": [ { "user_id":N, "on":"HH:MM", "break":"HH:MM",
	/*                 "break_night":"HH:MM", "off":"HH:MM", "callpaygradeID":K,
	/*                 "late":0|1, "note":"..." }, ... ] }
	/*
	/* PHP 5.x -- no null-coalescing (??), no short array syntax.
	*/

	/* ---- helpers ---- */

	function P($obj, $key, $default = '')
	{
		return (isset($obj->$key) && $obj->$key !== null) ? $obj->$key : $default;
	}

	function has_key($obj, $key)
	{
		return (isset($obj->$key) && $obj->$key !== null);
	}

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

	/*
	/* Capability checks. cancelled_at predates this (5.24.0); the paid test
	/* needs the late-cancellation migration. Without it, NO status-9 row can
	/* pass the test, so every one is refused -- the fail-closed direction,
	/* and exactly the D8 rule an unmigrated database still records.
	*/

	$haveCancelled = false;
	$ccr = mysql_query("SHOW COLUMNS FROM calls LIKE 'cancelled_at'");
	if ($ccr !== false && mysql_num_rows($ccr) > 0)
		$haveCancelled = true;

	$havePay = goat_ccm_have_cancel_pay();

	$cDetails = $db->selectFirst('id, bookingID, call_locked' . ($haveCancelled ? ', cancelled_at' : ''),
		'calls', 'id=' . intval($callID));

	if (!$cDetails)
	{
		send_status(404, 'Not Found');
		die('{"error":"Call not found"}');
	}

	if (intval($cDetails->call_locked) === 1)
	{
		send_status(409, 'Conflict');
		die('{"error":"Call is locked; unlock it in SmartStaff before editing times"}');
	}

	/* ---- parse body ---- */

	$raw     = file_get_contents('php://input');
	$payload = json_decode($raw);

	if (!isset($payload->rows) || !is_array($payload->rows))
	{
		send_status(400, 'Bad Request');
		die('{"error":"Body must be {\"rows\":[...]}"}');
	}

	/* ---- process rows ---- */

	$results = array();

	$paidSaved   = array();   /* userID => true: paid-cancellation rows written OK */
	$paidRefused = false;     /* any paid-cancellation row failed in this request */

	foreach ($payload->rows as $row)
	{

		$userID = has_key($row, 'user_id') ? intval($row->user_id) : 0;

		if ($userID <= 0)
		{
			$results[] = array('user_id' => $userID, 'ok' => false, 'error' => 'Missing user_id');
			continue;
		}

		/* crew member must already be booked on this call */

		$existing = $db->selectFirst('crewmapID, status', 'call_crew_map',
			'callID=' . intval($callID) . ' AND userID=' . intval($userID));

		if (!$existing)
		{
			$results[] = array('user_id' => $userID, 'ok' => false, 'error' => 'Not booked on call');
			continue;
		}

		/* STOOD DOWN -> NO TIMES, UNLESS PAID. See the header. The paid test
		/* is the shared SQL fragment, asked of this one row; a lookup error
		/* refuses the row (fails closed). */

		$isPaidCancel = false;

		if (intval($existing->status) === 9)
		{
			$paidRow = false;
			$paidErr = '';

			if ($havePay)
			{
				/* the EDITABLE test: paid, and the call not settled under the
				/* old rule (cancel_times_confirmed_at = cancelled_at) */
				$paidRow = $db->selectFirst('ccm.crewmapID',
					'call_crew_map ccm INNER JOIN calls c ON c.id = ccm.callID',
					'ccm.callID=' . intval($callID) . ' AND ccm.userID=' . intval($userID) .
					' AND ' . goat_ccm_cancel_editable_sql('ccm', 'c'));
				$paidErr = mysql_error();
			}

			if ($paidErr !== '')
			{
				$results[] = array('user_id' => $userID, 'ok' => false,
					'error' => 'Cancellation check failed: ' . $paidErr);
				continue;
			}

			if (!$paidRow)
			{
				$results[] = array('user_id' => $userID, 'ok' => false, 'code' => 'cancelled',
					'error' => 'Stood down from a cancelled call and not paid for it -- no times');
				continue;
			}

			$isPaidCancel = true;
		}

		/* UNANSWERED PROMOTION -> NO TIMES. A backup promoted to Confirmed (5)
		/* by ops has a call_promo_ack row with acked_at NULL until they accept in
		/* Crew Hub or ops dismiss the flag (dismiss-promo-ack.php, acked_src
		/* 'ops'). Until then nobody knows they are working, and a times write
		/* here is what gets them paid -- the Enter Times fill-down arrow copied
		/* a whole column onto someone who never turned up. Refused per row, so
		/* the rest of the save still lands.
		/*
		/* Guarded to status 5, the same as get-booking.php: a stale row on
		/* someone since declined is not a pending promotion. FAILS CLOSED: a
		/* lookup that errors refuses the row rather than letting it through. */

		if (intval($existing->status) === 5)
		{
			$promo    = $db->selectFirst('id', 'call_promo_ack',
				'callID=' . intval($callID) . ' AND userID=' . intval($userID) . ' AND acked_at IS NULL');
			$promoErr = mysql_error();

			if ($promoErr !== '')
			{
				$results[] = array('user_id' => $userID, 'ok' => false,
					'error' => 'Promotion check failed: ' . $promoErr);
				continue;
			}

			if ($promo)
			{
				$results[] = array('user_id' => $userID, 'ok' => false, 'code' => 'promo_pending',
					'error' => 'Promoted from backup and has not accepted -- no times until they accept in Crew Hub or ops dismiss the promotion flag');
				continue;
			}
		}

		/* build the write set from ONLY the keys this row actually sends */

		$dataArray = array();

		if (has_key($row, 'on'))          $dataArray['on']          = $db->sc($row->on);
		if (has_key($row, 'break'))       $dataArray['break']       = $db->sc($row->break);
		if (has_key($row, 'break_night')) $dataArray['break_night'] = $db->sc($row->break_night);
		if (has_key($row, 'off'))         $dataArray['off']         = $db->sc($row->off);

		/* paygrade -> the four derived rates, exactly as the native savetimes path.
		   Only written when callpaygradeID is supplied AND resolves to a paygrade row,
		   so a partial payload never zeroes an existing rate. */

		if (has_key($row, 'callpaygradeID'))
		{
			$callpaygradeID = intval($row->callpaygradeID);
			$paygradeInfo   = $db->selectFirst('*', 'paygrades', 'id=' . intval($callpaygradeID));

			if (!$paygradeInfo)
			{
				$results[] = array('user_id' => $userID, 'ok' => false,
					'error' => 'Unknown callpaygradeID ' . $callpaygradeID);
				continue;
			}

			$dataArray['callpaygradeID']      = $db->sc($callpaygradeID);
			$dataArray['callrate']            = $db->sc($paygradeInfo->rate);
			$dataArray['callrate_night']      = $db->sc($paygradeInfo->night_rate);
			$dataArray['callchargeout']       = $db->sc($paygradeInfo->co_rate);
			$dataArray['callchargeout_night'] = $db->sc($paygradeInfo->night_co_rate);
		}

		/* late: '1'/'0' char convention (matches add-call.php late=1 and
		   user-times.php user_entered_times='1'). */

		if (has_key($row, 'late'))
		{
			$lateVal = $row->late;
			$lateStr = ($lateVal === true || $lateVal === 1 || $lateVal === '1') ? '1' : '0';
			$dataArray['late'] = $db->sc($lateStr);
		}

		/* note: empty string clears it; absent leaves it untouched. */

		if (has_key($row, 'note'))
		{
			$dataArray['goat_note'] = $db->sc($row->note);
		}

		if (count($dataArray) === 0)
		{
			$results[] = array('user_id' => $userID, 'ok' => true, 'affected_rows' => 0,
				'note' => 'No fields to write');
			continue;
		}

		$db->update('call_crew_map', $dataArray,
			'callID=' . $db->sc($callID) . ' AND userID=' . $db->sc($userID));

		/* gate on mysql_error(), not affected_rows -- a no-op save reports 0 changed
		   rows even though the row exists. Capture affected_rows immediately. */

		$err = mysql_error();
		$aff = mysql_affected_rows();

		if ($err !== '')
		{
			$results[] = array('user_id' => $userID, 'ok' => false, 'error' => $err);

			if ($isPaidCancel)
				$paidRefused = true;
		}
		else
		{
			$results[] = array('user_id' => $userID, 'ok' => true, 'affected_rows' => $aff);

			if ($isPaidCancel)
				$paidSaved[$userID] = true;
		}

	}

	/*
	/* ---- DONE-TICK for a paid cancellation ----
	/*
	/* Set only when EVERY paid-cancellation row on the call was in this save
	/* and landed: a partial POST (one EIN from an import, a single-row fix)
	/* must not clear the call off the ops lane while other rows are still
	/* unchecked. THE GOAT's Enter Times sends all of them at once.
	/*
	/* Paid rows that failed for any reason other than the write itself (bad
	/* paygrade, no fields) are simply not in $paidSaved, so they hold the tick
	/* back too. The tick is written once, and not moved by a later re-save.
	*/

	$ticked = false;

	if ($havePay && $haveCancelled && isset($cDetails->cancelled_at) &&
	    $cDetails->cancelled_at !== null && intval($cDetails->cancelled_at) > 0 &&
	    !$paidRefused && count($paidSaved) > 0)
	{
		$allRes = mysql_query('SELECT ccm.userID AS userID
			FROM call_crew_map ccm INNER JOIN calls c ON c.id = ccm.callID
			WHERE ccm.callID=' . intval($callID) .
			' AND ' . goat_ccm_cancel_editable_sql('ccm', 'c'));

		$covered = ($allRes !== false);

		if ($covered)
		{
			while ($ar = mysql_fetch_object($allRes))
			{
				if (!isset($paidSaved[intval($ar->userID)]))
				{
					$covered = false;
					break;
				}
			}
		}

		if ($covered)
		{
			$db->update('calls', array('cancel_times_confirmed_at' => time()),
				'id=' . intval($callID) . ' AND cancel_times_confirmed_at IS NULL');

			if (mysql_error() === '')
				$ticked = true;
		}
	}

	echo json_encode(array(
		'ok'         => true,
		'call_id'    => intval($callID),
		'booking_id' => intval($cDetails->bookingID),
		'rows'       => $results,
		/* true when this save set calls.cancel_times_confirmed_at. The
		/* booking re-read is the authority; this is a hint for the UI. */
		'cancel_times_confirmed' => $ticked
	));

?>
