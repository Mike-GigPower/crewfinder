<?php

	/*
	/* THE CREW MEMBER WRITES THEIR OWN EMERGENCY CARD. Self-scope only --
	/* there is no path through this file to anybody else's row.
	/*
	/* D4: OPS CANNOT EDIT A CARD. Accuracy and consent come from the same
	/* decision; an operator transcribing somebody's condition off a phone call
	/* produces an inaccurate medical instruction with nobody's name on it. Ops
	/* may ask a crew member to fix their card. Ops may not fix it.
	/*
	/*   POST { "state": "nothing_to_declare" }
	/*   POST { "state": "declared", "entries": [ { category, action_note,
	/*                                              medication_location? }, ... ] }
	/*   POST { "state": "withdrawn" }        deletes the row outright
	/*
	/* Identity comes from goat_acting_user_id() -- the established helper for
	/* SELF-SCOPED crew endpoints, which resolves a SmartStaff session on the
	/* GOAT path and the service-key + asserted userID on the Crew Hub path.
	/* That is exactly what it is for. (The call-supervision.php warning about
	/* this helper concerns stamping an AUDIT column on an ADMIN endpoint from
	/* a caller-supplied id -- a different situation. Here the id IS the scope,
	/* so a wrong id writes to the wrong person's own row rather than granting
	/* a read of somebody else's.)
	/*
	/* PHP 5.6 -- array(), no ??, no short arrays. JSON on EVERY path including
	/* errors, so a caller never sees "Unexpected token '<'".
	*/

	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/emergency-db.php');

	header('Content-Type: application/json');

	/*
	/* mbstring is not guaranteed on this box and php -l would not catch its
	/* absence -- an undefined mb_strlen() is a FATAL at request time, on the
	/* one path that validates a medical instruction's length. Fall back to
	/* strlen(), which counts bytes rather than characters: stricter for
	/* multibyte input, never looser, which is the right direction for a cap.
	*/

	if (!function_exists('goat_emergency_len'))
	{
		function goat_emergency_len($s)
		{
			return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
		}
	}

	$MAX_ENTRIES     = 10;
	$MAX_NOTE        = 500;
	$MAX_MEDICATION  = 200;

	if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST')
	{
		goat_json_error(405, 'POST only');
		exit;
	}

	$userID = (int) goat_acting_user_id();

	if ($userID <= 0)
	{
		goat_json_error(401, 'Not authenticated');
		exit;
	}

	$raw  = file_get_contents('php://input');
	$body = json_decode($raw, true);

	if (!is_array($body))
	{
		goat_json_error(400, 'Body must be a JSON object');
		exit;
	}

	$state = isset($body['state']) ? trim((string) $body['state']) : '';

	if ($state !== 'nothing_to_declare' && $state !== 'declared' && $state !== 'withdrawn')
	{
		goat_json_error(400, 'state must be nothing_to_declare, declared or withdrawn');
		exit;
	}

	/*
	/* not_answered is DELIBERATELY not settable. It is the absence of a row,
	/* not a choice somebody makes -- that is the whole point of D3's three
	/* states. Withdrawing deletes the row and returns them to it.
	*/

	$entries = array();

	if ($state === 'declared')
	{
		$in = isset($body['entries']) && is_array($body['entries']) ? $body['entries'] : array();

		if (count($in) === 0)
		{
			goat_json_error(400, 'declared requires at least one entry');
			exit;
		}

		if (count($in) > $MAX_ENTRIES)
		{
			goat_json_error(400, 'at most ' . $MAX_ENTRIES . ' entries');
			exit;
		}

		$valid = goat_emergency_categories();
		$seq   = 0;

		foreach ($in as $e)
		{
			if (!is_array($e))
			{
				goat_json_error(400, 'each entry must be an object');
				exit;
			}

			$cat  = isset($e['category'])    ? trim((string) $e['category'])    : '';
			$note = isset($e['action_note']) ? trim((string) $e['action_note']) : '';
			$med  = isset($e['medication_location'])
			      ? trim((string) $e['medication_location']) : '';

			if (!in_array($cat, $valid, true))
			{
				goat_json_error(400, 'unknown category: ' . $cat);
				exit;
			}

			/*
			/* Length checked HERE rather than left to the column. Outside
			/* strict mode MariaDB would silently truncate, and a silently
			/* shortened instruction about an auto-injector is the worst
			/* possible way for this field to fail.
			*/

			if ($note === '')
			{
				goat_json_error(400, 'action_note is required for every entry');
				exit;
			}

			if (goat_emergency_len($note) > $MAX_NOTE)
			{
				goat_json_error(400, 'action_note is longer than ' . $MAX_NOTE . ' characters');
				exit;
			}

			if (goat_emergency_len($med) > $MAX_MEDICATION)
			{
				goat_json_error(400, 'medication_location is longer than ' . $MAX_MEDICATION . ' characters');
				exit;
			}

			$entries[] = array(
				'category'            => $cat,
				'action_note'         => $note,
				'medication_location' => ($med === '' ? null : $med),
				'sort_order'          => $seq
			);

			$seq++;
		}
	}

	$pdo = goat_emergency_pdo();

	if ($pdo === null)
	{
		goat_json_error(503, 'Emergency card storage is unavailable');
		exit;
	}

	$now = date('Y-m-d H:i:s');

	try
	{
		$pdo->beginTransaction();

		if ($state === 'withdrawn')
		{
			/* ON DELETE CASCADE takes the entries. The ACCESS LOG SURVIVES --
			/* it has no foreign key precisely so withdrawing cannot destroy
			/* the record of who looked. */

			$st = $pdo->prepare('DELETE FROM crew_emergency_info WHERE user_id = ?');
			$st->execute(array($userID));
		}
		else
		{
			$st = $pdo->prepare(
				'INSERT INTO crew_emergency_info
				   (user_id, state, consent_at, created_at, updated_at, updated_by)
				 VALUES (?, ?, ?, ?, ?, ?)
				 ON DUPLICATE KEY UPDATE
				   state      = VALUES(state),
				   consent_at = VALUES(consent_at),
				   updated_at = VALUES(updated_at),
				   updated_by = VALUES(updated_by)');

			/* consent_at is re-stamped on every save, not only the first. The
			/* form shows who can see the card each time it is submitted, so
			/* each save is a fresh act of consent rather than a reference back
			/* to one made months ago. */

			$st->execute(array($userID, $state, $now, $now, $now, $userID));

			$st = $pdo->prepare('DELETE FROM crew_emergency_entry WHERE user_id = ?');
			$st->execute(array($userID));

			if ($state === 'declared')
			{
				$st = $pdo->prepare(
					'INSERT INTO crew_emergency_entry
					   (user_id, category, action_note, medication_location,
					    sort_order, created_at)
					 VALUES (?, ?, ?, ?, ?, ?)');

				foreach ($entries as $e)
				{
					$st->execute(array(
						$userID,
						$e['category'],
						$e['action_note'],
						$e['medication_location'],
						$e['sort_order'],
						$now
					));
				}
			}
		}

		$pdo->commit();
	}
	catch (PDOException $e)
	{
		if ($pdo->inTransaction())
		{
			$pdo->rollBack();
		}

		/* Never return the driver message: it can echo the submitted values,
		/* which here are health information. */

		error_log('emergency-set: write failed for user ' . $userID . ': ' . $e->getMessage());
		goat_json_error(500, 'Could not save the emergency card');
		exit;
	}

	echo json_encode(array(
		'ok'         => true,
		'state'      => ($state === 'withdrawn' ? 'not_answered' : $state),
		'entries'    => count($entries),
		'updated_at' => $now
	));
