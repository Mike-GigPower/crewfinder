<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — edit / RENEW one licence row in user_licenses, for THE
	/* GOAT's Manage Crew -> Licences tab. One row per (user, type): a renewal
	/* edits the existing line (new dates + optionally a new PDF) rather than
	/* adding a second.
	/*
	/* Structure mirrors admin-add-license.php: same admin gate, same allow-list,
	/* same NULL-safe date parse, and the SAME PDF-validation block (magic bytes +
	/* 10 MB cap). Induction Certificate rows can never be reached or created here.
	/*
	/* PDF handling: a new upload REPLACES the stored file (old PDF and any legacy
	/* image jpgs are unlinked after a clean UPDATE); no upload leaves pdf_file /
	/* has_image untouched.
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
	/* 3. load the existing row + guard it is a licence (never an induction). */

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
	$oldType   = $row->type;
	$rowVenue  = $row->venue;
	$oldPdf    = $row->pdf_file;
	$oldHasImg = (int) $row->has_image;

	/* Reject inductions. The discriminator is a SET `venue` (native inductions are
	/* typed by venue+year, not 'Induction Certificate'), so !empty(venue) is the
	/* real test; the type string is kept as extra cover. !empty() treats NULL / 0
	/* / '' / '0' all as "no venue" = a licence. */
	if (!empty($rowVenue) || $oldType === 'Induction Certificate')
	{
		http_response_code(403);
		die('{"ok":false,"error":"not a licence"}');
	}

	/*
	/* 4. validate the new type against the fixed allow-list. */

	$allowedTypes = array('CI', 'EWP', 'WWC', 'Forklift', 'Truck', 'Working at Heights');

	$type = isset($_POST['type']) ? trim($_POST['type']) : '';

	if (!in_array($type, $allowedTypes, true))
	{
		http_response_code(400);
		die('{"ok":false,"error":"invalid type"}');
	}

	$typeEsc = mysql_real_escape_string($type);

	/*
	/* 5. one-per-(user,type) guard — if the type is being CHANGED and a different
	/* row already holds the new (user, type), refuse rather than create a dup. */

	if ($type !== $oldType)
	{
		$dupRes = mysql_query(
			"SELECT id FROM user_licenses
			 WHERE `user` = " . $rowUser . " AND type = '" . $typeEsc . "'
			   AND id != " . $id . " LIMIT 1"
		);

		if ($dupRes !== false && mysql_num_rows($dupRes) > 0)
		{
			http_response_code(409);
			die('{"ok":false,"error":"type already exists"}');
		}
	}

	/*
	/* 6. parse dates — strict YYYY-MM-DD or SQL NULL, never 0000-00-00. */

	$dateCertifiedSql = 'NULL';
	if (isset($_POST['date_certified'])
	    && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($_POST['date_certified'])))
	{
		$dateCertifiedSql = "'" . mysql_real_escape_string(trim($_POST['date_certified'])) . "'";
	}

	$dateExpirySql = 'NULL';
	if (isset($_POST['date_expiry'])
	    && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($_POST['date_expiry'])))
	{
		$dateExpirySql = "'" . mysql_real_escape_string(trim($_POST['date_expiry'])) . "'";
	}

	/*
	/* 7. PDF replacement — ONLY if a new licence_pdf was uploaded. Same
	/* validation block as admin-add-license.php (magic bytes + 10 MB cap). When
	/* no file is uploaded, pdf_file / has_image are left as they are. */

	$savedName  = null;
	$savedPath  = null;
	$pdfClause  = '';   /* extra SET fragment, only set when a new file lands */

	if (isset($_FILES['licence_pdf']) && is_uploaded_file($_FILES['licence_pdf']['tmp_name']))
	{
		$head = '';
		$fh   = fopen($_FILES['licence_pdf']['tmp_name'], 'rb');
		if ($fh)
		{
			$head = fread($fh, 5);
			fclose($fh);
		}

		if ($head != '%PDF-')
		{
			http_response_code(400);
			die('{"ok":false,"error":"not a pdf"}');
		}

		if ((int) $_FILES['licence_pdf']['size'] > 10 * 1024 * 1024)
		{
			http_response_code(400);
			die('{"ok":false,"error":"file too large"}');
		}

		$targetdir = BASEPATH . 'user_uploads/';
		if (!is_dir($targetdir))
		{
			@mkdir($targetdir, 0775, true);
		}

		$savedName = $rowUser . '_' . time() . '.pdf';
		$savedPath = $targetdir . $savedName;

		if (!move_uploaded_file($_FILES['licence_pdf']['tmp_name'], $savedPath))
		{
			http_response_code(500);
			die('{"ok":false,"error":"file write failed"}');
		}

		/* new file supersedes the old: pdf_file = new name, has_image back to 0. */
		$pdfClause = ", pdf_file = '" . mysql_real_escape_string($savedName) . "', has_image = 0";
	}

	/*
	/* 8. UPDATE. Success is gated on mysql_error() (NOT affected_rows, which is 0
	/* on a no-op save). On error, delete any newly written file so a failed
	/* update never leaves an orphan. */

	mysql_query(
		"UPDATE user_licenses SET type = '" . $typeEsc . "',"
		. " date_certified = " . $dateCertifiedSql . ","
		. " date_expiry = " . $dateExpirySql . $pdfClause
		. " WHERE id = " . $id
	);

	if (mysql_error() !== '')
	{
		if ($savedPath !== null && is_file($savedPath))
		{
			@unlink($savedPath);
		}
		http_response_code(500);
		die('{"ok":false,"error":"update failed"}');
	}

	/*
	/* 9. clean up the superseded files — ONLY after a clean UPDATE, and ONLY when
	/* a new PDF replaced them. Licence PDFs are per-row unique {user}_{time}.pdf
	/* (not shared like induction certs), so unlinking the old one is safe. Each
	/* unlink is guarded so a missing file never errors. */

	if ($savedName !== null)
	{
		if ($oldPdf !== null && $oldPdf !== '')
		{
			$oldPath = BASEPATH . 'user_uploads/' . basename($oldPdf);
			if (is_file($oldPath))
				@unlink($oldPath);
		}
		if ($oldHasImg == 1)
		{
			$img1 = BASEPATH . 'images/licensepics/licenseimg_' . $id . '.jpg';
			$img2 = BASEPATH . 'images/licensepics/licenseimg_large_' . $id . '.jpg';
			if (is_file($img1)) @unlink($img1);
			if (is_file($img2)) @unlink($img2);
		}
	}

	/*
	/* 10. done. pdf_file reflects the new file if one was uploaded, else the
	/* value that was already stored. */

	echo json_encode(array(
		'ok'       => true,
		'id'       => $id,
		'pdf_file' => ($savedName !== null) ? $savedName
		            : (($oldPdf !== null && $oldPdf !== '') ? $oldPdf : null)
	));

?>
