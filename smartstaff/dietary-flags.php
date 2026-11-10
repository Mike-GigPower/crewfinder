<?php

	/*
	/* DIETARY REQUIREMENTS FOR MANY CREW AT ONCE -- the header icon, the Booked
	/* Crew icons and the crew list's Dietary column.
	/*
	/*   POST { "user_ids": [ ... ] }
	/*   ->   { ok, flags: { "123": { state, options:[codes], note, consented } } }
	/*
	/* Ids with no row are ABSENT; the caller treats absent as not_answered.
	/*
	/* UNLIKE emergency-flags.php THIS RETURNS CONTENT. There is no reveal to
	/* protect (D9 -- no viewing log) and the crew list needs the text to print.
	/*
	/* Admin + operations SESSIONS only (D10), via goat_dietary_can_view_others().
	/* Leadership gets 403, which THE GOAT reads as "no icons" and "couldn't be
	/* loaded" on the crew list -- the intended outcome, not a fault.
	/*
	/* PHP 5.6. JSON on every path.
	*/

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/dietary-lib.php');

	header('Content-Type: application/json');

	$MAX_IDS = 500;

	if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST')
	{
		goat_json_error(405, 'POST only');
		exit;
	}

	if (goat_user_cohort() === null)
	{
		goat_json_error(401, 'Not authenticated');
		exit;
	}

	if (!goat_dietary_can_view_others())
	{
		goat_json_error(403, 'Not permitted');
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

	if (count($ids) > $MAX_IDS)
	{
		goat_json_error(400, 'at most ' . $MAX_IDS . ' user_ids');
		exit;
	}

	$flags = array();

	if (count($ids) > 0)
	{
		$pdo = goat_pdo();

		if ($pdo === null)
		{
			goat_json_error(503, 'Dietary storage is unavailable');
			exit;
		}

		try
		{
			$list = array_keys($ids);
			$in   = implode(',', array_fill(0, count($list), '?'));

			$st = $pdo->prepare(
				'SELECT user_id, state, options, note, consent_at
				   FROM crew_dietary
				  WHERE user_id IN (' . $in . ')');
			$st->execute($list);

			while ($row = $st->fetch())
			{
				$state = (string) $row['state'];

				$flags[(string) (int) $row['user_id']] = array(
					'state'     => $state,
					'options'   => ($state === 'declared') ? goat_dietary_split($row['options']) : array(),
					'note'      => ($state === 'declared' && $row['note'] !== null && $row['note'] !== '') ? (string) $row['note'] : null,
					'consented' => ($row['consent_at'] !== null)
				);
			}
		}
		catch (PDOException $e)
		{
			error_log('dietary-flags: read failed: ' . $e->getMessage());
			goat_json_error(500, 'Could not load dietary requirements');
			exit;
		}
	}

	/* An empty PHP array encodes as [] -- send {} so the shape never changes. */

	echo json_encode(array(
		'ok'    => true,
		'flags' => (count($flags) > 0) ? $flags : new stdClass()
	));
