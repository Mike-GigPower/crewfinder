<?php

	/*
	/* global file */

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

	$cDetails = $db->selectFirst('id, bookingID, call_locked', 'calls', 'id=' . intval($callID));

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

	foreach ($payload->rows as $row)
	{

		$userID = has_key($row, 'user_id') ? intval($row->user_id) : 0;

		if ($userID <= 0)
		{
			$results[] = array('user_id' => $userID, 'ok' => false, 'error' => 'Missing user_id');
			continue;
		}

		/* crew member must already be booked on this call */

		$existing = $db->selectFirst('crewmapID', 'call_crew_map',
			'callID=' . intval($callID) . ' AND userID=' . intval($userID));

		if (!$existing)
		{
			$results[] = array('user_id' => $userID, 'ok' => false, 'error' => 'Not booked on call');
			continue;
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
		}
		else
		{
			$results[] = array('user_id' => $userID, 'ok' => true, 'affected_rows' => $aff);
		}

	}

	echo json_encode(array(
		'ok'         => true,
		'call_id'    => intval($callID),
		'booking_id' => intval($cDetails->bookingID),
		'rows'       => $results
	));

?>
