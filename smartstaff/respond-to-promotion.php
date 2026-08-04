<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SELF endpoint — the acting crew member ANSWERS a promotion.
	/*
	/* When ops promote a Backup (7) into a Confirmed (5) spot, that is a
	/* decision the crew member never agreed to: they accepted standby, possibly
	/* weeks earlier, and their circumstances may have changed. update-crew-
	/* status.php writes a call_promo_ack row; this endpoint answers it.
	/*
	/* Kept SEPARATE from respond-to-call.php, whose guard is "status <= 1"
	/* (only an OFFERED call can be answered) — a promoted crew member sits at
	/* 5 and that endpoint would reject them. Kept separate from
	/* respond-to-change.php too: that one's authorisation is a call_change_ack
	/* row, a different question with different semantics.
	/*
	/* Contract: POST callID, action in {accept, decline}. SINGLE CALL only — no
	/* link-group cascade in v1, matching respond-to-change.php. (A declined
	/* promotion on a load-out can strand an upstream load-in the crew member
	/* already holds; that cascade is deliberate follow-up work, see the design
	/* doc's open questions.)
	/*
	/*   accept:
	/*     - time-gated: refuse if the call has already started (Melbourne
	/*       wall-clock, same computation as respond-to-call.php)
	/*     - stamp acked_at + acked_src='crew'; status LEFT UNTOUCHED (5 stays 5)
	/*   decline:
	/*     - never time-gated
	/*     - status 5 -> 6 (Declined) + removeFromCalendar
	/*     - stamp acked_at + acked_src='crew'
	/*
	/* Self-scoped, service-key trust — same identity path as
	/* respond-to-call.php. Reuses $db and $sss from global.php so the
	/* side-effects are byte-identical to SmartStaff's own dashboard.
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
	/* Require an UNANSWERED promotion for THIS user on THIS call. The pending
	/* row's presence is the whole authorisation: no row, or an already-answered
	/* row, means nothing to answer.
	*/
	$promo = $db->selectFirst(
		'id',
		'call_promo_ack',
		'callID=' . $db->sc($callID) . ' AND userID=' . $db->sc($userID) .
		' AND acked_at IS NULL'
	);

	if (!$promo)
	{
		echo json_encode(array('ok' => false, 'error' => 'no pending promotion'));
		exit;
	}

	$mapRow      = $db->selectFirst(
		'status',
		'call_crew_map',
		'callID=' . $db->sc($callID) . ' AND userID=' . $db->sc($userID)
	);
	$priorStatus = $mapRow ? (int) $mapRow->status : 0;

	if ($action === 'accept')
	{
		/*
		/* Time guard — a promotion onto a shift that has ALREADY STARTED can no
		/* longer be confirmed. Melbourne wall-clock, so correct whatever
		/* timezone the server runs in. Mirrors respond-to-change.php.
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
					'error'   => 'This shift has already started, so it can no longer be confirmed.'
				));
				exit;
			}
		}

		mysql_query(
			'UPDATE call_promo_ack SET acked_at=' . time() . ", acked_src='crew'" .
			' WHERE callID=' . intval($callID) . ' AND userID=' . intval($userID) .
			' AND acked_at IS NULL'
		);

		if (mysql_error())
		{
			http_response_code(500);
			die('{"error":"ack stamp failed: ' . addslashes(mysql_error()) . '"}');
		}

		echo json_encode(array(
			'ok'            => true,
			'action'        => 'accept',
			'result_status' => $priorStatus   /* unchanged */
		));
		exit;
	}

	/*
	/* action == decline (never time-gated). Only a row currently Confirmed (5)
	/* flips to Declined (6) — a promotion only ever lands on 5. Anything else is
	/* a no-op status-wise but still stamps the ack.
	*/
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

	$declined = (mysql_affected_rows() > 0);

	/* A promoted row IS confirmed, so it always has a calendar row to remove. */
	if ($declined)
	{
		$sss->removeFromCalendar($callID, $userID);
	}

	mysql_query(
		'UPDATE call_promo_ack SET acked_at=' . time() . ", acked_src='crew'" .
		' WHERE callID=' . intval($callID) . ' AND userID=' . intval($userID) .
		' AND acked_at IS NULL'
	);

	if (mysql_error())
	{
		http_response_code(500);
		die('{"error":"ack stamp failed: ' . addslashes(mysql_error()) . '"}');
	}

	echo json_encode(array(
		'ok'            => true,
		'action'        => 'decline',
		'result_status' => $declined ? 6 : $priorStatus
	));

?>
