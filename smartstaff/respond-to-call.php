<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');
	include('call-graph.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SELF endpoint — the acting crew member confirms or declines an OFFERED
	/* call. Mirrors the crew dashboard handler (dash.php, action=callstatus):
	/*
	/*   - only rows currently status <= 1 (offered: Unconfirmed / SMS Sent)
	/*     can change, so an already-confirmed/declined call is left untouched
	/*   - status 5 = Confirmed; also added to the calendar via addToCalendar,
	/*     which is what then surfaces it in my-shifts.php
	/*   - status 6 = Declined ("Can't attend") — drops out of the offers list
	/*   - status 7 = Backup — a Confirm (5) on a call that is already full is
	/*     written as 7 instead (no calendar row), mirroring sms-cron.php so the
	/*     PWA and SMS paths agree. See the capacity check below.
	/*
	/* A call that has already STARTED can no longer be confirmed — see the time
	/* guard below. Decline is never time-gated.
	/*
	/* Reuses $db and $sss from global.php so the confirm side-effect is byte
	/* identical to SmartStaff's own dashboard.
	*/

	$userID = (int) goat_acting_user_id();

	if ($userID <= 0)
	{
		http_response_code(403);
		die('{"error":"not authorised"}');
	}

	$callID     = isset($_POST['callID']) ? (int) $_POST['callID'] : 0;
	$callStatus = isset($_POST['status']) ? (int) $_POST['status'] : 0;

	if ($callID <= 0)
	{
		http_response_code(400);
		die('{"error":"callID required"}');
	}

	/* only confirm (5) or decline (6) are valid targets */

	if ($callStatus != 5 && $callStatus != 6)
	{
		http_response_code(400);
		die('{"error":"status must be 5 (confirm) or 6 (decline)"}');
	}

	/*
	/* Seed-call guard.
	/*
	/* Every row this endpoint moves is normally guarded by status <= 1, so an
	/* already-answered row is a no-op. The one exception is $breakCommitment —
	/* confirmed UPSTREAM rows whose commitment a decline has broken.
	/*
	/* The seed call is never in $breakCommitment, so without this guard a
	/* decline seeded on an already-confirmed row would leave that row at 5
	/* while un-confirming everything upstream of it — declined for the call
	/* they wanted and still confirmed for the one they dropped.
	/*
	/* Refuse rather than allow. Nothing in the system can currently send a
	/* response for a non-offered row (my-call-offers.php and dash.php both
	/* filter status <= 1), so this changes no reachable behaviour — it just
	/* makes the unreachable case fail loudly instead of incoherently. Letting
	/* a crew member un-confirm an accepted shift is a policy decision, not a
	/* consequence of a consistency fix; if that feature is wanted later, this
	/* is where it goes.
	/*
	/* An ABSENT row is fine and falls through — the apply loop is a harmless
	/* no-op and the response reports changed:false.
	*/

	$seedRow = $db->selectFirst(
		'status',
		'call_crew_map',
		'userID=' . $db->sc($userID) . ' AND callID=' . $db->sc($callID)
	);

	if ($seedRow && (int) $seedRow->status > 1)
	{
		$cur = (int) $seedRow->status;

		/*
		/* Idempotent: they are already in the state they asked for. A
		/* double-tapped Decline, or a stale offer card in the PWA, must not
		/* read as a failure — the requested outcome already holds.
		/*
		/* Backup (7) satisfies a Confirm (5): they accepted, the call was
		/* full, and re-tapping Accept must not error. The response reports
		/* result_status 7 and backup:true so the client shows the real state.
		/*
		/* Shape matches the success response from the apply loop, with
		/* changed:false — clients can treat both identically.
		*/

		if ($cur === $callStatus || ($callStatus == 5 && $cur == 7))
		{
			echo json_encode(array(
				'ok'            => true,
				'callID'        => $callID,
				'status'        => $callStatus,
				'result_status' => $cur,
				'backup'        => ($cur == 7) ? true : false,
				'changed'       => false,
				'changed_calls' => array(),
				'changed_names' => array(),
				'unconfirmed'   => array(),
				'package'       => array($callID),
				'linked'        => false
			));
			die();
		}

		/* genuine conflict — e.g. declining a call they are confirmed on */

		http_response_code(409);
		echo json_encode(array(
			'error'          => 'This call has already been answered and cannot be changed here.',
			'callID'         => $callID,
			'current_status' => $cur
		));
		die();
	}

	/*
	/* Resolve the affected set. On a decline this is now goat_decline_scope —
	/* the same helper my-call-offers.php uses to tell the crew member what
	/* they are about to lose, so the warning and the action cannot diverge.
	*/

	$package = goat_user_package($userID, $callID);
	$callIDs = $package;

	$heldStatus = array();
	$hres = mysql_query("SELECT callID, status FROM call_crew_map
	                     WHERE userID = " . $userID);

	if ($hres !== false)
	{
		while ($hrow = mysql_fetch_object($hres))
		{
			$heldStatus[(int) $hrow->callID] = (int) $hrow->status;
		}
	}

	$breakCommitment = array();

	if ($callStatus == 6)
	{
		$scope   = goat_decline_scope($userID, $callID);
		$callIDs = array_keys($scope);

		foreach ($scope as $cid => $st)
		{
			/* confirmed/backup rows outside the offered package are the ones
			/* whose commitment is being broken — they bypass the status guard
			/* and lose their calendar entry */
			if ($st > 1 && !in_array($cid, $package))
			{
				$breakCommitment[$cid] = true;
			}
		}
	}

	if (!count($callIDs))
	{
		$callIDs[] = $callID;
	}

	/* Downstream-most first (DESIGN §4.3): on a partial failure under MyISAM,
	/* a crew member confirmed downstream but not upstream is recoverable; the
	/* reverse breaks the invariant. Sort by descending downstream depth. */

	$depthOf = array();

	foreach ($callIDs as $cid)
	{
		$depthOf[$cid] = count(goat_calls_downstream($cid));
	}

	usort($callIDs, function($a, $b) use ($depthOf) {
		return $depthOf[$a] - $depthOf[$b];
	});

	/*
	/* Time guard — a call that has already STARTED can no longer be CONFIRMED.
	/* Decline (6) is always allowed. Linked calls answer as one unit, so if ANY
	/* call in the resolved set has started, the whole accept is refused.
	/*
	/* The start instant is the call's Melbourne wall-clock (start_date +
	/* start_time) resolved in Australia/Melbourne, so the check is correct
	/* regardless of the server's own timezone. Mirrors the start computation in
	/* my-call-offers.php.
	*/

	if ($callStatus == 5)
	{
		$melTz = new DateTimeZone('Australia/Melbourne');
		$nowTs = time();

		foreach ($callIDs as $cid)
		{
			$cRow = $db->selectFirst('start_date, start_time', 'calls', 'id=' . $db->sc($cid));

			if (!$cRow)
			{
				continue;
			}

			$cDate   = date('Y-m-d', (int) $cRow->start_date);
			$startTs = false;

			try {
				$dt = new DateTime($cDate . ' ' . $cRow->start_time, $melTz);
				$startTs = $dt->getTimestamp();
			} catch (Exception $e) {
				$startTs = false;
			}

			if ($startTs !== false && $startTs <= $nowTs)
			{
				echo json_encode(array(
					'ok'      => false,
					'expired' => true,
					'error'   => 'This shift has already started, so it can no longer be accepted.'
				));
				exit;
			}
		}
	}

	/*
	/* Capacity. A package confirm BYPASSES the full/backup check entirely
	/* (DESIGN §3.3): those crew were promised the slot upstream and must never
	/* be written as Backup because the receiving call was overfilled by direct
	/* booking.
	/*
	/* An UNFED single call keeps the old sms-cron.php behaviour exactly — this
	/* is the no-regression path for every call that has no feeds.
	*/

	$effectiveStatus = $callStatus;   /* 5 or 6 — may become 7 below */

	if ($callStatus == 5 && count($callIDs) === 1)
	{
		$only     = $callIDs[0];
		$callRow  = $db->selectFirst('required', 'calls', 'id=' . $db->sc($only));
		$required = $callRow ? (int) $callRow->required : 0;

		$stat = $db->selectFirst(
			'COUNT(call_crew_map.status) as cnt',
			'call_crew_map',
			'status=5 AND callID=' . $db->sc($only) . ' GROUP BY status'
		);
		$confirmed = $stat ? (int) $stat->cnt : 0;

		/* >= required matches sms-cron.php exactly (including the required=0
		/* edge, where a call needing no crew reads as full). */

		if ($confirmed >= $required)
		{
			$effectiveStatus = 7;   /* Backup — accepted, but the call is full */
		}
	}

	/*
	/* Apply. Two guards, deliberately different:
	/*
	/*   - normal rows: status <= 1, so an already-answered row is a no-op
	/*   - $breakCommitment rows: NO status guard, because the whole point is
	/*     to un-confirm an accepted shift whose commitment has been broken.
	/*     Each one also loses its calendar entry.
	/*
	/* Idempotent by construction (MyISAM has no transactions — DESIGN §4.3):
	/* re-running produces the same end state.
	*/

	$totalChanged  = 0;
	$changedCalls  = array();
	$unconfirmed   = array();   /* previously-confirmed rows we took back */

	foreach ($callIDs as $cid)
	{
		$isBreak = isset($breakCommitment[$cid]);

		$where = $isBreak
			? 'userID=' . $db->sc($userID) . ' AND callID=' . $db->sc($cid)
			: 'status <= 1 AND userID=' . $db->sc($userID) . ' AND callID=' . $db->sc($cid);

		$db->update(
			'call_crew_map',
			array('status' => $db->sc($effectiveStatus)),
			$where
		);

		if (mysql_error())
		{
			http_response_code(500);
			die('{"error":"call status update failed: ' . addslashes(mysql_error()) . '"}');
		}

		$changed = mysql_affected_rows();

		if ($changed > 0 || $isBreak)
		{
			$totalChanged  += $changed;
			$changedCalls[] = $cid;

			if ($effectiveStatus == 5)
			{
				$sss->addToCalendar($cid, $userID);
			}

			if ($isBreak)
			{
				/* the accepted shift is being taken back — drop its calendar
				/* row too, or the crew member keeps a phantom entry */

				$sss->removeFromCalendar($cid, $userID);
				$unconfirmed[] = $cid;
			}
		}
	}

	/* names for the response, so the Hub and ops can say what actually moved */

	$changedNames = array();

	if (count($changedCalls))
	{
		$nres = mysql_query("SELECT id, call_name FROM calls
		                     WHERE id IN (" . implode(',', $changedCalls) . ")");

		if ($nres !== false)
		{
			while ($nrow = mysql_fetch_object($nres))
			{
				$changedNames[(int) $nrow->id] = $nrow->call_name;
			}
		}
	}

	echo json_encode(array(
		'ok'             => true,
		'callID'         => $callID,
		'status'         => $callStatus,                             /* what the crew requested (5/6) */
		'result_status'  => $effectiveStatus,                        /* what was written (5/6/7) */
		'backup'         => ($effectiveStatus == 7) ? true : false,
		'changed'        => ($totalChanged > 0 || count($unconfirmed) > 0) ? true : false,
		'changed_calls'  => $changedCalls,
		'changed_names'  => $changedNames,
		'unconfirmed'    => $unconfirmed,                            /* accepted shifts taken back */
		'package'        => $package,
		'linked'         => count($callIDs) > 1 ? true : false       /* preserved for compatibility */
	));

?>
