<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');
	include('licence-catalogue-lib.php');

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
		"SELECT id, `user`, type, type_canonical, venue, pdf_file, has_image
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
	$oldCanon  = $row->type_canonical;   /* NULL until triaged/migrated; the dedup key */
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
	/* 4. validate the new type against the catalogue allow-list. */

	/*
	/* The allow-list is the PUBLISHED `licence_catalogue` codes, read through
	/* licence-catalogue-lib.php. It used to be a hardcoded array here, one of
	/* five copies of the taxonomy.
	/*
	/* FALSE means the catalogue could not be read. REJECT the write — an empty
	/* allow-list would reject everything while looking like a broken form, and
	/* permitting anything would drop a safety boundary that exists to keep
	/* licence writes off induction rows.
	*/

	$allowedTypes = goat_licence_allowed_types();

	if ($allowedTypes === false)
	{
		http_response_code(500);
		die('{"ok":false,"error":"licence catalogue unavailable"}');
	}

	$type = isset($_POST['type']) ? trim($_POST['type']) : '';

	if (!in_array($type, $allowedTypes, true))
	{
		http_response_code(400);
		die('{"ok":false,"error":"invalid type"}');
	}

	$typeEsc = mysql_real_escape_string($type);

	/*
	/* 5. one-per-(user,licence) guard — if the CANONICAL licence is being changed and
	/* a different row already holds the new one, refuse rather than create a dup.
	/*
	/* Compare the new code against the row's type_canonical, NOT its raw type. After
	/* the backfill $oldType is free text ('CI Card') while the canonical value is the
	/* code ('CI'), so editing that row and re-picking CI is NOT a change — comparing
	/* against $oldType would wrongly read it as one and run a needless collision check.
	/* $oldCanon is NULL on a still-untriaged row, so picking any code there counts as
	/* a change and the check runs, which is correct.
	/*
	/* The collision query is keyed the same way as the add endpoints: match
	/* type_canonical when set, fall back to raw type only for untriaged rows. */

	if ($type !== $oldCanon)
	{
		$dupRes = mysql_query(
			"SELECT id FROM user_licenses
			 WHERE `user` = " . $rowUser . "
			   AND (type_canonical = '" . $typeEsc . "'
			        OR (type_canonical IS NULL AND type = '" . $typeEsc . "'))
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

	/* type_canonical is set to the (validated) new type and type_triaged to 1, so an
	/* edit ALWAYS leaves the row fully canonical — including the legacy case where the
	/* old type was free text not in the catalogue and the operator picks a real code:
	/* both columns become that code, never half-migrated. It also keeps an edited row
	/* out of the triage queue (type_canonical IS NULL / type_triaged = 0). */

	mysql_query(
		"UPDATE user_licenses SET type = '" . $typeEsc . "',"
		. " type_canonical = '" . $typeEsc . "', type_triaged = 1,"
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
