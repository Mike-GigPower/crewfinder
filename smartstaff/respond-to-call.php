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
				'statuses'      => array(),
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

	if (!function_exists('goat_rtc_root_is_full'))
	{
		/*
		/* Is this call at or over its number, counting CONFIRMED rows only?
		/*
		/* Body copied verbatim from the pre-peel check so the predicate is
		/* byte-identical to what shipped in August, including `>= required`
		/* and the required=0 edge where a call needing no crew reads as full.
		/* It matches sms-cron.php for the same reason. Do not "tidy" it.
		/*
		/* Memoised per request because the peel can offer the same call as a
		/* root on more than one pass, and this is two queries a time.
		*/

		function goat_rtc_root_is_full($callID, &$memo)
		{
			$callID = (int) $callID;

			if (isset($memo[$callID]))
			{
				return $memo[$callID];
			}

			global $db;

			$callRow  = $db->selectFirst('required', 'calls', 'id=' . $db->sc($callID));
			$required = $callRow ? (int) $callRow->required : 0;

			$stat = $db->selectFirst(
				'COUNT(call_crew_map.status) as cnt',
				'call_crew_map',
				'status=5 AND callID=' . $db->sc($callID) . ' GROUP BY status'
			);
			$confirmed = $stat ? (int) $stat->cnt : 0;

			$memo[$callID] = ($confirmed >= $required) ? true : false;

			return $memo[$callID];
		}
	}

	/*
	/* Capacity — checked on the package ROOTS, then PEELED.
	/*
	/* A root is a call in the package that no other package member feeds. It
	/* is a call the crew member is being booked onto directly, so its
	/* `required` must be honoured.
	/*
	/* A NON-root member keeps the DESIGN §3.3 bypass: those crew were promised
	/* the slot upstream and must never be written Backup because a receiving
	/* call was overfilled by direct booking. That is why case 4 leaves a call
	/* at 4 confirmed against 3 required, and it is still correct.
	/*
	/* PEEL, replacing August's all-or-nothing. When a root IS full, the
	/* promise that justified the bypass was not kept — so that root goes to
	/* Backup, drops out, and whatever it fed becomes a root in its own right
	/* and is checked on its own merits. Repeat until every remaining root has
	/* room.
	/*
	/* This is Joe's case, 11 Sep 2026: Ben Ralph backed up on a load-in that
	/* filled, and backed up on a load out with ten places free purely because
	/* the two were linked. The feed invariant runs one way only — holding the
	/* load out never required holding the load-in (see goat_decline_scope) —
	/* so "Backup upstream, Confirmed downstream" is a legal state and the one
	/* ops wants. The reverse is what must stay impossible, and it still is: a
	/* call is only ever written Backup while it is a root, which means
	/* everything upstream of it inside the package was already written Backup
	/* on an earlier pass.
	/*
	/* CYCLE GUARD — all-or-nothing survives here, deliberately. The link_group
	/* backfill produced symmetric pairs (A->B and B->A) which feed each other,
	/* so the root set computes EMPTY. Those genuinely are answered as one unit
	/* — that is what a linked call meant — so if any member is full the whole
	/* remainder goes to Backup, exactly as before. Peeling them would silently
	/* change the meaning of every old linked call in the system.
	/*
	/* A single unfed call is its own only root and never reaches a second
	/* pass, so that path stays byte-identical to sms-cron.php.
	*/

	$statusOf = array();

	foreach ($callIDs as $cid)
	{
		$statusOf[(int) $cid] = $callStatus;   /* 5 or 6; 5s may become 7 below */
	}

	if ($callStatus == 5)
	{
		$remaining = array();

		foreach ($callIDs as $cid)
		{
			$remaining[] = (int) $cid;
		}

		$fullMemo = array();
		$guard    = 0;

		while (count($remaining) && $guard < 20)
		{
			$guard++;

			/* targets of locked edges that START inside what is left */

			$fedInside  = array();
			$downstream = goat_feed_step($remaining, 'down');

			foreach ($downstream as $d)
			{
				if (in_array((int) $d, $remaining))
				{
					$fedInside[(int) $d] = true;
				}
			}

			$roots = array();

			foreach ($remaining as $cid)
			{
				if (!isset($fedInside[$cid]))
				{
					$roots[] = $cid;
				}
			}

			/* Mutually-fed remainder — see CYCLE GUARD above. All or nothing. */

			if (!count($roots))
			{
				$anyFull = false;

				foreach ($remaining as $cid)
				{
					if (goat_rtc_root_is_full($cid, $fullMemo))
					{
						$anyFull = true;
						break;
					}
				}

				if ($anyFull)
				{
					foreach ($remaining as $cid)
					{
						$statusOf[$cid] = 7;
					}
				}

				break;
			}

			$fullRoots = array();

			foreach ($roots as $rid)
			{
				if (goat_rtc_root_is_full($rid, $fullMemo))
				{
					$fullRoots[] = $rid;
				}
			}

			/* Every root has room — everything still standing confirms. */

			if (!count($fullRoots))
			{
				break;
			}

			$next = array();

			foreach ($remaining as $cid)
			{
				if (in_array($cid, $fullRoots))
				{
					$statusOf[$cid] = 7;
				}
				else
				{
					$next[] = $cid;
				}
			}

			$remaining = $next;
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
	/* Since the peel, the status written is PER CALL ($statusOf), not one
	/* value for the package. A call written 7 gets no calendar row, as before,
	/* but that is now decided per call.
	/*
	/* Idempotent by construction (MyISAM has no transactions — DESIGN §4.3):
	/* re-running produces the same end state.
	*/

	$totalChanged  = 0;
	$changedCalls  = array();
	$unconfirmed   = array();   /* previously-confirmed rows we took back */
	$writtenStatus = array();   /* call_id => status actually written */

	foreach ($callIDs as $cid)
	{
		$cid     = (int) $cid;
		$isBreak = isset($breakCommitment[$cid]);
		$writeSt = isset($statusOf[$cid]) ? (int) $statusOf[$cid] : (int) $callStatus;

		$where = $isBreak
			? 'userID=' . $db->sc($userID) . ' AND callID=' . $db->sc($cid)
			: 'status <= 1 AND userID=' . $db->sc($userID) . ' AND callID=' . $db->sc($cid);

		$db->update(
			'call_crew_map',
			array('status' => $db->sc($writeSt)),
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

			$writtenStatus[$cid] = $writeSt;

			if ($writeSt == 5)
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

	/*
	/* result_status / backup describe the SEED call — the card the crew member
	/* tapped. Since the peel a package can carry more than one status, so a
	/* single value for "the package" no longer exists. The seed row is
	/* guaranteed status <= 1 here (the guard at the top of this file returned
	/* early otherwise), so intended == written for it.
	/*
	/* `statuses` is additive and reports what was actually WRITTEN, per call.
	/* Keys are cast to strings so json_encode always emits an object — call
	/* ids are never a 0..n-1 sequence today, but relying on that is how a
	/* client ends up parsing an array.
	/*
	/* Existing clients ignore `statuses` and keep reading result_status /
	/* backup, so nothing in Crew Hub needs to change to deploy this.
	*/

	$seedStatus = isset($statusOf[$callID]) ? (int) $statusOf[$callID] : (int) $callStatus;

	$statusMap = array();

	foreach ($writtenStatus as $k => $v)
	{
		$statusMap[(string) $k] = (int) $v;
	}

	echo json_encode(array(
		'ok'             => true,
		'callID'         => $callID,
		'status'         => $callStatus,                             /* what the crew requested (5/6) */
		'result_status'  => $seedStatus,                             /* what was written for the SEED call */
		'backup'         => ($seedStatus == 7) ? true : false,
		'statuses'       => $statusMap,                              /* per call, what was written */
		'changed'        => ($totalChanged > 0 || count($unconfirmed) > 0) ? true : false,
		'changed_calls'  => $changedCalls,
		'changed_names'  => $changedNames,
		'unconfirmed'    => $unconfirmed,                            /* accepted shifts taken back */
		'package'        => $package,
		'linked'         => count($callIDs) > 1 ? true : false       /* preserved for compatibility */
	));

?>
