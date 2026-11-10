<?php

	/*
	/* READ ONE CREW MEMBER'S DIETARY REQUIREMENTS.
	/*
	/*   GET ?subject=<userID>
	/*
	/* Allowed when the subject IS the caller (Crew Hub's own page, or anybody's
	/* own record), or when the caller's SESSION cohort is admin or operations
	/* (THE GOAT). See goat_dietary_can_view_others() for why the cohort is only
	/* ever read from the session and never from the service-key path's
	/* asserted id.
	/*
	/* NO VIEWING LOG (D9). Unlike the emergency card there is no reveal to
	/* record: the crew list prints this for the client's caterer by design.
	/*
	/* PHP 5.6. JSON on every path.
	*/

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/dietary-lib.php');

	header('Content-Type: application/json');

	$viewerID = (int) goat_acting_user_id();

	if ($viewerID <= 0)
	{
		goat_json_error(401, 'Not authenticated');
		exit;
	}

	$subjectID = 0;

	if (isset($_GET['subject']))
	{
		$subjectID = (int) $_GET['subject'];
	}

	if ($subjectID <= 0)
	{
		goat_json_error(400, 'subject required');
		exit;
	}

	if ($subjectID !== $viewerID && !goat_dietary_can_view_others())
	{
		goat_json_error(403, 'Not permitted');
		exit;
	}

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		goat_json_error(503, 'Dietary storage is unavailable');
		exit;
	}

	$row = null;

	try
	{
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
	}
	catch (PDOException $e)
	{
		error_log('dietary-get: read failed for subject ' . $subjectID . ': ' . $e->getMessage());
		goat_json_error(500, 'Could not load dietary requirements');
		exit;
	}

	echo json_encode(goat_dietary_payload($subjectID, $row));
