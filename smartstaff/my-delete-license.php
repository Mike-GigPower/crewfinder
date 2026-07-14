<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SELF endpoint — delete ONE of the LOGGED-IN / service-asserted user's OWN
	/* licence rows from user_licenses (Crew Hub "My Licences" → Delete), then clean
	/* up its file(s).
	/*
	/* TWO guards, both mandatory for a self-scoped surface:
	/*   1. OWNERSHIP — the row's `user` must equal goat_acting_user_id(); otherwise
	/*      404 (a crew member can never delete anyone else's licence, even by id).
	/*   2. LICENCE (not induction) — a SET `venue` marks an induction (native ones
	/*      are typed by venue+year, not 'Induction Certificate'), so !empty(venue)
	/*      is the real test; the type string is kept as extra cover. This is the
	/*      data-loss path the handover flags: without it, Delete on a native
	/*      induction row would drop it and unlink its cert.
	/*
	/* Licence PDFs are per-row unique {user}_{time}.pdf (not shared like induction
	/* certs), so unlinking on delete is safe. Legacy image licences also drop their
	/* two licensepics jpgs. Each unlink is guarded so a missing file never errors.
	/*
	/* Mirrors admin-delete-license.php, plus the ownership check and the
	/* self-scoped gate.
	*/

	$actingUser = (int) goat_acting_user_id();  /* emits JSON + exits on failure */

	/*
	/* validate id. */

	$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

	if ($id <= 0)
	{
		http_response_code(400);
		die('{"ok":false,"error":"missing id"}');
	}

	/*
	/* load the row (incl. its owner). */

	$loadRes = mysql_query(
		"SELECT id, `user`, type, venue, pdf_file, has_image
		 FROM user_licenses WHERE id = " . $id . " LIMIT 1"
	);

	if ($loadRes === false)
	{
		http_response_code(500);
		die('{"ok":false,"error":"read failed"}');
	}

	if (mysql_num_rows($loadRes) == 0)
	{
		http_response_code(404);
		die('{"ok":false,"error":"not found"}');
	}

	$row       = mysql_fetch_object($loadRes);
	$rowUser   = (int) $row->user;
	$rowType   = $row->type;
	$rowVenue  = $row->venue;
	$rowPdf    = $row->pdf_file;
	$rowHasImg = (int) $row->has_image;

	/*
	/* OWNERSHIP guard — the row must belong to the acting user. 404 (not 403) so
	/* we don't reveal that another crew member's id exists. */
	if ($rowUser !== $actingUser)
	{
		http_response_code(404);
		die('{"ok":false,"error":"not found"}');
	}

	/*
	/* LICENCE guard — reject inductions (venue set). Deleting through the wrong
	/* test would drop a native induction row and unlink its cert. */
	if (!empty($rowVenue) || $rowType === 'Induction Certificate')
	{
		http_response_code(403);
		die('{"ok":false,"error":"not a licence"}');
	}

	/*
	/* DELETE the row (scoped to id AND owner as belt-and-braces); gate on
	/* mysql_error(). */

	mysql_query("DELETE FROM user_licenses WHERE id = " . $id . " AND `user` = " . $actingUser);

	if (mysql_error() !== '')
	{
		http_response_code(500);
		die('{"ok":false,"error":"delete failed"}');
	}

	/*
	/* clean the file(s) — after the row is gone. */

	if ($rowPdf !== null && $rowPdf !== '')
	{
		$pdfPath = BASEPATH . 'user_uploads/' . basename($rowPdf);
		if (is_file($pdfPath))
			@unlink($pdfPath);
	}

	if ($rowHasImg == 1)
	{
		$img1 = BASEPATH . 'images/licensepics/licenseimg_' . $id . '.jpg';
		$img2 = BASEPATH . 'images/licensepics/licenseimg_large_' . $id . '.jpg';
		if (is_file($img1)) @unlink($img1);
		if (is_file($img2)) @unlink($img2);
	}

	echo json_encode(array('ok' => true, 'deleted' => $id));

?>
