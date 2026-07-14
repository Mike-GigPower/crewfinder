<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* SELF endpoint — stream ONE of the LOGGED-IN / service-asserted user's OWN
	/* licence files (PDF, or a legacy image) by row id, for Crew Hub "My Licences"
	/* View.
	/*
	/* Self-scoped + OWNERSHIP-checked: the acting user comes from
	/* goat_acting_user_id() (a SmartStaff session, OR the service key + the
	/* backend-asserted userID), and the row is served ONLY if its `user` matches.
	/* Passing another crew member's id returns 404 — never their file. The admin
	/* endpoint does not need this (admin may view anyone); a self-scoped surface
	/* MUST have it (this is the IDOR the native user-add-license.php carries and
	/* the handover flags).
	/*
	/* Mirrors admin-get-license-file.php otherwise: anti-traversal via basename +
	/* strict {digits}_{digits}.pdf whitelist, a venue guard so an induction file
	/* can never be pulled through the licence surface, readfile with nosniff.
	*/

	$actingUser = (int) goat_acting_user_id();  /* emits JSON + exits on failure */

	/*
	/* validate id. */

	$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

	if ($id <= 0)
	{
		http_response_code(400);
		header('Content-Type: application/json');
		die('{"error":"missing id"}');
	}

	/*
	/* load the row (incl. its owner). */

	$res = mysql_query(
		"SELECT `user`, pdf_file, has_image, type, venue
		 FROM user_licenses WHERE id = " . $id . " LIMIT 1"
	);

	if ($res === false || mysql_num_rows($res) == 0)
	{
		http_response_code(404);
		header('Content-Type: application/json');
		die('{"error":"not found"}');
	}

	$row = mysql_fetch_object($res);

	/*
	/* OWNERSHIP guard — the row must belong to the acting user. 404 (not 403) so
	/* we don't reveal that another crew member's id exists. */
	if ((int) $row->user !== $actingUser)
	{
		http_response_code(404);
		header('Content-Type: application/json');
		die('{"error":"not found"}');
	}

	/*
	/* licence guard — reject inductions. A SET `venue` marks an induction (native
	/* ones are typed by venue+year, not 'Induction Certificate'), so !empty(venue)
	/* is the real test; the type string stays as extra cover. */
	if (!empty($row->venue) || $row->type === 'Induction Certificate')
	{
		http_response_code(403);
		header('Content-Type: application/json');
		die('{"error":"not a licence"}');
	}

	/*
	/* PDF branch — anti-traversal via basename + strict {digits}_{digits}.pdf. */

	if ($row->pdf_file !== null && $row->pdf_file !== '')
	{
		$file = basename($row->pdf_file);

		if (!preg_match('/^[0-9]+_[0-9]+\.pdf$/', $file))
		{
			http_response_code(400);
			header('Content-Type: application/json');
			die('{"error":"bad filename"}');
		}

		$path = BASEPATH . 'user_uploads/' . $file;

		if (!is_file($path))
		{
			http_response_code(404);
			header('Content-Type: application/json');
			die('{"error":"file not found"}');
		}

		header('Content-Type: application/pdf');
		header('Content-Length: ' . filesize($path));
		header('Content-Disposition: inline; filename="licence.pdf"');
		header('X-Content-Type-Options: nosniff');
		readfile($path);
		exit;
	}

	/*
	/* legacy image branch — serve the large licensepics jpg for a pre-PDF licence. */

	if ((int) $row->has_image == 1)
	{
		$path = BASEPATH . 'images/licensepics/licenseimg_large_' . $id . '.jpg';

		if (!is_file($path))
		{
			http_response_code(404);
			header('Content-Type: application/json');
			die('{"error":"file not found"}');
		}

		header('Content-Type: image/jpeg');
		header('Content-Length: ' . filesize($path));
		header('Content-Disposition: inline; filename="licence.jpg"');
		header('X-Content-Type-Options: nosniff');
		readfile($path);
		exit;
	}

	/*
	/* nothing to serve. */

	http_response_code(404);
	header('Content-Type: application/json');
	die('{"error":"no file"}');

?>
