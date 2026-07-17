<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* ADMIN ONLY (same gate as create-booking.php / update-booking.php). */

	if (goat_user_cohort() !== 'admin')
	{
		send_status(403, 'Forbidden');
		die('{"error":"Admin only"}');
	}

	/*
	/* Edits a call's detail fields, replicating add-call.php (action=edit):
	/*
	/*   1. $db->update('calls', dataArray, 'id=N')
	/*   2. select all call_crew_map rows for the call
	/*   3. for each assigned crew member: $sss->addToCalendar($callID, $userID)
	/*      -- re-syncs that crew member's calendar entry to the call's (possibly
	/*         changed) date/time/length. This is REQUIRED: without it, booked
	/*         crew keep stale calendar times after a time edit. We reuse
	/*         SmartStaff's own $sss->addToCalendar (global, from global.php) so the
	/*         calendar behaviour is byte-identical to a SmartStaff edit; we do not
	/*         reimplement calendar math.
	/*
	/* We DELIBERATELY do not write call_locked (or times_filled / edit_times /
	/* pubhol flags). add-call.php only runs the accounting cascade
	/* ($accounting->generateCallData) on a call_locked 0->1 transition; by never
	/* touching call_locked we can never trigger it, and a partial $db->update
	/* leaves those columns untouched.
	/*
	/* Editable field map:
	/*   calls : call_name, start_date (unix), start_time, est_length (<- 'length'),
	/*           required, notes
	/*
	/* Call id via ?id=N. Body JSON {call:{...fields...}}.
	/*
	/* PHP 5.x — no null-coalescing (??), no short array syntax.
	*/

	/* ---- helpers (identical to create-booking.php) ---- */

	function P($obj, $key, $default = '')
	{
		return (isset($obj->$key) && $obj->$key !== null) ? $obj->$key : $default;
	}

	function to_unix($v)
	{
		if ($v === '' || $v === null) return 0;
		if (is_numeric($v))           return intval($v);
		$t = strtotime($v);                 /* Australia/Melbourne tz, set in global.php */
		return $t ? $t : 0;
	}

	function send_status($code, $msg)
	{
		$proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($proto . ' ' . $code . ' ' . $msg);
	}

	/* ---- target call id ---- */

	$callID = isset($_GET['id']) ? intval($_GET['id']) : 0;

	if ($callID <= 0)
	{
		send_status(400, 'Bad Request');
		die('{"error":"Missing or invalid ?id"}');
	}

	/* Widened to capture the "was" timing BEFORE the UPDATE — used to detect a
	/* timing change and to store each affected crew member's last-agreed time in
	/* call_change_ack (the re-confirm flag). */
	$existingCall = $db->selectFirst(
		'id, bookingID, start_date, start_time, est_length', 'calls', 'id=' . $callID);

	if (!$existingCall)
	{
		send_status(404, 'Not Found');
		die('{"error":"Call not found"}');
	}

	$bookingID = (int) $existingCall->bookingID;

	$old_start_date = (int) $existingCall->start_date;
	$old_start_time = $existingCall->start_time;
	$old_est_length = $existingCall->est_length;

	/* ---- parse body ---- */

	$raw     = file_get_contents('php://input');
	$payload = json_decode($raw);

	if (!$payload || !isset($payload->call))
	{
		send_status(400, 'Bad Request');
		die('{"error":"Invalid or missing JSON body (expected {call})"}');
	}

	$c = $payload->call;

	/* ---- validate ---- */

	$errors = array();

	if (trim(P($c, 'call_name', '')) === '')    $errors[] = 'call.call_name is required';
	if (to_unix(P($c, 'start_date', '')) <= 0)  $errors[] = 'call.start_date is required/invalid';

	if (count($errors))
	{
		send_status(422, 'Unprocessable Entity');
		echo json_encode(array('error' => 'validation failed', 'errors' => $errors));
		die();
	}

	/* ---- update call (mirrors add-call.php action=edit, editable subset) ---- */

	$callData = array(
		'call_name'   => $db->sc(P($c, 'call_name', '')),
		'start_date'  => to_unix(P($c, 'start_date', 0)),
		'start_time'  => $db->sc(trim(P($c, 'start_time', '')) !== '' ? $c->start_time : '00:00:00'),
		'est_length'  => $db->sc(P($c, 'length', 0)),
		'required'    => intval(P($c, 'required', 0)),
		'notes'       => $db->sc(P($c, 'notes', '')),
	);

	/*
	/* Timing change? Compare the NEW values (the same ones $callData writes:
	/* to_unix(start_date), the start_time string, length) against the "was"
	/* values captured above. Only a timing change flags crew for re-confirm; a
	/* name/required/notes-only edit returns timing_changed:false and touches no
	/* call_change_ack rows.
	/*
	/* est_length is compared as (double) so 4 vs 4.00 is not a false positive.
	/* start_time is normalised through date('H:i:s', strtotime()) on both sides
	/* so "6:00:00" vs "06:00:00" doesn't read as a change either.
	*/
	$new_start_date = to_unix(P($c, 'start_date', 0));
	$new_start_time = (trim(P($c, 'start_time', '')) !== '' ? $c->start_time : '00:00:00');
	$new_est_length = P($c, 'length', 0);

	$old_time_norm = date('H:i:s', strtotime('1970-01-01 ' . $old_start_time));
	$new_time_norm = date('H:i:s', strtotime('1970-01-01 ' . $new_start_time));

	$timing_changed = (
		   $old_start_date !== $new_start_date
		|| (string) $old_time_norm !== (string) $new_time_norm
		|| (double) $old_est_length !== (double) $new_est_length
	);

	$db->update('calls', $callData, 'id=' . $callID);

	$err          = mysql_error();
	$updAffected  = mysql_affected_rows();   /* capture NOW; the calendar loop below runs its own queries */

	if ($err !== '')
	{
		send_status(500, 'Internal Server Error');
		echo json_encode(array('error' => 'call update failed', 'detail' => $err));
		die();
	}

	/* ---- re-sync calendars for assigned crew (mirrors add-call.php edit) ---- */

	$synced   = 0;
	$callCrew = $db->select('*', 'call_crew_map', 'callID = ' . $callID);

	/*
	/* Re-confirm fan-out lists (only populated on a timing change). We piggyback
	/* the existing per-crew calendar loop rather than re-querying the roster.
	/*   status 5 (Confirmed) -> flag + 'reconfirm' push (Accept / Decline)
	/*   status 7 (Backup)    -> flag + 'standby'   push ("No longer available")
	/*   status 0/1 (Offered) -> 'info' push only; offer card self-updates, no flag
	/*
	/* INSERT IGNORE + the UNIQUE (callID, userID) key mean a re-edit does NOT
	/* overwrite an existing flag: anyone still flagged keeps the "was" time they
	/* last signed off on. Someone who accepted/declined had their row deleted, so
	/* the insert lands and re-flags them against the newer "was".
	*/
	$reconfirm_users = array();   /* status 5 */
	$standby_users   = array();   /* status 7 */
	$info_users      = array();   /* status 0/1 */

	if (is_array($callCrew) && count($callCrew) > 0)
	{
		foreach ($callCrew as $crew)
		{
			$sss->addToCalendar($callID, intval($crew->userID));
			$synced++;

			if ($timing_changed)
			{
				$st  = (int) $crew->status;
				$uid = (int) $crew->userID;

				if ($st === 5 || $st === 7)
				{
					mysql_query(
						'INSERT IGNORE INTO call_change_ack ' .
						'(callID, userID, prev_start_date, prev_start_time, prev_est_length, changed_at) ' .
						'VALUES (' .
							intval($callID) . ', ' . $uid . ', ' .
							intval($old_start_date) . ', ' . $db->sc($old_start_time) . ', ' .
							$db->sc($old_est_length) . ', ' . time() .
						')'
					);

					if ($st === 5) $reconfirm_users[] = $uid;
					else           $standby_users[]   = $uid;
				}
				elseif ($st === 0 || $st === 1)
				{
					$info_users[] = $uid;
				}
			}
		}
	}

	/*
	/* NEW start/end ISO for the notification line — built the same way the
	/* offer/backup endpoints resolve wall-clock (unix date + start_time, end =
	/* start + est_length hours). The push is only a nudge; the card renders the
	/* full delta from my-shifts / my-backups.
	*/
	$new_start_iso = date('Y-m-d\TH:i:s',
	                     strtotime(date('Y-m-d', $new_start_date) . ' ' . $new_start_time));
	$new_end_iso   = date('Y-m-d\TH:i:s',
	                     strtotime($new_start_iso) + (int) round(((double) $new_est_length) * 3600));

	echo json_encode(array(
		'ok'             => true,
		'call_id'        => $callID,
		'booking_id'     => $bookingID,
		'crew_synced'    => $synced,
		'affected_rows'  => $updAffected,
		'timing_changed' => $timing_changed ? true : false,
		'reconfirm_users'=> $reconfirm_users,
		'standby_users'  => $standby_users,
		'info_users'     => $info_users,
		'new_start'      => $new_start_iso,
		'new_end'        => $new_end_iso,
	));

?>
