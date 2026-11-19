<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include('call-change-log.php');
	include_once('leave-standby-lib.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SELF endpoint — the acting crew member ANSWERS a call-change re-confirm.
	/* When a call's TIMING is edited after crew were contacted, update-call.php
	/* writes a call_change_ack row per affected confirmed (5) / backup (7) crew
	/* member (the "you have an unacknowledged change" flag). This endpoint clears
	/* that flag when the crew member responds.
	/*
	/* Kept SEPARATE from respond-to-call.php on purpose: that endpoint's guard is
	/* "status <= 1" (only an OFFERED call can be answered). This one's guard is
	/* "an ack row exists for me on this call" — a CONFIRMED/BACKUP crew member
	/* answering a change, which respond-to-call.php would reject.
	/*
	/* Contract: POST callID, action in {accept, decline}. Acts on the SINGLE call
	/* only — no link-group cascade in v1 (the flag is written per changed call).
	/*
	/*   accept  (confirmed re-confirms, OR a backup harmlessly clears the flag):
	/*     - time-gated: refuse if the NEW start has already passed (Melbourne
	/*       wall-clock, same computation as respond-to-call.php)
	/*     - DELETE the ack row; call_crew_map.status is LEFT UNTOUCHED (5 stays 5,
	/*       7 stays 7) — accepting a change is not a re-offer, just an ack.
	/*   decline (confirmed drops out, OR a backup opts off standby):
	/*     - never time-gated
	/*     - status 5 -> 6 (Declined), and removeFromCalendar
	/*     - status 7 -> 13 (Left standby), NOT 6 (S5). It is the same intent as
	/*       the Take me off standby button, so it must land on the same status;
	/*       sending it to 6 would count a withdrawal from standby against them
	/*       and contradict D1. It cascades to locked-upstream standby rows
	/*       through goat_leave_standby_scope(), the same helper
	/*       leave-standby.php writes from, and records src 'change'.
	/*       No calendar work: a backup holds no place (and an existing row from
	/*       an ops 5 -> 7 demotion is left alone, exactly as before).
	/*     - DELETE the ack row — for every call that moved, not just the seed
	/*
	/* HISTORY: every successful answer also appends one row to call_change_log
	/* (answer, answer_src 'crew', prior/result status, answered_at, plus the
	/* "was" timing copied from the ack row before it is deleted). That table is
	/* the audit trail; call_change_ack stays a pure pending flag, deleted on
	/* answer, exactly as before. See MIGRATION-call-change-log.sql.
	/*
	/* Self-scoped, service-key trust — same identity path as respond-to-call.php.
	/* Reuses $db and $sss from global.php so the side-effects are byte-identical
	/* to SmartStaff's own dashboard.
	/*
	/* PHP 5.x — mysql_*, no null-coalescing (??), no short array syntax.
	/* The call_change_log history INSERT is the exception: it goes through
	/* goat_log_change_answer() in call-change-log.php (PDO, prepared).
	*/

	$userID = (int) goat_acting_user_id();

	if ($userID <= 0)
	{
		http_response_code(403);
		die('{"error":"not authorised"}');
	}

	$callID = isset($_POST['callID']) ? (int) $_POST['callID'] : 0;
	$action = isset($_POST['action']) ? strtolower(trim($_POST['action'])) : '';

	if ($callID <= 0)
	{
		http_response_code(400);
		die('{"error":"callID required"}');
	}

	if ($action !== 'accept' && $action !== 'decline')
	{
		http_response_code(400);
		die('{"error":"action must be accept or decline"}');
	}

	/*
	/* Require an outstanding change for THIS user on THIS call. The ack row's
	/* presence is the whole authorisation: no row -> nothing to answer.
	*/
	$ack = $db->selectFirst(
		'id, prev_start_date, prev_start_time, prev_est_length, changed_at',
		'call_change_ack',
		'callID=' . $db->sc($callID) . ' AND userID=' . $db->sc($userID)
	);

	if (!$ack)
	{
		echo json_encode(array('ok' => false, 'error' => 'no pending change'));
		exit;
	}

	/*
	/* Read the crew member's current status on this call — decides whether a
	/* decline needs removeFromCalendar (only a prior Confirmed 5 has a calendar
	/* row) and what result_status we report.
	*/
	$mapRow      = $db->selectFirst(
		'status',
		'call_crew_map',
		'callID=' . $db->sc($callID) . ' AND userID=' . $db->sc($userID)
	);
	$priorStatus = $mapRow ? (int) $mapRow->status : 0;

	if ($action === 'accept')
	{
		/*
		/* Time guard — a change to a shift that has ALREADY STARTED can no longer
		/* be re-confirmed. Measured against the NEW (current) call timing in
		/* Australia/Melbourne, so it is correct whatever timezone the server runs
		/* in. Mirrors respond-to-call.php / my-call-offers.php.
		*/
		$cRow = $db->selectFirst('start_date, start_time', 'calls', 'id=' . $db->sc($callID));

		if ($cRow)
		{
			$melTz   = new DateTimeZone('Australia/Melbourne');
			$nowTs   = time();
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
					'error'   => 'This shift has already started, so it can no longer be re-confirmed.'
				));
				exit;
			}
		}

		/* Clear the flag; leave call_crew_map.status untouched (5 stays 5, 7 stays 7). */
		mysql_query(
			'DELETE FROM call_change_ack WHERE callID=' . intval($callID) .
			' AND userID=' . intval($userID)
		);

		if (mysql_error())
		{
			http_response_code(500);
			die('{"error":"ack clear failed: ' . addslashes(mysql_error()) . '"}');
		}

		/* History row — status unchanged on accept. */
		goat_log_change_answer($callID, $userID, $ack, 'accept', 'crew', $priorStatus, $priorStatus);

		/*
		/* Subsume any pending promotion acknowledgement: answering the timing
		/* change answers the promotion. Accepting the new time is agreement to
		/* be on the call; declining flips 5 -> 6 and makes it moot either way.
		/* Harmless no-op when there is no promo row. Placed AFTER the change
		/* answer's own error gate and NOT gated itself: the change answer has
		/* already succeeded and must never 500 over a subsumption.
		*/
		mysql_query(
			'UPDATE call_promo_ack SET acked_at=' . time() . ", acked_src='crew'" .
			' WHERE callID=' . intval($callID) . ' AND userID=' . intval($userID) .
			' AND acked_at IS NULL'
		);

		echo json_encode(array(
			'ok'            => true,
			'action'        => 'accept',
			'result_status' => $priorStatus   /* unchanged */
		));
		exit;
	}

	/*
	/* action == decline (never time-gated). TWO destinations, chosen by what the
	/* row IS, not by what $priorStatus said it was when we read it further up:
	/*
	/*   status 7 -> 13  Left standby (S5), with the locked-upstream cascade
	/*   status 5 ->  6  Declined, as before
	/*
	/* Driven by each UPDATE's own guard rather than by $priorStatus, so a row
	/* that moved between the read and here lands correctly either way. The
	/* standby path is tried first: goat_leave_standby_scope() returns an empty
	/* set unless this user's seed row is actually at 7, so a Confirmed row
	/* falls straight through to the 5 -> 6 path below.
	/*
	/* Anything at another status is a no-op status-wise but still clears the flag.
	*/

	$leftStandby = array();   /* call ids moved 7 -> 13 */
	$scope       = goat_leave_standby_scope($userID, $callID);

	/*
	/* false means the scope read failed, NOT that they hold nothing. Falling
	/* through would be the dangerous outcome: the 5 -> 6 update below is guarded
	/* on status 5, so a backup's row would stay at 7 while the ack was cleared
	/* and the response reported success — a crew member told they are off
	/* standby who is still on it. Refuse instead; nothing has been written yet.
	*/
	if ($scope === false)
	{
		http_response_code(500);
		die('{"error":"leave standby scope read failed"}');
	}

	if (count($scope))
	{
		$idList = implode(',', array_map('intval', $scope));

		/*
		/* `status = 7` is load-bearing for the same reason it is in
		/* leave-standby.php: a promotion landing between the scope read and
		/* this statement must not be rewritten to 13.
		*/
		mysql_query('UPDATE call_crew_map SET status = 13
		             WHERE status = 7 AND userID = ' . intval($userID) . '
		               AND callID IN (' . $idList . ')');

		if (mysql_error())
		{
			http_response_code(500);
			die('{"error":"leave standby update failed: ' . addslashes(mysql_error()) . '"}');
		}

		/* which rows actually moved — settled by a re-read, not affected_rows */

		$lres = mysql_query('SELECT callID FROM call_crew_map
		                     WHERE userID = ' . intval($userID) . ' AND status = 13
		                       AND callID IN (' . $idList . ')');

		if ($lres !== false)
		{
			while ($lrow = mysql_fetch_object($lres))
			{
				$leftStandby[] = (int) $lrow->callID;
			}
		}

		/*
		/* History, src 'change': this withdrawal came from answering a timing
		/* change, not from the Take me off standby button. Degrades if
		/* MIGRATION-leave-standby.sql has not run — the status write stands
		/* either way, which matters more than the audit row.
		*/
		if (count($leftStandby))
		{
			$lsr = mysql_query("SHOW TABLES LIKE 'call_standby_leave'");

			if ($lsr !== false && mysql_num_rows($lsr) > 0)
			{
				$nowStamp = time();
				$vals     = array();

				foreach ($leftStandby as $lc)
				{
					$vals[] = '(' . intval($lc) . ',' . intval($userID) . ',' . $nowStamp . ",'change')";
				}

				mysql_query('INSERT INTO call_standby_leave (callID, userID, left_at, src)
				             VALUES ' . implode(',', $vals));
			}
		}
	}

	$declined = false;

	if (!count($leftStandby))
	{
		$db->update(
			'call_crew_map',
			array('status' => $db->sc(6)),
			'status = 5 AND callID=' . $db->sc($callID) . ' AND userID=' . $db->sc($userID)
		);

		if (mysql_error())
		{
			http_response_code(500);
			die('{"error":"decline update failed: ' . addslashes(mysql_error()) . '"}');
		}

		$declined = ($db->affected_rows > 0);
	}

	/* A prior Confirmed (5) had a calendar row — remove it. Backups (7) never do. */
	if ($declined && $priorStatus === 5)
	{
		$sss->removeFromCalendar($callID, $userID);
	}

	/*
	/* Clear the flag. For a standby withdrawal that means every call that moved,
	/* not just the seed: they are no longer waiting to hear about any of their
	/* timings. The seed is always in $leftStandby when the cascade ran, so this
	/* covers it.
	*/

	$ackCalls = count($leftStandby) ? $leftStandby : array((int) $callID);

	mysql_query(
		'DELETE FROM call_change_ack WHERE callID IN (' . implode(',', array_map('intval', $ackCalls)) . ')' .
		' AND userID=' . intval($userID)
	);

	if (mysql_error())
	{
		http_response_code(500);
		die('{"error":"ack clear failed: ' . addslashes(mysql_error()) . '"}');
	}

	/*
	/* History row — the status actually written: 13 for a standby withdrawal
	/* (S5), 6 for a confirmed decline, otherwise the status it already had.
	/* Crew Hub reads this to choose the toast tone, so 13 must be recorded
	/* truthfully or a withdrawal is toasted as a decline (crew-data.ts:845).
	*/
	$resultStatus = count($leftStandby) ? 13 : ($declined ? 6 : $priorStatus);

	goat_log_change_answer($callID, $userID, $ack, 'decline', 'crew', $priorStatus, $resultStatus);

	/*
	/* Subsume any pending promotion acknowledgement: answering the timing
	/* change answers the promotion. Accepting the new time is agreement to
	/* be on the call; declining flips 5 -> 6 and makes it moot either way.
	/* Harmless no-op when there is no promo row. Placed AFTER the change
	/* answer's own error gate and NOT gated itself: the change answer has
	/* already succeeded and must never 500 over a subsumption.
	*/
	mysql_query(
		'UPDATE call_promo_ack SET acked_at=' . time() . ", acked_src='crew'" .
		' WHERE callID=' . intval($callID) . ' AND userID=' . intval($userID) .
		' AND acked_at IS NULL'
	);

	echo json_encode(array(
		'ok'            => true,
		'action'        => 'decline',
		'result_status' => $resultStatus,
		/*
		/* Additive (S5). The calls this answer took off standby, seed first in
		/* scope order, so the Hub can name them in the toast exactly as the
		/* Take me off standby flow does. Empty on an ordinary 5 -> 6 decline.
		*/
		'left_standby'  => $leftStandby
	));

?>
