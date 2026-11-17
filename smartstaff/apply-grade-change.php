<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');
	include(dirname(__FILE__) . '/grade-ladder-lib.php');

	header('Content-Type: application/json');

	/*
	/* CHANGE PAY GRADE — APPLY. BRIEF-promotion-paygrade-slice1 §2.3;
	/* DESIGN-promotion-paygrade-cascade v0.2 D1-D15.
	/*
	/* POST JSON {user_id, new_grade, wef, call_ids:[...], expected_current_grade}
	/*
	/* Moves the ticked call rows to the new tier, then the person's own grade.
	/*
	/* EVERY ROW IS RE-CHECKED HERE, against the same rule the preview used
	/* (goat_grade_change_rows). A row that no longer qualifies — call locked
	/* since the preview, status changed, row gone, already on the target — is
	/* SKIPPED with its reason, never forced.
	/*
	/* NO TRANSACTION: call_crew_map is MyISAM (expire-offer.php), so a
	/* transaction would protect nothing. The order does the work instead:
	/*   1. refuse if users.paygradeID is no longer expected_current_grade
	/*   2. write the rows
	/*   3. write users.paygradeID LAST, guarded on the expected value
	/* A run that dies between 2 and 3 leaves the person on the old grade, so
	/* the remaining rows show in the next preview and a re-run finishes the
	/* job. Re-running the same body is safe: done rows report already_target.
	/*
	/* RATES: callpaygradeID and all four rate columns move together, exactly
	/* as update-call-times.php (lines 280-284) writes them on a native save.
	/* The values are bound as the STRINGS read from paygrades — never
	/* SET col = pg.col, which copies FLOAT bits (REMEDIATION-new-crew-paygrade
	/* -2026-09-09.sql step 3).
	/*
	/* promo_pending rows are NOT skipped (deviation from the brief, 17 Nov):
	/* update-call-times.php refuses them because a TIMES write is what gets
	/* someone paid. A grade write pays nobody; a promoted-not-yet-accepted row
	/* should carry the person's grade like any other.
	/*
	/* ccm_responded_at_upd (BEFORE UPDATE trigger): every arm tests a status
	/* change or responded_src = 'phone' on NEW. This UPDATE sets neither, so
	/* it falls through all three arms (body in DESIGN-call-cancellation-v0_1
	/* §4.3).
	/*
	/* rowCount() is read only for its meaning, in two places: on a row write
	/* (from != to, so 0 means the row moved since the re-check) and on the
	/* users write (0 means someone changed the grade in between).
	/*
	/* PHP 5.6.
	*/

	if (goat_user_cohort() !== 'admin')
		goat_json_error(403, 'Admin only');

	if ($_SERVER['REQUEST_METHOD'] !== 'POST')
		goat_json_error(405, 'POST only');

	$body = json_decode(file_get_contents('php://input'), true);
	if (!is_array($body))
		goat_json_error(400, 'Body must be JSON');

	$userId   = isset($body['user_id'])   ? (int) $body['user_id']   : 0;
	$newGrade = isset($body['new_grade']) ? (int) $body['new_grade'] : 0;
	$wef      = isset($body['wef'])       ? (string) $body['wef']    : '';
	$expected = isset($body['expected_current_grade']) ? (int) $body['expected_current_grade'] : -1;
	$callIds  = array();

	if (isset($body['call_ids']) && is_array($body['call_ids']))
	{
		foreach ($body['call_ids'] as $cid)
		{
			$cid = (int) $cid;
			if ($cid > 0 && !in_array($cid, $callIds, true))
				$callIds[] = $cid;
		}
	}

	$today   = goat_today_iso();
	$ppStart = goat_pay_period_start($today);

	if ($userId <= 0)
		goat_json_error(400, 'user_id required');
	if (!in_array($newGrade, goat_grade_ladder(), true))
		goat_json_error(400, 'new_grade must be one of ' . implode(', ', goat_grade_ladder()));
	if ($expected < 0)
		goat_json_error(400, 'expected_current_grade required');
	if (!goat_valid_iso_date($wef))
		goat_json_error(400, 'wef must be a date (YYYY-MM-DD)');
	if ($wef < $ppStart || $wef > $today)
	{
		http_response_code(400);
		die(json_encode(array(
			'error'            => 'WEF must be between ' . $ppStart . ' (start of the current pay period) and ' . $today,
			'code'             => 'wef_out_of_range',
			'pay_period_start' => $ppStart,
			'today'            => $today
		)));
	}
	if (count($callIds) > 500)
		goat_json_error(400, 'at most 500 call_ids');

	try
	{
		$pdo = goat_pdo();

		$lm = goat_grade_ladder_map($pdo);
		if (!$lm['ok'])
			goat_json_error(500, $lm['error']);
		$map = $lm['map'];

		/* 1. the person, and the grade the preview was built on */
		$st = $pdo->prepare('SELECT id, paygradeID, usergroupID FROM users WHERE id = ? LIMIT 1');
		$st->execute(array($userId));
		$u = $st->fetch(PDO::FETCH_ASSOC);

		if (!$u || (int) $u['usergroupID'] !== 3)
			goat_json_error(404, 'crew member not found');

		$cur = (int) $u['paygradeID'];
		if ($cur !== $expected)
		{
			http_response_code(409);
			die(json_encode(array(
				'error'         => 'The grade was changed since the preview. Reload and check.',
				'code'          => 'grade_changed',
				'current_grade' => $cur
			)));
		}

		/* 2. re-check every requested row now, then write the ones that pass */
		$split   = goat_grade_change_rows($pdo, $userId, $newGrade, $wef, $map, $callIds);
		$byCall  = array();
		foreach ($split['rows'] as $r)
			$byCall[$r['call_id']] = $r;

		$why = array();
		foreach ($split['locked'] as $r)     $why[$r['call_id']] = 'locked';
		foreach ($split['before_wef'] as $r) $why[$r['call_id']] = 'before_wef';

		$upd = $pdo->prepare('UPDATE call_crew_map
		                      SET callpaygradeID = ?, callrate = ?, callrate_night = ?,
		                          callchargeout = ?, callchargeout_night = ?
		                      WHERE callID = ? AND userID = ? AND callpaygradeID = ?
		                        AND status IN (' . implode(',', array_map('intval', goat_grade_change_statuses())) . ')');

		/* For "already_target": is the row there at all, and on what grade? */
		$look = $pdo->prepare('SELECT callpaygradeID, status FROM call_crew_map
		                       WHERE callID = ? AND userID = ? LIMIT 1');

		$updated = array();
		$skipped = array();

		foreach ($callIds as $cid)
		{
			if (!isset($byCall[$cid]))
			{
				if (isset($why[$cid]))
				{
					$skipped[] = array('call_id' => $cid, 'reason' => $why[$cid]);
					continue;
				}
				$look->execute(array($cid, $userId));
				$row = $look->fetch(PDO::FETCH_ASSOC);
				$look->closeCursor();

				if (!$row)
					$reason = 'not_on_call';
				else if (goat_grade_target((int) $row['callpaygradeID'], $newGrade, $map) === (int) $row['callpaygradeID'])
					$reason = 'already_target';
				else if (!in_array((int) $row['status'], goat_grade_change_statuses(), true))
					$reason = 'status';
				else if (!isset($map[(int) $row['callpaygradeID']]))
					$reason = 'not_ladder_grade';
				else
					$reason = 'not_eligible';

				$skipped[] = array('call_id' => $cid, 'reason' => $reason);
				continue;
			}

			$r  = $byCall[$cid];
			$to = $map[$r['to_grade']];

			$upd->execute(array(
				(string) $r['to_grade'],
				$to['rate'], $to['night_rate'], $to['co_rate'], $to['night_co_rate'],
				$cid, $userId, $r['from_grade']
			));

			/* from != to always, so a matched row is always a changed row: 0
			/* here means the row moved between the re-check and this write. */
			if ($upd->rowCount() !== 1)
			{
				$skipped[] = array('call_id' => $cid, 'reason' => 'changed_since_check');
				continue;
			}

			$updated[] = array('call_id' => $cid, 'from_grade' => $r['from_grade'], 'to_grade' => $r['to_grade']);
		}

		/* 3. the person's grade, last, guarded on the value we checked */
		$gradeChanged = false;
		if ($cur !== $newGrade)
		{
			$ug = $pdo->prepare('UPDATE users SET paygradeID = ? WHERE id = ? AND paygradeID = ?');
			$ug->execute(array($newGrade, $userId, $cur));

			if ($ug->rowCount() !== 1)
			{
				http_response_code(409);
				die(json_encode(array(
					'error'   => 'The call rows were updated, but the person\'s grade changed underneath. Reload and check.',
					'code'    => 'grade_write_conflict',
					'updated' => $updated,
					'skipped' => $skipped
				)));
			}
			$gradeChanged = true;
		}
	}
	catch (Exception $e)
	{
		goat_json_error(500, 'apply failed: ' . $e->getMessage());
	}

	echo json_encode(array(
		'ok'            => true,
		'grade_changed' => $gradeChanged,
		'from_grade'    => $cur,
		'to_grade'      => $newGrade,
		'wef'           => $wef,
		'updated'       => $updated,
		'skipped'       => $skipped
	));

?>
