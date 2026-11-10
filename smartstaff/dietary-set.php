<?php

	/*
	/* WRITE ONE CREW MEMBER'S DIETARY REQUIREMENTS.
	/*
	/*   POST { "subject": 123, "state": "declared",
	/*          "options": ["vegetarian","allergy"], "note": "Peanuts",
	/*          "source": "goat" }
	/*   POST { "subject": 123, "state": "none" }
	/*   POST { "subject": 123, "confirm": true }           self path only
	/*
	/* TWO PATHS, CHOSEN BY HOW THE CALLER AUTHENTICATED -- never by anything in
	/* the body:
	/*
	/*   OPS   a SmartStaff SESSION whose cohort is admin or operations (THE
	/*         GOAT, and convert). Any subject, including the operator's own
	/*         record -- an operator editing themselves in THE GOAT is still the
	/*         office entering it, so it is stamped 'goat' and NOT consented.
	/*         updated_by is the session user. updated_source is 'goat', or
	/*         'apply' when convert says so. consent_at is KEPT as it was (Q8);
	/*         with none it stays NULL, except that an 'apply' write with a
	/*         consent_at in the body stores it as given -- the candidate
	/*         consented on the application form.
	/*
	/*   SELF  NO session, the Crew API service key + asserted userID (Crew
	/*         Hub, via goat_acting_user_id()). The subject MUST be that id.
	/*         Stamps consent_at = now, updated_source = 'crewhub'. A bare
	/*         { confirm: true } stamps consent_at and changes nothing else, so
	/*         "Updated by Gig Power office" still reads true afterwards (D11).
	/*
	/*   A session whose cohort is NOT admin/operations is refused outright.
	/*   The cohort is read with goat_user_cohort() -- the SESSION -- and never
	/*   derived from an asserted id. See goat_dietary_can_view_others().
	/*
	/* PHP 5.6 -- array(), no ??, no short arrays. JSON on EVERY path.
	*/

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/dietary-lib.php');

	header('Content-Type: application/json');

	$MAX_NOTE = 300;

	if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST')
	{
		goat_json_error(405, 'POST only');
		exit;
	}

	/* ---- who is writing, and on which path ---- */

	$sessionCohort = goat_user_cohort();   /* null when there is no session */

	if ($sessionCohort !== null)
	{
		if ($sessionCohort !== 'admin' && $sessionCohort !== 'operations')
		{
			goat_json_error(403, 'Not permitted');
			exit;
		}

		$path    = 'ops';
		$actorID = (int) $_SESSION[SITE_KEY]['userID'];
	}
	else
	{
		/* Service key + asserted userID, or a 401 and exit. */
		$path    = 'self';
		$actorID = (int) goat_acting_user_id();
	}

	if ($actorID <= 0)
	{
		goat_json_error(401, 'Not authenticated');
		exit;
	}

	$body = json_decode(file_get_contents('php://input'), true);

	if (!is_array($body))
	{
		goat_json_error(400, 'Body must be a JSON object');
		exit;
	}

	$subjectID = isset($body['subject']) ? (int) $body['subject'] : 0;

	if ($subjectID <= 0)
	{
		goat_json_error(400, 'subject required');
		exit;
	}

	if ($path === 'self' && $subjectID !== $actorID)
	{
		goat_json_error(403, 'Not permitted');
		exit;
	}

	$confirmOnly = (isset($body['confirm']) && $body['confirm'] === true);

	if ($confirmOnly && $path !== 'self')
	{
		goat_json_error(400, 'Only the crew member can confirm their own dietary requirements');
		exit;
	}

	/* ---- validate the content (not for a bare confirm) ---- */

	$state    = '';
	$optsOut  = null;
	$noteOut  = null;

	if (!$confirmOnly)
	{
		$state = isset($body['state']) ? trim((string) $body['state']) : '';

		if ($state !== 'declared' && $state !== 'none' && $state !== 'not_answered')
		{
			goat_json_error(400, 'state must be declared, none or not_answered');
			exit;
		}

		if ($state === 'declared')
		{
			$vocab = goat_dietary_options();
			$in    = (isset($body['options']) && is_array($body['options'])) ? $body['options'] : array();
			$have  = array();

			foreach ($in as $c)
			{
				if (!is_string($c) || !isset($vocab[$c]))
				{
					goat_json_error(400, 'unknown dietary option: ' . (is_scalar($c) ? (string) $c : '?'));
					exit;
				}

				$have[$c] = true;
			}

			if (count($have) === 0)
			{
				goat_json_error(400, 'Tick at least one option, or choose No requirements');
				exit;
			}

			$note = isset($body['note']) ? trim((string) $body['note']) : '';

			/* Checked HERE rather than left to the column: outside strict mode
			/* MariaDB would silently truncate. */

			if (goat_dietary_len($note) > $MAX_NOTE)
			{
				goat_json_error(400, 'The note is longer than ' . $MAX_NOTE . ' characters');
				exit;
			}

			if ($note === '')
			{
				foreach (goat_dietary_note_required() as $needs)
				{
					if (isset($have[$needs]))
					{
						goat_json_error(400, 'Add a note saying what the ' . strtolower($vocab[$needs]) . ' is');
						exit;
					}
				}
			}

			/* Stored in vocabulary order, whatever order they arrived in. */

			$sorted = array();

			foreach (array_keys($vocab) as $code)
			{
				if (isset($have[$code]))
				{
					$sorted[] = $code;
				}
			}

			$optsOut = implode(',', $sorted);
			$noteOut = ($note === '') ? null : $note;
		}

		/* none / not_answered clear options and note (both stay null). */
	}

	/* ---- source and the consent value the body may carry ---- */

	$source       = ($path === 'self') ? 'crewhub' : 'goat';
	$applyConsent = null;

	if ($path === 'ops' && isset($body['source']) && $body['source'] === 'apply')
	{
		$source = 'apply';

		if (isset($body['consent_at']) && $body['consent_at'] !== null && $body['consent_at'] !== '')
		{
			$ts = strtotime((string) $body['consent_at']);

			if ($ts === false || $ts <= 0)
			{
				goat_json_error(400, 'consent_at is not a date');
				exit;
			}

			$applyConsent = date('Y-m-d H:i:s', $ts);
		}
	}

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		goat_json_error(503, 'Dietary storage is unavailable');
		exit;
	}

	$now = date('Y-m-d H:i:s');
	$row = null;

	try
	{
		$pdo->beginTransaction();

		/* FOR UPDATE: the kept consent_at must be the one this write replaces,
		/* not one a concurrent Crew Hub confirm has just changed. */

		$st = $pdo->prepare(
			'SELECT user_id, state, options, note, consent_at, updated_at,
			        updated_by, updated_source
			   FROM crew_dietary
			  WHERE user_id = ?
			    FOR UPDATE');
		$st->execute(array($subjectID));
		$existing = $st->fetch();

		if (!is_array($existing))
		{
			$existing = null;
		}

		if ($confirmOnly)
		{
			if ($existing === null || $existing['state'] === 'not_answered')
			{
				$pdo->rollBack();
				goat_json_error(409, 'There is nothing to confirm yet');
				exit;
			}

			$st = $pdo->prepare('UPDATE crew_dietary SET consent_at = ? WHERE user_id = ?');
			$st->execute(array($now, $subjectID));
		}
		else
		{
			if ($path === 'self')
			{
				$consent = $now;
			}
			else if ($applyConsent !== null)
			{
				$consent = $applyConsent;
			}
			else
			{
				/* Q8: an ops edit keeps an existing consent_at; none stays null. */
				$consent = ($existing !== null) ? $existing['consent_at'] : null;
			}

			$st = $pdo->prepare(
				'INSERT INTO crew_dietary
				   (user_id, state, options, note, consent_at,
				    updated_at, updated_by, updated_source)
				 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
				 ON DUPLICATE KEY UPDATE
				   state          = VALUES(state),
				   options        = VALUES(options),
				   note           = VALUES(note),
				   consent_at     = VALUES(consent_at),
				   updated_at     = VALUES(updated_at),
				   updated_by     = VALUES(updated_by),
				   updated_source = VALUES(updated_source)');
			$st->execute(array(
				$subjectID,
				$state,
				$optsOut,
				$noteOut,
				$consent,
				$now,
				$actorID,
				$source
			));
		}

		$st = $pdo->prepare(
			'SELECT user_id, state, options, note, consent_at, updated_at,
			        updated_by, updated_source
			   FROM crew_dietary
			  WHERE user_id = ?');
		$st->execute(array($subjectID));
		$found = $st->fetch();

		if (is_array($found))
		{
			$row = $found;
		}

		$pdo->commit();
	}
	catch (PDOException $e)
	{
		if ($pdo->inTransaction())
		{
			$pdo->rollBack();
		}

		/* Never return the driver message: it can echo the submitted note. */

		error_log('dietary-set: write failed for subject ' . $subjectID . ': ' . $e->getMessage());
		goat_json_error(500, 'Could not save dietary requirements');
		exit;
	}

	echo json_encode(goat_dietary_payload($subjectID, $row));
