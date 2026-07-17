<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

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
	/*     - status 5/7 -> 6 (Declined); if it WAS 5, removeFromCalendar (a backup
	/*       has no calendar row, so that path is skipped)
	/*     - DELETE the ack row
	/*
	/* Self-scoped, service-key trust — same identity path as respond-to-call.php.
	/* Reuses $db and $sss from global.php so the side-effects are byte-identical
	/* to SmartStaff's own dashboard.
	/*
	/* PHP 5.x — mysql_*, no null-coalescing (??), no short array syntax.
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
		'id',
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

		echo json_encode(array(
			'ok'            => true,
			'action'        => 'accept',
			'result_status' => $priorStatus   /* unchanged */
		));
		exit;
	}

	/*
	/* action == decline (never time-gated). Only a row currently Confirmed (5) or
	/* Backup (7) flips to Declined (6); anything else is a no-op status-wise but
	/* still clears the flag.
	*/
	$db->update(
		'call_crew_map',
		array('status' => $db->sc(6)),
		'status IN (5, 7) AND callID=' . $db->sc($callID) . ' AND userID=' . $db->sc($userID)
	);

	if (mysql_error())
	{
		http_response_code(500);
		die('{"error":"decline update failed: ' . addslashes(mysql_error()) . '"}');
	}

	$declined = (mysql_affected_rows() > 0);

	/* A prior Confirmed (5) had a calendar row — remove it. Backups (7) never do. */
	if ($declined && $priorStatus === 5)
	{
		$sss->removeFromCalendar($callID, $userID);
	}

	mysql_query(
		'DELETE FROM call_change_ack WHERE callID=' . intval($callID) .
		' AND userID=' . intval($userID)
	);

	if (mysql_error())
	{
		http_response_code(500);
		die('{"error":"ack clear failed: ' . addslashes(mysql_error()) . '"}');
	}

	echo json_encode(array(
		'ok'            => true,
		'action'        => 'decline',
		'result_status' => $declined ? 6 : $priorStatus
	));

?>
