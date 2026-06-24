<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SELF endpoint — record one or more completed inductions for the
	/* logged-in / asserted user, each carrying the SAME uploaded certificate.
	/*
	/* Mirrors the crew "Add Induction" handler (add-induction.php):
	/*   - PDF saved to BASEPATH.'user_uploads/' as {crew_id}_{time}.pdf
	/*   - a user_licenses row (type 'Induction Certificate') per venue
	/*   - crew_venue_induction replaced (delete + insert) per venue
	/* complete_date is parsed with strtotime() (accepts ISO or 'dd-M-yy').
	/*
	/* venue_ids: comma-separated venue ids. One id for a single venue, or
	/* several for a grouped induction (e.g. Melbourne Park = its five arenas):
	/* the single cert is stored ONCE and every venue row points at it.
	/*
	/* Trust-based, exactly like SmartStaff's own form: a present 'confirmation'
	/* plus a supplied cert is taken as the crew member's attestation and the
	/* venue goes straight to Complete. An ops-approval phase is deferred
	/* (see backlog).
	*/

	$crewID = (int) goat_acting_user_id();

	if ($crewID <= 0)
	{
		http_response_code(403);
		die('{"error":"not authorised"}');
	}

	/*
	/* require confirmation + a completion date */

	if (!isset($_POST['confirmation']) || $_POST['confirmation'] == '')
	{
		http_response_code(400);
		die('{"error":"confirmation required"}');
	}

	if (!isset($_POST['complete_date']) || trim($_POST['complete_date']) == '')
	{
		http_response_code(400);
		die('{"error":"complete_date required"}');
	}

	$completeTs = strtotime($_POST['complete_date']);

	if ($completeTs === false || $completeTs <= 0)
	{
		http_response_code(400);
		die('{"error":"complete_date not understood"}');
	}

	/*
	/* parse venue ids (comma-separated) */

	$venueIds = array();
	$rawIds   = isset($_POST['venue_ids']) ? $_POST['venue_ids'] : '';
	$parts    = explode(',', $rawIds);

	foreach ($parts as $p)
	{
		$vid = (int) trim($p);
		if ($vid > 0)
		{
			$venueIds[] = $vid;
		}
	}

	if (count($venueIds) == 0)
	{
		http_response_code(400);
		die('{"error":"venue_ids required"}');
	}

	/*
	/* validate the ids are real, active induction venues */

	$inClause = implode(',', $venueIds);

	$vRes = mysql_query(
		"SELECT id FROM venues
		 WHERE active = 1 AND has_induction = 1 AND id IN (" . $inClause . ")"
	);

	if ($vRes === false)
	{
		http_response_code(500);
		die('{"error":"venue check failed: ' . addslashes(mysql_error()) . '"}');
	}

	$validIds = array();

	while ($vrow = mysql_fetch_object($vRes))
	{
		$validIds[] = (int) $vrow->id;
	}

	if (count($validIds) == 0)
	{
		http_response_code(400);
		die('{"error":"no valid induction venues in request"}');
	}

	/*
	/* require a PDF certificate (extension + %PDF- magic bytes; the browser
	/* MIME type is spoofable so it is not trusted) */

	if (!isset($_FILES['certificate']) || !is_uploaded_file($_FILES['certificate']['tmp_name']))
	{
		http_response_code(400);
		die('{"error":"certificate file required"}');
	}

	$ext = strtolower(pathinfo($_FILES['certificate']['name'], PATHINFO_EXTENSION));

	if ($ext != 'pdf')
	{
		http_response_code(400);
		die('{"error":"certificate must be a PDF"}');
	}

	$head = '';
	$fh   = fopen($_FILES['certificate']['tmp_name'], 'rb');

	if ($fh)
	{
		$head = fread($fh, 5);
		fclose($fh);
	}

	if ($head != '%PDF-')
	{
		http_response_code(400);
		die('{"error":"certificate is not a valid PDF"}');
	}

	$maxBytes = 10 * 1024 * 1024;

	if ((int) $_FILES['certificate']['size'] > $maxBytes)
	{
		http_response_code(400);
		die('{"error":"certificate too large (max 10MB)"}');
	}

	/*
	/* save the cert once, shared across every venue in this submission */

	$targetdir = BASEPATH . 'user_uploads/';

	if (!is_dir($targetdir))
	{
		@mkdir($targetdir, 0775, true);
	}

	$targetname = $crewID . '_' . time() . '.pdf';
	$targetfile = $targetdir . $targetname;

	if (!move_uploaded_file($_FILES['certificate']['tmp_name'], $targetfile))
	{
		http_response_code(500);
		die('{"error":"certificate could not be stored"}');
	}

	$fileEsc = mysql_real_escape_string($targetname);

	/*
	/* write one induction (and one licence) per valid venue. The user_licenses
	/* row is best-effort (keeps the cert visible under My Licenses); the
	/* induction row is the one we gate on. */

	$written = array();

	foreach ($validIds as $vid)
	{
		mysql_query(
			"INSERT INTO user_licenses (user, venue, type, pdf_file, has_image)
			 VALUES (" . $crewID . ", " . $vid . ", 'Induction Certificate', '" . $fileEsc . "', 0)"
		);

		mysql_query(
			"DELETE FROM crew_venue_induction
			 WHERE crew_id = " . $crewID . " AND venue_id = " . $vid
		);

		mysql_query(
			"INSERT INTO crew_venue_induction (crew_id, venue_id, complete_date, file)
			 VALUES (" . $crewID . ", " . $vid . ", " . (int) $completeTs . ", '" . $fileEsc . "')"
		);

		if (mysql_error())
		{
			http_response_code(500);
			die('{"error":"induction write failed for venue ' . $vid . ': ' . addslashes(mysql_error()) . '"}');
		}

		$written[] = $vid;
	}

	echo json_encode(array(
		'ok'        => true,
		'file'      => $targetname,
		'venues'    => $written,
		'completed' => date('d M Y', (int) $completeTs)
	));

?>
