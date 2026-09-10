<?php

	/*
	/* PRESENCE ONLY, FOR MANY CREW AT ONCE — what a list needs to decide
	/* whether to draw the medical icon beside a name.
	/*
	/*   POST { "user_ids": [123, 456, ...] }
	/*   ->   { ok, flags: { "123": "declared", "456": "not_answered" } }
	/*
	/* RETURNS STATE, NEVER CONTENT. No action_note, no category, no
	/* medication location ever leaves this endpoint. Opening the card is
	/* emergency-get.php, and that is the call that gets logged.
	/*
	/* THIS ENDPOINT DOES NOT WRITE TO THE ACCESS LOG, and that is a deliberate
	/* weakening rather than an oversight (design 8.2). A row per crew member
	/* per list render would write thousands of rows a day and bury the reveals
	/* that matter; a log nobody can read is not an audit trail. If it later
	/* matters who saw THAT A FLAG EXISTED, the answer is one coarse row per
	/* session, not one per render.
	/*
	/* PRESENCE IS STILL A DISCLOSURE (D7). A medical icon beside a name tells
	/* every viewer that person has something recorded, before anybody clicks.
	/* So this is scope-checked exactly like the reveal: a subject the viewer
	/* may not see comes back as 'not_answered', which is what a viewer with no
	/* right to know should see. It is not an error and must not be
	/* distinguishable from a genuine absence.
	/*
	/* PHP 5.6. JSON on every path.
	*/

	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/emergency-scope.php');
	include(dirname(__FILE__) . '/emergency-db.php');

	header('Content-Type: application/json');

	$MAX_IDS = 500;

	if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST')
	{
		goat_json_error(405, 'POST only');
		exit;
	}

	$viewerID = (int) goat_acting_user_id();

	if ($viewerID <= 0)
	{
		goat_json_error(401, 'Not authenticated');
		exit;
	}

	$body = json_decode(file_get_contents('php://input'), true);

	if (!is_array($body) || !isset($body['user_ids']) || !is_array($body['user_ids']))
	{
		goat_json_error(400, 'user_ids array required');
		exit;
	}

	/* dedupe and sanitise; every id is cast, no string reaches SQL */

	$ids = array();

	foreach ($body['user_ids'] as $raw)
	{
		$id = (int) $raw;

		if ($id > 0)
		{
			$ids[$id] = true;
		}
	}

	if (count($ids) === 0)
	{
		echo json_encode(array('ok' => true, 'flags' => array()));
		exit;
	}

	if (count($ids) > $MAX_IDS)
	{
		goat_json_error(400, 'at most ' . $MAX_IDS . ' user_ids');
		exit;
	}

	/*
	/* Scope is evaluated PER SUBJECT through goat_emergency_scope(), not
	/* short-circuited by resolving the viewer's cohort once and assuming.
	/* That would be a second implementation of the rule living here, which is
	/* exactly what D12 forbids -- and it is the shape that would silently stop
	/* matching the day rule 4 is switched on. The cost is two indexed PK
	/* lookups per subject inside goat_cohort_for_user(); at the 500-id cap
	/* that is a thousand sub-millisecond reads, which is a fair price for one
	/* definition of who may see what.
	*/

	$allowed = array();

	foreach (array_keys($ids) as $subjectID)
	{
		$d = goat_emergency_scope($viewerID, $subjectID);

		if ($d['allowed'])
		{
			$allowed[] = (int) $subjectID;
		}
	}

	/* Everything starts at not_answered, INCLUDING subjects out of scope --
	/* see the header note. Absence and refusal look identical on purpose. */

	$flags = array();

	foreach (array_keys($ids) as $id)
	{
		$flags[(string) $id] = 'not_answered';
	}

	if (count($allowed) > 0)
	{
		$pdo = goat_emergency_pdo();

		if ($pdo === null)
		{
			goat_json_error(503, 'Emergency card storage is unavailable');
			exit;
		}

		try
		{
			$in = implode(',', array_fill(0, count($allowed), '?'));

			$st = $pdo->prepare(
				'SELECT user_id, state FROM crew_emergency_info
				  WHERE user_id IN (' . $in . ')');
			$st->execute($allowed);

			while ($row = $st->fetch())
			{
				$flags[(string) (int) $row['user_id']] = $row['state'];
			}
		}
		catch (PDOException $e)
		{
			error_log('emergency-flags: read failed for viewer ' . $viewerID . ': ' . $e->getMessage());
			goat_json_error(500, 'Could not load emergency flags');
			exit;
		}
	}

	echo json_encode(array('ok' => true, 'flags' => $flags));
