<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — delete one licence row from user_licenses, for THE GOAT's
	/* Manage Crew -> Licences tab, then clean up its file(s).
	/*
	/* Induction Certificate rows can never be reached here (same load-and-guard as
	/* admin-edit-license.php), so an induction can never be deleted through the
	/* licence surface.
	/*
	/* Licence PDFs are per-row unique {user}_{time}.pdf (not shared like induction
	/* certs), so unlinking on delete is safe. Legacy image licences also drop
	/* their two licensepics jpgs. Each unlink is guarded so a missing file never
	/* errors.
	*/

	/*
	/* 1. gate — admin only. */

	if (goat_user_cohort() !== 'admin')
	{
		http_response_code(403);
		die('{"ok":false,"error":"forbidden"}');
	}

	/*
	/* 2. validate id. */

	$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

	if ($id <= 0)
	{
		http_response_code(400);
		die('{"ok":false,"error":"missing id"}');
	}

	/*
	/* 3. load the row + guard it is a licence (never an induction). */

	$loadRes = mysql_query(
		"SELECT id, type, venue, pdf_file, has_image
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
	$rowType   = $row->type;
	$rowVenue  = $row->venue;
	$rowPdf    = $row->pdf_file;
	$rowHasImg = (int) $row->has_image;

	/* Reject inductions. A SET `venue` marks an induction (native ones are typed
	/* by venue+year, not 'Induction Certificate'), so !empty(venue) is the real
	/* test; the type string stays as extra cover. Deleting through the wrong test
	/* would drop a native induction row and unlink its cert — this prevents it. */
	if (!empty($rowVenue) || $rowType === 'Induction Certificate')
	{
		http_response_code(403);
		die('{"ok":false,"error":"not a licence"}');
	}

	/*
	/* 4. DELETE the row; gate on mysql_error(). */

	mysql_query("DELETE FROM user_licenses WHERE id = " . $id);

	if (mysql_error() !== '')
	{
		http_response_code(500);
		die('{"ok":false,"error":"delete failed"}');
	}

	/*
	/* 5. clean the file(s) — after the row is gone. */

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

	/*
	/* 6. done. */

	echo json_encode(array('ok' => true, 'deleted' => $id));

?>
