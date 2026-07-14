<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* ADMIN endpoint — stream ONE licence's file (PDF, or a legacy image) by row
	/* id, for the "View" control in THE GOAT's Manage Crew -> Licences tab.
	/*
	/* Mirrors get-induction-cert.php (anti-traversal via basename + strict regex,
	/* readfile with inline headers + nosniff) but is id-based and admin-gated
	/* rather than session-scoped. It NEVER serves an Induction Certificate — those
	/* have their own endpoint — so an induction file can't be pulled through the
	/* licence surface.
	*/

	/*
	/* 1. gate — admin only. */

	if (goat_user_cohort() !== 'admin')
	{
		http_response_code(403);
		header('Content-Type: application/json');
		die('{"error":"forbidden"}');
	}

	/*
	/* 2. validate id. */

	$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

	if ($id <= 0)
	{
		http_response_code(400);
		header('Content-Type: application/json');
		die('{"error":"missing id"}');
	}

	/*
	/* 3. load the row + guard it is a licence (never an induction). */

	$res = mysql_query(
		"SELECT pdf_file, has_image, type, venue
		 FROM user_licenses WHERE id = " . $id . " LIMIT 1"
	);

	if ($res === false || mysql_num_rows($res) == 0)
	{
		http_response_code(404);
		header('Content-Type: application/json');
		die('{"error":"not found"}');
	}

	$row = mysql_fetch_object($res);

	/* Reject inductions. A SET `venue` marks an induction (native ones are typed
	/* by venue+year, not 'Induction Certificate'), so !empty(venue) is the real
	/* test; the type string stays as extra cover. This keeps induction certs from
	/* being pulled through the licence file surface. */
	if (!empty($row->venue) || $row->type === 'Induction Certificate')
	{
		http_response_code(403);
		header('Content-Type: application/json');
		die('{"error":"not a licence"}');
	}

	/*
	/* 4. PDF branch — anti-traversal via basename + strict {digits}_{digits}.pdf
	/* whitelist, exactly like get-induction-cert.php. */

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
	/* 5. legacy image branch — serve the large licensepics jpg for a pre-PDF
	/* licence. (Drop this branch if no image licences exist; see BRIEF section 1.) */

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
	/* 6. nothing to serve. */

	http_response_code(404);
	header('Content-Type: application/json');
	die('{"error":"no file"}');

?>
