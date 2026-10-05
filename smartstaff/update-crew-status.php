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
	/* ADMIN ONLY (same gate as update-call.php / update-booking.php).
	/*
	/* Crew Boss status changes (e.g. no-show from the floor) are deferred to a
	/* later phase; for now every status edit from THE GOAT is admin-gated, which
	/* is at least as strict as SmartStaff's native callsheet (confirm/decline are
	/* checkPermissions(1); no-show is callboss-or-admin). */

	if (goat_user_cohort() !== 'admin')
	{
		send_status(403, 'Forbidden');
		die('{"error":"Admin only"}');
	}

	/*
	/* Sets a single crew member's status on a call, replicating add-call.php's
	/* native confirm / decline / no-show handlers:
	/*
	/*   status 5 (confirmed)   : UPDATE status=5  + $sss->addToCalendar(callID,userID)
	/*   status 6 (declined)    : UPDATE status=6  (NO calendar change)
	/*   status 7 (backup)      : UPDATE status=7  (NO calendar change; promoting
	/*                            a backup means setting status 5, which adds it)
	/*   status 8 (no-show)     : UPDATE status=8  (NO calendar change)
	/*   status 1 (pending)     : UPDATE status=1  (NO calendar change; NO SMS sent)
	/*   status 0 (unconfirmed) : UPDATE status=0  (NO calendar change)
	/*
	/* Calendar parity with SmartStaff:
	/*   - confirm ADDS a calendars row, via SmartStaff's own $sss->addToCalendar
	/*     (global, from global.php), so behaviour is byte-identical; we do NOT
	/*     reimplement calendar math.
	/*   - decline / no-show DELIBERATELY leave any existing calendars row in place.
	/*     This matches native add-call.php (only the separate 'remove' action ever
	/*     deletes calendar rows) AND is the desired behaviour: a declined entry at
	/*     a given time tells the operator not to re-offer that resource a clashing
	/*     call.
	/*
	/* No SMS is ever sent. Native status 1 (pending) is only set as a side-effect
	/* of sendsms; here we set it directly with no message.
	/*
	/* Call id via ?id=N. Body JSON {userID:N, status:M}. One crew member per call.
	/*
	/* PHP 5.x -- no null-coalescing (??), no short array syntax. */

	/* ---- helpers (identical to update-call.php) ---- */

	function P($obj, $key, $default = '')
	{
		return (isset($obj->$key) && $obj->$key !== null) ? $obj->$key : $default;
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

	$existingCall = $db->selectFirst('id, bookingID', 'calls', 'id=' . $callID);

	if (!$existingCall)
	{
		send_status(404, 'Not Found');
		die('{"error":"Call not found"}');
	}

	$bookingID = (int) $existingCall->bookingID;

	/* ---- parse body ---- */

	$raw     = file_get_contents('php://input');
	$payload = json_decode($raw);

	if (!$payload)
	{
		send_status(400, 'Bad Request');
		die('{"error":"Invalid or missing JSON body (expected {userID, status})"}');
	}

	/*
	/* mysql_* and JSON both hand us strings sometimes ("5" vs 5); intval folds
	/* them together so the status === 5 calendar test below is reliable. */

	$userID = intval(P($payload, 'userID', 0));
	$status = intval(P($payload, 'status', -1));

	/* ---- validate ---- */

	$errors = array();

	if ($userID <= 0)
		$errors[] = 'userID is required';

	$allowedStatuses = array(0, 1, 5, 6, 7, 8);   /* 0 unconfirmed, 1 pending, 5 confirmed, 6 declined, 7 backup, 8 no-show */

	if (!in_array($status, $allowedStatuses))
		$errors[] = 'status must be one of 0, 1, 5, 6, 7, 8';

	if (count($errors))
	{
		send_status(422, 'Unprocessable Entity');
		echo json_encode(array('error' => 'validation failed', 'errors' => $errors));
		die();
	}

	/* ---- the crew member must already be assigned to this call ----
	/*
	/* We UPDATE call_crew_map; we never INSERT. If the row is absent the UPDATE is
	/* a silent no-op, so confirm it exists up front. This also lets us gate
	/* success on mysql_error() rather than affected_rows -- a re-save of the same
	/* status changes 0 rows but is NOT a failure. */

	$existingRow = $db->selectFirst('userID, status', 'call_crew_map', 'callID=' . $callID . ' AND userID=' . $userID);

	if (!$existingRow)
	{
		send_status(404, 'Not Found');
		die('{"error":"Crew member is not assigned to this call"}');
	}

	$prevStatus = (int) $existingRow->status;   /* status BEFORE this update — lets us flag a 7->5 promotion */

	$isPromotion = ($prevStatus === 7 && $status === 5);
	$now         = time();   /* once — every row in a promotion shares one promoted_at, the group key */

	/* ---- link-safe Promote: a 7 -> 5 move promotes downstream too ----
	/*
	/* DESIGN-backup-callout §3.1. Being Confirmed on X commits the crew member
	/* to everything X feeds over a locked edge. Promoting X alone would leave
	/* them Confirmed upstream of a call they are only Backup on, which is the
	/* one state the feed rules forbid.
	/*
	/* So every downstream call must already be held (5 or 7). If any is not —
	/* no row, declined, no-show, cancelled — refuse and write nothing (D10):
	/* ops fixes those first. Rows at 7 are promoted with X and flagged with
	/* the same promoted_at, so the crew member answers them as one promotion.
	/* Rows already at 5 are left alone.
	/*
	/* Upstream rows are never touched: holding the load out never required
	/* holding the load-in. Every other transition skips this block. */

	$promotedCalls = array();

	if ($isPromotion)
	{
		include_once('call-graph.php');

		$down = goat_calls_downstream($callID);   /* locked edges only */

		if (count($down))
		{
			$downStatus = array();

			$dres = mysql_query("SELECT callID, status FROM call_crew_map
			                     WHERE userID = " . intval($userID) . "
			                       AND callID IN (" . implode(',', array_map('intval', $down)) . ")");

			if ($dres !== false)
			{
				while ($drow = mysql_fetch_object($dres))
				{
					$downStatus[(int) $drow->callID] = (int) $drow->status;
				}
			}

			$notHeld = array();

			foreach ($down as $d)
			{
				$d = (int) $d;

				if (!isset($downStatus[$d]) || ($downStatus[$d] !== 5 && $downStatus[$d] !== 7))
				{
					$notHeld[$d] = isset($downStatus[$d]) ? $downStatus[$d] : null;
				}
			}

			if (count($notHeld))
			{
				$names = array();
				$nres  = mysql_query("SELECT id, call_name FROM calls
				                      WHERE id IN (" . implode(',', array_keys($notHeld)) . ")");

				if ($nres !== false)
				{
					while ($nrow = mysql_fetch_object($nres))
					{
						$names[(int) $nrow->id] = $nrow->call_name;
					}
				}

				$calls = array();

				foreach ($notHeld as $d => $st)
				{
					$calls[] = array(
						'call_id'   => $d,
						'call_name' => isset($names[$d]) ? $names[$d] : '',
						'status'    => $st   /* null = no row on that call */
					);
				}

				send_status(409, 'Conflict');
				echo json_encode(array('ok' => false, 'error' => 'downstream_not_held', 'calls' => $calls));
				die();
			}

			/* Downstream-most first, as respond-to-call.php: under MyISAM a
			/* partial failure that leaves someone Confirmed downstream but not
			/* upstream is recoverable; the reverse breaks the invariant. */

			$toPromote = array();
			$depthOf   = array();

			foreach ($down as $d)
			{
				$d = (int) $d;

				if ($downStatus[$d] === 7)
				{
					$toPromote[] = $d;
					$depthOf[$d]   = count(goat_calls_downstream($d));
				}
			}

			usort($toPromote, function($a, $b) use ($depthOf) {
				return $depthOf[$a] - $depthOf[$b];
			});

			foreach ($toPromote as $d)
			{
				$db->update('call_crew_map', array('status' => $db->sc(5)),
				            'status = 7 AND callID=' . $d . ' AND userID=' . $userID);

				$err = mysql_error();

				if ($err !== '')
				{
					send_status(500, 'Internal Server Error');
					echo json_encode(array('error' => 'downstream promotion failed', 'detail' => $err, 'call_id' => $d));
					die();
				}

				$sss->addToCalendar($d, $userID);

				/* DELETE-then-INSERT, for the reason given at the promo-ack block below */

				mysql_query('DELETE FROM call_promo_ack WHERE callID=' . intval($d) .
				            ' AND userID=' . intval($userID));

				$db->insert('call_promo_ack', array(
					'callID'      => $db->sc($d),
					'userID'      => $db->sc($userID),
					'promoted_at' => $db->sc($now)
				));

				$promotedCalls[] = $d;
			}
		}
	}

	/* ---- update status ---- */

	$updFields = array('status' => $db->sc($status));

	/*
	/* Tag ops-entered resolutions so response-time stats can exclude them: the
	/* duration would measure how long ops took to type it in, not how long the
	/* crew member took to answer. Only on a genuine offered -> resolved move;
	/* tagging every write would leave 'ops' stuck on a row the trigger later
	/* needs to treat as a crew response. The trigger still stamps responded_at
	/* (the row DID resolve), so it is never miscounted as a non-response. */

	if (in_array($prevStatus, array(0, 1)) && in_array($status, array(5, 6, 7, 8)))
	{
		$updFields['responded_src'] = $db->sc('ops');
	}

	$db->update('call_crew_map', $updFields, 'callID=' . $callID . ' AND userID=' . $userID);

	$err         = mysql_error();
	$updAffected = mysql_affected_rows();   /* capture NOW; the calendar call below runs its own queries */

	if ($err !== '')
	{
		send_status(500, 'Internal Server Error');
		echo json_encode(array('error' => 'status update failed', 'detail' => $err));
		die();
	}

	/* ---- calendar parity: confirm ADDS, everything else leaves it alone ----
	/*
	/* Mirrors add-call.php action=confirm: on status 5 we re-sync the crew
	/* member's calendar via SmartStaff's own $sss->addToCalendar (global, from
	/* global.php). Decline / no-show / pending / unconfirmed do not touch the
	/* calendar, matching native and keeping declined entries visible. */

	$calendarSynced = 0;

	if ($status === 5)
	{
		$sss->addToCalendar($callID, $userID);
		$calendarSynced = 1;
	}

	/* ---- promotion acknowledgement flag ----
	/*
	/* A 7 -> 5 promotion is an ops decision the crew member has not answered.
	/* Flag it so they can Accept/Decline in the Crew Hub, and so ops can see
	/* who has not responded (the pill in the call dialog).
	/*
	/* DELETE-then-INSERT, deliberately NOT the INSERT IGNORE that
	/* call_change_ack uses. That table preserves the original "was" timing on
	/* re-edit; here there is nothing to preserve, and a stale ACKNOWLEDGED row
	/* from an earlier promotion would silently suppress the new flag. Someone
	/* promoted, declined, re-added and promoted again must be asked again.
	/*
	/* Placed AFTER the status write and addToCalendar so a failed promotion
	/* never leaves an orphan flag. Not gated on affected_rows: re-saving the
	/* same status changes 0 rows but is not a failure.
	/*
	/* Since link-safe Promote this runs once per promoted row: the downstream
	/* rows above, then X here, all with the same $now so they share one
	/* promoted_at and are answered as one group. */

	$promoFlagged = 0;

	if ($isPromotion)
	{
		mysql_query('DELETE FROM call_promo_ack WHERE callID=' . intval($callID) .
		            ' AND userID=' . intval($userID));

		$db->insert('call_promo_ack', array(
			'callID'      => $db->sc($callID),
			'userID'      => $db->sc($userID),
			'promoted_at' => $db->sc($now)
		));

		$promoFlagged = (mysql_error() === '') ? 1 : 0;

		$promotedCalls[] = $callID;   /* X last */
	}

	/* ---- call-out fill check (brief, Part F follow-up) ----
	/*
	/* Ops setting someone to Confirmed can fill a call that has a call-out
	/* running. Close it 'filled' the same as a filling accept would, so the
	/* members still at offered go back on Backup instead of sitting on a
	/* call-out card for a full call. Every call written to 5 counts: X, and
	/* the downstream calls a link-safe Promote moved with it.
	/*
	/* Under the capacity lock, which goat_callout_close requires. The status
	/* write above is already done and is NOT undone if the lock is busy: the
	/* call-out then stays open and callout-sweep.php's full-call catch-all
	/* closes it within 15 minutes. */

	$calloutsClosed = array();

	if ($status === 5)
	{
		include_once('callout.php');

		$wrote5   = $promotedCalls;
		$wrote5[] = $callID;

		if (goat_capacity_lock(3))
		{
			$calloutsClosed = goat_callout_close_if_full($wrote5);
			goat_capacity_unlock();
		}
	}

	echo json_encode(array(
		'ok'              => true,
		'call_id'         => $callID,
		'booking_id'      => $bookingID,
		'user_id'         => $userID,
		'status'          => $status,
		'promoted'        => $isPromotion,
		'promoted_calls'  => $promotedCalls,   /* downstream-most first, X last; empty unless promoted */
		'callouts_closed' => $calloutsClosed,  /* call-outs this write filled and closed */
		'promo_flagged'   => $promoFlagged,
		'calendar_synced' => $calendarSynced,
		'affected_rows'   => $updAffected,
	));

?>
