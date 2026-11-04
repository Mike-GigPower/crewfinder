<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* HAS OPS APPROVED THESE CALLS' TIMES? — slice D of BRIEF-crew-self-times
	/* (§5.4). Read by the Supabase nightly job that starts the 90-day clock on
	/* self-submitted timesheet photos (D8) and restarts it when times are
	/* re-opened.
	/*
	/* WHY THE JOB ASKS SMARTSTAFF: approval can be made in SmartStaff's own
	/* call screens, which THE GOAT never sees (D19). times_filled is the tick
	/* both routes set.
	/*
	/*   POST {callIDs: [int, ...]}     at most 500
	/*
	/*   200 {ok: true, by_call: {callID: {times_filled: 0|1,
	/*                                     accepted_at: 'Y-m-d H:i:s' | null}}}
	/*
	/* accepted_at IS THE LATEST accepted_at AMONG THE CALL'S LIVE SUBMISSIONS
	/* (not voided, not superseded by a live row), or null. The job uses it as
	/* the exact approval time when present and the night it first sees
	/* times_filled = 1 otherwise.
	/*
	/* NOTHING WRITES accepted_at TODAY. At ac00bfd no file in smartstaff/ and
	/* nothing in app.py sets call_time_submissions.accepted_at or accepted_by,
	/* so this returns null for every call until THE GOAT's accept starts
	/* writing it, and the job falls back to the night it first sees the tick.
	/* Carried in the response now so the job needs no change when it does.
	/*
	/* Ids that are not in `calls` are absent from by_call. A failed lookup is
	/* a 500, never a partial answer: the job reads a missing call as "not
	/* approved yet", and a broken query must not be able to say that.
	/*
	/* SERVICE KEY ONLY. No session path; nothing here is for a person.
	/*
	/* DATABASE: goat_pdo() (goat-db.php), prepared statements only, per the
	/* house rule for new endpoints. Errors are logged in full server-side and
	/* reported through goat_json_error() without the driver's text.
	/*
	/* PHP 5.x — array(), no ??, no short array syntax.
	*/

	$goat_key = isset($_SERVER['HTTP_X_GOAT_SERVICE_KEY'])
	          ? $_SERVER['HTTP_X_GOAT_SERVICE_KEY'] : '';

	if (!goat_service_key_ok($goat_key))
	{
		goat_json_error(403, 'service key required');
	}

	$payload = json_decode(file_get_contents('php://input'));

	if (!$payload || !isset($payload->callIDs) || !is_array($payload->callIDs))
	{
		goat_json_error(422, 'callIDs must be an array of call ids');
	}

	if (count($payload->callIDs) > 500)
	{
		goat_json_error(422, 'at most 500 callIDs per request');
	}

	$ids = array();

	foreach ($payload->callIDs as $cid)
	{
		$cid = (int) $cid;

		if ($cid > 0)
		{
			$ids[$cid] = true;
		}
	}

	if (count($ids) === 0)
	{
		echo json_encode(array('ok' => true, 'by_call' => new stdClass()));
		exit;
	}

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		goat_json_error(500, 'database unavailable');
	}

	$idVals = array_keys($ids);
	$marks  = implode(',', array_fill(0, count($idVals), '?'));

	$out = array();

	try
	{
		/* ---- the tick. Cast in PHP, never "= 1" in SQL: SmartStaff writes it ---- */

		$cs = $pdo->prepare("SELECT `id`, `times_filled` FROM `calls` WHERE `id` IN (" . $marks . ")");
		$cs->execute($idVals);

		foreach ($cs->fetchAll() as $row)
		{
			$out[(int) $row['id']] = array(
				'times_filled' => ((int) $row['times_filled'] === 1) ? 1 : 0,
				'accepted_at'  => null
			);
		}

		/* ---- latest accepted_at among LIVE submissions ---- */

		if (count($out) > 0)
		{
			$found = array_keys($out);

			$ss = $pdo->prepare("SELECT s.`callID` AS call_id, MAX(s.`accepted_at`) AS accepted_at
			                     FROM `call_time_submissions` s
			                     WHERE s.`callID` IN (" . implode(',', array_fill(0, count($found), '?')) . ")
			                       AND s.`voided` = 0
			                       AND s.`accepted_at` IS NOT NULL
			                       AND NOT EXISTS (
			                         SELECT 1
			                         FROM `call_time_submissions` n
			                         WHERE n.`supersedes_id` = s.`id`
			                           AND n.`voided` = 0
			                       )
			                     GROUP BY s.`callID`");
			$ss->execute($found);

			foreach ($ss->fetchAll() as $srow)
			{
				$cid = (int) $srow['call_id'];

				if (isset($out[$cid]) && $srow['accepted_at'] !== null)
				{
					$out[$cid]['accepted_at'] = (string) $srow['accepted_at'];
				}
			}
		}
	}
	catch (PDOException $e)
	{
		error_log('self-times-approval: ' . $e->getMessage());
		goat_json_error(500, 'lookup failed');
	}

	/* json_encode() emits an OBJECT for by_call because call ids are never the
	   list 0..n-1; the empty case is forced to {} so the shape never flips to
	   [] when nothing matched */

	echo json_encode(array('ok' => true, 'by_call' => (count($out) ? $out : new stdClass())));

?>
