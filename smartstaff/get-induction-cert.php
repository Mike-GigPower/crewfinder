<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* SELF endpoint — streams ONE of the acting user's own induction
	/* certificates from user_uploads/. The cert PDFs are session-gated on
	/* SmartStaff, so the portal proxies through here with the service key
	/* rather than hotlinking.
	/*
	/* Ownership is enforced two ways: the filename is whitelisted to
	/* {digits}_{digits}.pdf via basename + regex (no path traversal), and a
	/* crew_venue_induction row must exist for this crew_id + file.
	*/

	$crewID = (int) goat_acting_user_id();

	if ($crewID <= 0)
	{
		http_response_code(403);
		header('Content-Type: application/json');
		die('{"error":"not authorised"}');
	}

	$file = isset($_GET['file']) ? basename($_GET['file']) : '';

	if (!preg_match('/^[0-9]+_[0-9]+\.pdf$/', $file))
	{
		http_response_code(400);
		header('Content-Type: application/json');
		die('{"error":"bad filename"}');
	}

	$fileEsc = mysql_real_escape_string($file);

	$res = mysql_query(
		"SELECT 1 FROM crew_venue_induction
		 WHERE crew_id = " . $crewID . " AND file = '" . $fileEsc . "' LIMIT 1"
	);

	if ($res === false || mysql_num_rows($res) == 0)
	{
		http_response_code(403);
		header('Content-Type: application/json');
		die('{"error":"not your certificate"}');
	}

	$path = BASEPATH . 'user_uploads/' . $file;

	if (!is_file($path))
	{
		http_response_code(404);
		header('Content-Type: application/json');
		die('{"error":"certificate not found"}');
	}

	header('Content-Type: application/pdf');
	header('Content-Length: ' . filesize($path));
	header('Content-Disposition: inline; filename="induction-certificate.pdf"');
	header('X-Content-Type-Options: nosniff');
	readfile($path);
	exit;

?>
