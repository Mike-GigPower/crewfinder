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
	/* Contract: POST callID, action in {accept, decline}. The answer covers the
	/* posted call's whole GROUP — every unanswered row of this user's sharing
	/* its promoted_at (DESIGN-backup-callout §3.2). A Crew Hub build that still
	/* shows one card per call answers the group from the first card; the
	/* second then gets the 'no pending promotion' no-op, which is harmless.
	/*
	/*   accept:
	/*     - time-gated on the earliest-starting call in the group (Melbourne
	/*       wall-clock, same computation as respond-to-call.php)
	/*     - stamp acked_at + acked_src='crew' on every group row; status LEFT
	/*       UNTOUCHED (5 stays 5)
	/*   decline:
	/*     - never time-gated
	/*     - every group row: status 5 -> 6 (Declined) + removeFromCalendar
	/*     - Confirmed rows upstream of the group are withdrawn too (5 -> 6)
	/*     - stamp acked_at + acked_src='crew' on every group row
	/*
	/* Response adds group_calls and, on decline, withdrawn_calls.
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

	include_once('promo-group.php');

	/*
	/* Require an UNANSWERED promotion for THIS user on THIS call. The pending
	/* row's presence is the whole authorisation: no row, or an already-answered
	/* row, means nothing to answer — goat_promo_group returns empty.
	/*
	/* The GROUP — one promotion: this user's unanswered rows sharing the
	/* posted row's promoted_at (promo-group.php). Always contains $callID. A
	/* single-call promotion is a group of one and behaves exactly as before.
	*/
	$groupCalls = goat_promo_group($userID, $callID);

	if (!count($groupCalls))
	{
		echo json_encode(array('ok' => false, 'error' => 'no pending promotion'));
		exit;
	}

	$groupIn = implode(',', $groupCalls);

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
		/*
		/* Gated on the EARLIEST-starting call in the group: the group is
		/* answered as one, so once its first call has started it is too late.
		*/
		$melTz   = new DateTimeZone('Australia/Melbourne');
		$nowTs   = time();
		$firstTs = false;

		$tres = mysql_query('SELECT start_date, start_time FROM calls WHERE id IN (' . $groupIn . ')');

		if ($tres !== false)
		{
			while ($cRow = mysql_fetch_object($tres))
			{
				$cDate = date('Y-m-d', (int) $cRow->start_date);

				try {
					$dt = new DateTime($cDate . ' ' . $cRow->start_time, $melTz);
					$ts = $dt->getTimestamp();
				} catch (Exception $e) {
					continue;
				}

				if ($firstTs === false || $ts < $firstTs)
				{
					$firstTs = $ts;
				}
			}
		}

		if ($firstTs !== false && $firstTs <= $nowTs)
		{
			echo json_encode(array(
				'ok'      => false,
				'expired' => true,
				'error'   => 'This shift has already started, so it can no longer be confirmed.'
			));
			exit;
		}

		mysql_query(
			'UPDATE call_promo_ack SET acked_at=' . time() . ", acked_src='crew'" .
			' WHERE callID IN (' . $groupIn . ') AND userID=' . intval($userID) .
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
			'result_status' => $priorStatus,   /* unchanged */
			'group_calls'   => $groupCalls
		));
		exit;
	}

	/*
	/* action == decline (never time-gated). Only a row currently Confirmed (5)
	/* flips to Declined (6) — a promotion only ever lands on 5. Anything else is
	/* a no-op status-wise but still stamps the ack. Every call in the group.
	*/
	$declined = false;

	foreach ($groupCalls as $gc)
	{
		$db->update(
			'call_crew_map',
			array('status' => $db->sc(6)),
			'status = 5 AND callID=' . $db->sc($gc) . ' AND userID=' . $db->sc($userID)
		);

		if (mysql_error())
		{
			http_response_code(500);
			die('{"error":"decline update failed: ' . addslashes(mysql_error()) . '"}');
		}

		if (mysql_affected_rows() > 0)
		{
			/* A promoted row IS confirmed, so it always has a calendar row to remove. */
			$sss->removeFromCalendar($gc, $userID);

			if ($gc == $callID)
			{
				$declined = true;
			}
		}
	}

	/*
	/* Withdraw Confirmed rows UPSTREAM of what was just declined — the same
	/* rule a normal decline applies (respond-to-call.php's $breakCommitment,
	/* via the shared goat_decline_scope). Without this, declining a promoted
	/* load-out would leave them Confirmed on the load-in that commits them to
	/* it, which is the one state the feed rules forbid (DESIGN §6).
	/*
	/* goat_promo_withdraw_ids takes the union over EVERY call in the group,
	/* and is the same code my-shifts.php uses for promo_declining_withdraws,
	/* so the warning Crew Hub showed is exactly what happens here.
	*/
	$withdrawn = array();

	foreach (goat_promo_withdraw_ids($userID, $groupCalls) as $wc)
	{
		$db->update(
			'call_crew_map',
			array('status' => $db->sc(6)),
			'status = 5 AND callID=' . $db->sc($wc) . ' AND userID=' . $db->sc($userID)
		);

		if (mysql_error())
		{
			http_response_code(500);
			die('{"error":"upstream withdraw failed: ' . addslashes(mysql_error()) . '"}');
		}

		if (mysql_affected_rows() > 0)
		{
			$sss->removeFromCalendar($wc, $userID);
			$withdrawn[] = $wc;
		}
	}

	mysql_query(
		'UPDATE call_promo_ack SET acked_at=' . time() . ", acked_src='crew'" .
		' WHERE callID IN (' . $groupIn . ') AND userID=' . intval($userID) .
		' AND acked_at IS NULL'
	);

	if (mysql_error())
	{
		http_response_code(500);
		die('{"error":"ack stamp failed: ' . addslashes(mysql_error()) . '"}');
	}

	echo json_encode(array(
		'ok'              => true,
		'action'          => 'decline',
		'result_status'   => $declined ? 6 : $priorStatus,
		'group_calls'     => $groupCalls,
		'withdrawn_calls' => $withdrawn   /* upstream Confirmed rows taken back */
	));

?>
