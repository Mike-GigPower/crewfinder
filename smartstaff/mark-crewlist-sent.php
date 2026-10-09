<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');
	include(dirname(__FILE__) . '/crewlist-sent-lib.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — record that crew lists were sent for one or more calls
	/* (BRIEF-crewlist-sent §A2, DESIGN-crewlist-sent-v0_2 §4.1, D1).
	/*
	/*   POST { "calls": [ { "call_id": 39050,
	/*                       "crew_ids": [9734, 1234],
	/*                       "start_date": 1791460800,
	/*                       "start_time": "07:00",          HH:MM or HH:MM:SS
	/*                       "est_length": 8.5 }, ... ] }
	/*
	/*   -> { "ok": true, "marked": [39050, ...] }
	/*
	/* THE SNAPSHOT COMES FROM THE CLIENT ON PURPOSE. It must equal what was
	/* PRINTED (§4.1) — the /api/booking data the PDF was made from — not what
	/* the database says by the time Rich clicks "Mark as sent". If a confirmed
	/* crew member changed in between, the call should read 'changed' at once,
	/* which is the truth.
	/*
	/* THE BOOKING ID DOES NOT. bookingID is read from `calls` for each call_id;
	/* a client-sent booking id is never trusted (and is ignored if present).
	/*
	/* ALL OR NOTHING. Every entry is validated, and every call_id checked to
	/* exist and not be cancelled, BEFORE anything is written. Any failure ->
	/* 400 and no rows. The rows then go in as ONE multi-row INSERT, so a
	/* MyISAM table (no transactions) still gets them as a single statement.
	/*
	/* History is kept: marking an already-marked call adds a row and the newest
	/* live row is the current one (§4.3).
	/*
	/* Gate: admin only (goat_user_cohort() === 'admin') — D9, the write rule.
	/*
	/* PHP 5.x — array(), no ??, no short arrays, tabs. PDO via goat_pdo().
	*/

	$MAX_CALLS = 200;

	if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST')
	{
		goat_json_error(405, 'POST only');
		exit;
	}

	if (goat_user_cohort() !== 'admin')
	{
		goat_json_error(403, 'Admin only');
		exit;
	}

	$actorID = isset($_SESSION[SITE_KEY]['userID']) ? (int) $_SESSION[SITE_KEY]['userID'] : 0;

	if ($actorID <= 0)
	{
		goat_json_error(401, 'Not authenticated');
		exit;
	}

	/* JSON via php://input, the house convention for GOAT write endpoints. */
	$raw  = file_get_contents('php://input');
	$body = json_decode($raw, true);

	if (!is_array($body) || !isset($body['calls']) || !is_array($body['calls']))
	{
		goat_json_error(400, 'Body must be a JSON object with a calls array');
		exit;
	}

	$in = $body['calls'];

	if (!count($in))
	{
		goat_json_error(400, 'calls is empty');
		exit;
	}

	if (count($in) > $MAX_CALLS)
	{
		goat_json_error(400, 'at most ' . $MAX_CALLS . ' calls');
		exit;
	}

	/*
	/* An integer from JSON: an int, or a whole-number float / digit string.
	/* Returns null for anything else (true, "7a", 7.5, negative ...).
	*/
	function goat_crewlist_int($v)
	{
		if (is_int($v))
			return $v;
		if (is_float($v) && floor($v) == $v && abs($v) < 2147483648)
			return (int) $v;
		if (is_string($v) && preg_match('/^[0-9]+$/', $v))
			return (int) $v;
		return null;
	}

	/*
	/* ── VALIDATE AND NORMALISE every entry ───────────────────────────────────
	*/

	$rows = array();      /* call_id => normalised snapshot, in request order */

	foreach ($in as $i => $e)
	{
		$where = 'calls[' . (int) $i . ']';

		if (!is_array($e))
		{
			goat_json_error(400, $where . ' must be an object');
			exit;
		}

		$callID = isset($e['call_id']) ? goat_crewlist_int($e['call_id']) : null;

		if ($callID === null || $callID <= 0)
		{
			goat_json_error(400, $where . '.call_id must be a positive integer');
			exit;
		}

		if (isset($rows[$callID]))
		{
			goat_json_error(400, 'call ' . $callID . ' appears more than once');
			exit;
		}

		/* crew_ids -> unique ints, sorted, CSV. Empty is allowed: a call
		/* printed with nobody confirmed is still a list that was sent. */
		if (!isset($e['crew_ids']) || !is_array($e['crew_ids']))
		{
			goat_json_error(400, $where . '.crew_ids must be an array');
			exit;
		}

		$crew = array();

		foreach ($e['crew_ids'] as $u)
		{
			$u = goat_crewlist_int($u);
			if ($u === null || $u <= 0)
			{
				goat_json_error(400, $where . '.crew_ids must hold positive integers');
				exit;
			}
			$crew[$u] = true;
		}

		$crew = array_keys($crew);
		sort($crew, SORT_NUMERIC);

		$startDate = isset($e['start_date']) ? goat_crewlist_int($e['start_date']) : null;

		if ($startDate === null || $startDate <= 0)
		{
			goat_json_error(400, $where . '.start_date must be a positive unix timestamp');
			exit;
		}

		/* start_time -> HH:MM:SS */
		$t = isset($e['start_time']) && is_string($e['start_time']) ? trim($e['start_time']) : '';

		if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $t, $m)
		    || (int) $m[1] > 23 || (int) $m[2] > 59
		    || (isset($m[3]) && $m[3] !== '' && (int) $m[3] > 59))
		{
			goat_json_error(400, $where . '.start_time must be HH:MM or HH:MM:SS');
			exit;
		}

		$startTime = sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2],
		                     (isset($m[3]) && $m[3] !== '') ? (int) $m[3] : 0);

		/* est_length -> 2 dp, inside DECIMAL(6,2) */
		$len = isset($e['est_length']) ? $e['est_length'] : null;

		if (!(is_int($len) || is_float($len) || (is_string($len) && is_numeric($len)))
		    || (float) $len < 0 || (float) $len > 9999.99)
		{
			goat_json_error(400, $where . '.est_length must be a number from 0 to 9999.99');
			exit;
		}

		$rows[$callID] = array(
			'crew_ids'   => implode(',', $crew),
			'start_date' => $startDate,
			'start_time' => $startTime,
			'est_length' => sprintf('%.2f', round((float) $len, 2))
		);
	}

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		goat_json_error(503, 'Database handle unavailable');
		exit;
	}

	$callIDs = array_keys($rows);

	try
	{
		/*
		/* ── CHECK every call: exists, not cancelled, and its bookingID ──────
		/*
		/* Cancelled test guarded as in get-calls-bulk.php, through PDO.
		*/

		$hasCancelled = goat_crewlist_have_cancelled($pdo);

		$stmt = $pdo->prepare(
			"SELECT id, bookingID" . ($hasCancelled ? ", cancelled_at" : "") . "
			 FROM calls
			 WHERE id IN (" . goat_crewlist_placeholders(count($callIDs)) . ")");
		goat_crewlist_bind_ints($stmt, $callIDs, 1);
		$stmt->execute();

		$booking = array();

		while ($r = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			if ($hasCancelled && $r['cancelled_at'] !== null)
			{
				$stmt->closeCursor();
				goat_json_error(400, 'call ' . (int) $r['id'] . ' is cancelled');
				exit;
			}

			$booking[(int) $r['id']] = (int) $r['bookingID'];
		}
		$stmt->closeCursor();

		foreach ($callIDs as $id)
		{
			if (!isset($booking[$id]) || $booking[$id] <= 0)
			{
				goat_json_error(400, 'call ' . $id . ' not found');
				exit;
			}
		}

		/*
		/* ── WRITE: one multi-row INSERT ─────────────────────────────────────
		*/

		$now    = time();
		$tuples = array();

		foreach ($callIDs as $id)
			$tuples[] = '(?, ?, ?, ?, ?, ?, ?, ?)';

		$stmt = $pdo->prepare(
			"INSERT INTO call_crewlist_sent
			        (callID, bookingID, crew_ids, start_date, start_time, est_length, sent_by, sent_at)
			 VALUES " . implode(', ', $tuples));

		$pos = 1;

		foreach ($callIDs as $id)
		{
			$s = $rows[$id];
			$stmt->bindValue($pos++, (int) $id,            PDO::PARAM_INT);
			$stmt->bindValue($pos++, (int) $booking[$id],  PDO::PARAM_INT);
			$stmt->bindValue($pos++, $s['crew_ids'],       PDO::PARAM_STR);
			$stmt->bindValue($pos++, (int) $s['start_date'], PDO::PARAM_INT);
			$stmt->bindValue($pos++, $s['start_time'],     PDO::PARAM_STR);
			$stmt->bindValue($pos++, $s['est_length'],     PDO::PARAM_STR);
			$stmt->bindValue($pos++, (int) $actorID,       PDO::PARAM_INT);
			$stmt->bindValue($pos++, (int) $now,           PDO::PARAM_INT);
		}

		$stmt->execute();
	}
	catch (PDOException $e)
	{
		error_log('mark-crewlist-sent: ' . $e->getMessage());
		goat_json_error(500, 'could not record crew list sent');
		exit;
	}

	$marked = array();
	foreach ($callIDs as $id)
		$marked[] = (int) $id;

	echo json_encode(array('ok' => true, 'marked' => $marked));

?>
