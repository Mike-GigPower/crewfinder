<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');

	header('Content-Type: application/json');

	/*
	/* OFFER REMINDERS — withdraw unanswered offers (DESIGN-offer-reminders
	/* v0.3 §1, §3, §5; BRIEF slice 2 §3.3).
	/*
	/*   no_response            -> 10  normal offer, 30 h, a push reached a device
	/*   not_delivered          -> 11  normal offer, 30 h, no push ever reached one
	/*   withdrawn_call_started -> 12  short-notice offer, at call start
	/*
	/* The Crew Hub cron chooses the outcome (only it can read push_events).
	/* THIS FILE DECIDES WHETHER: it recomputes short notice and due-ness from
	/* the row and the call and refuses anything early, so a cron bug cannot
	/* withdraw an offer before its time. It also refuses phone rows
	/* (phone_row) and offers on HIDDEN bookings (hidden_booking) — a booking
	/* can be hidden between the get-offers-due read and this write (Q12
	/* revised, 10 Nov).
	/*
	/* THE UPDATE IS GUARDED THREE WAYS — status <= 1, the exact offered_at the
	/* cron evaluated (a re-offer in between changes it), and the row id. A
	/* crew member answering in the same second wins; rowCount() = 0 is then the
	/* answer "lost_race", not an error. This is the one place in the file where
	/* the row count is read, and it is read for that meaning only.
	/*
	/* MyISAM: no transaction. Order is UPDATE, then offer_history, then the
	/* defensive calendar delete. If the history insert fails the withdrawal
	/* stands (the person is not left holding a live offer) and the result
	/* says history:false, so the gap is visible rather than silent.
	/*
	/* offer_history uniqueness (callID, userID, offered_at) makes a repeat
	/* call for the same round a no-op: INSERT IGNORE.
	/*
	/* Trigger interplay (ccm_responded_at_upd, 391 / 8e17ddf8, 10 Nov):
	/*   arm 1 — skipped by refusing phone rows here;
	/*   arm 2 — 10/11/12 -> 0 later restarts the clock (D12);
	/*   arm 3 — does NOT fire for 10-12, so a withdrawal never stamps
	/*           responded_at. Proved on test 10 Nov.
	/*
	/* PHP 5.6.
	*/

	$goat_key = isset($_SERVER['HTTP_X_GOAT_SERVICE_KEY'])
	          ? $_SERVER['HTTP_X_GOAT_SERVICE_KEY'] : '';

	$viaService = goat_service_key_ok($goat_key);

	if (!$viaService && goat_user_cohort() !== 'admin')
	{
		goat_json_error(403, 'Service key or admin session required');
	}

	if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST')
	{
		goat_json_error(405, 'POST required');
	}

	$payload = json_decode(file_get_contents('php://input'));

	if (!is_object($payload) || !isset($payload->items) || !is_array($payload->items))
	{
		goat_json_error(400, 'items array required');
	}

	if (count($payload->items) === 0 || count($payload->items) > 200)
	{
		goat_json_error(400, 'items must hold 1 to 200 entries');
	}

	$dryRun = (isset($payload->dry_run) && $payload->dry_run) ? true : false;

	/* an admin browser session may look, never write */
	if (!$viaService)
		$dryRun = true;

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		goat_json_error(503, 'Database handle unavailable');
	}

	$OUTCOME_STATUS = array(
		'no_response'            => 10,
		'not_delivered'          => 11,
		'withdrawn_call_started' => 12
	);

	$WINDOW = 108000;   /* 30 h */
	$now    = time();   /* one clock for the whole request */

	try
	{
		$sel = $pdo->prepare("
			SELECT ccm.crewmapID, ccm.callID, ccm.userID, ccm.status, ccm.responded_src,
			       DATE_FORMAT(ccm.offered_at, '%Y-%m-%d %H:%i:%s') AS offered_at,
			       UNIX_TIMESTAMP(ccm.offered_at)                   AS offered_unix,
			       c.start_date, c.start_time,
			       IFNULL(b.hidden, 0) AS hidden
			  FROM call_crew_map ccm
			  INNER JOIN calls c ON c.id = ccm.callID
			  LEFT  JOIN bookings b ON b.id = c.bookingID
			 WHERE ccm.crewmapID = :id
		");

		$upd = $pdo->prepare("
			UPDATE call_crew_map
			   SET status = :st
			 WHERE crewmapID  = :id
			   AND status    <= 1
			   AND offered_at = :off
		");

		$cnt = $pdo->prepare("
			SELECT COUNT(*) AS n FROM offer_history
			 WHERE callID = :c AND userID = :u
		");

		$ins = $pdo->prepare("
			INSERT IGNORE INTO offer_history
			       (crewmapID, callID, userID, round, offered_at, outcome, outcome_at, short_notice)
			VALUES (:id, :c, :u, :rnd, :off, :outcome, NOW(), :sn)
		");

		$cal = $pdo->prepare("DELETE FROM calendars WHERE `call` = :c AND `user` = :u");
	}
	catch (PDOException $e)
	{
		error_log('expire-offer: prepare: ' . $e->getMessage());
		goat_json_error(500, 'prepare failed');
	}

	$results = array();
	$counts  = array('changed' => 0, 'would_change' => 0, 'skipped' => 0);

	foreach ($payload->items as $item)
	{
		$res = array(
			'crewmap_id'    => null,
			'result'        => 'skipped',
			'reason'        => null,
			'status_after'  => null,
			'round'         => null,
			'history'       => false,
			'calendar_rows' => 0
		);

		if (!is_object($item) || !isset($item->crewmap_id) || !isset($item->offered_unix) || !isset($item->outcome))
		{
			$res['reason'] = 'bad_item';
			$results[] = $res; $counts['skipped']++;
			continue;
		}

		$id       = (int) $item->crewmap_id;
		$seenOff  = (int) $item->offered_unix;
		$outcome  = (string) $item->outcome;
		$res['crewmap_id'] = $id;

		if ($id <= 0 || $seenOff <= 0)
		{
			$res['reason'] = 'bad_item';
			$results[] = $res; $counts['skipped']++;
			continue;
		}

		if (!isset($OUTCOME_STATUS[$outcome]))
		{
			$res['reason'] = 'bad_outcome';
			$results[] = $res; $counts['skipped']++;
			continue;
		}

		try
		{
			$sel->execute(array(':id' => $id));
			$row = $sel->fetch(PDO::FETCH_ASSOC);
			$sel->closeCursor();

			if (!$row)
			{
				$res['reason'] = 'not_found';
				$results[] = $res; $counts['skipped']++;
				continue;
			}

			$status = (int) $row['status'];

			if ($status > 1)
			{
				$res['reason'] = 'not_open';
				$res['status_after'] = $status;
				$results[] = $res; $counts['skipped']++;
				continue;
			}

			if ($row['offered_unix'] === null || (int) $row['offered_unix'] !== $seenOff)
			{
				$res['reason'] = 'round_changed';
				$results[] = $res; $counts['skipped']++;
				continue;
			}

			if ($row['responded_src'] === 'phone')
			{
				$res['reason'] = 'phone_row';
				$results[] = $res; $counts['skipped']++;
				continue;
			}

			if ((int) $row['hidden'] === 1)
			{
				$res['reason'] = 'hidden_booking';
				$results[] = $res; $counts['skipped']++;
				continue;
			}

			/* start instant — same idiom as get-offers-due.php */
			$date_i  = (int) $row['start_date'];
			$time_hm = substr((string) $row['start_time'], 0, 5);
			if (!preg_match('/^\d{2}:\d{2}$/', $time_hm)) $time_hm = '00:00';
			list($hh, $mm) = array_map('intval', explode(':', $time_hm));
			$start = strtotime(date('Y-m-d', $date_i) . ' ' . $time_hm . ':00');
			if ($start === false) $start = $date_i + ($hh * 3600) + ($mm * 60);

			$offered = (int) $row['offered_unix'];
			$short   = (($start - $offered) < $WINDOW);

			if ($outcome === 'withdrawn_call_started')
			{
				if (!$short)          { $res['reason'] = 'not_short_notice'; }
				else if ($now < $start) { $res['reason'] = 'not_due'; }
			}
			else
			{
				if ($short)                         { $res['reason'] = 'short_notice_offer'; }
				else if ($now < $offered + $WINDOW) { $res['reason'] = 'not_due'; }
			}

			if ($res['reason'] !== null)
			{
				$results[] = $res; $counts['skipped']++;
				continue;
			}

			$newStatus = $OUTCOME_STATUS[$outcome];

			if ($dryRun)
			{
				$res['result']       = 'would_change';
				$res['status_after'] = $newStatus;
				$results[] = $res; $counts['would_change']++;
				continue;
			}

			$upd->execute(array(':st' => $newStatus, ':id' => $id, ':off' => $row['offered_at']));

			if ($upd->rowCount() === 0)
			{
				$res['reason'] = 'lost_race';
				$results[] = $res; $counts['skipped']++;
				continue;
			}

			$res['result']       = 'changed';
			$res['status_after'] = $newStatus;
			$counts['changed']++;
		}
		catch (PDOException $e)
		{
			error_log('expire-offer: row ' . $id . ': ' . $e->getMessage());
			$res['reason'] = 'db_error';
			$results[] = $res; $counts['skipped']++;
			continue;
		}

		/* ---- history: the withdrawal already stands; a failure here is reported ---- */

		try
		{
			$cnt->execute(array(':c' => (int) $row['callID'], ':u' => (int) $row['userID']));
			$n = (int) $cnt->fetchColumn();
			$cnt->closeCursor();

			$ins->execute(array(
				':id'      => $id,
				':c'       => (int) $row['callID'],
				':u'       => (int) $row['userID'],
				':rnd'     => $n + 1,
				':off'     => $row['offered_at'],
				':outcome' => $outcome,
				':sn'      => $short ? 1 : 0
			));

			$res['round']   = $n + 1;
			$res['history'] = true;
		}
		catch (PDOException $e)
		{
			error_log('expire-offer: history ' . $id . ': ' . $e->getMessage());
		}

		/* ---- calendar: an open offer should have none; remove any stray ---- */

		try
		{
			$cal->execute(array(':c' => (int) $row['callID'], ':u' => (int) $row['userID']));
			$res['calendar_rows'] = (int) $cal->rowCount();
		}
		catch (PDOException $e)
		{
			error_log('expire-offer: calendar ' . $id . ': ' . $e->getMessage());
		}

		$results[] = $res;
	}

	echo json_encode(array(
		'ok'       => true,
		'dry_run'  => $dryRun,
		'now_unix' => $now,
		'counts'   => $counts,
		'results'  => $results
	));

?>
