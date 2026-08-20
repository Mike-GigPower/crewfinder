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
	/* SELF endpoint — write ONE licence row into user_licenses for the LOGGED-IN /
	/* service-asserted user (Crew Hub "My Licences" → Add), with an optional PDF.
	/*
	/* Self-scoped: the target user comes from goat_acting_user_id() (a SmartStaff
	/* session, OR the service key + the backend-asserted userID), NEVER a
	/* client-supplied `user`. This is the ownership guarantee — a crew member can
	/* only ever add a licence to their OWN record.
	/*
	/* Mirrors admin-add-license.php exactly otherwise: the same fixed allow-list
	/* (so a write can never touch or create an induction row), the same NULL-safe
	/* date parse, the same %PDF- magic + 10 MB cap, and — critically — the INSERT
	/* NEVER sets `venue`, so the row is a licence (empty venue), never an
	/* induction. Idempotent per (user, type): a duplicate returns skipped:true.
	*/

	$user = (int) goat_acting_user_id();  /* emits JSON + exits on failure */

	if ($user <= 0)
	{
		http_response_code(400);
		die('{"ok":false,"error":"missing user"}');
	}

	/*
	/* validate type against the catalogue allow-list. Anything else — INCLUDING
	/* 'Induction Certificate' — is rejected, so a licence write can never touch or
	/* create an induction row. */

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

	/*
	/* parse dates. Each of date_certified / date_expiry is either a strict
	/* YYYY-MM-DD string or SQL NULL — NEVER 0000-00-00. Empty/malformed → NULL. */

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

	$typeEsc = mysql_real_escape_string($type);

	/*
	/* idempotency — one row per (user, licence). If a row already exists, stop here:
	/* no file written, no row inserted. The crew form only offers types the member
	/* doesn't already hold, so this is a backstop.
	/*
	/* Keyed on the CANONICAL code, not the raw type: post-backfill `type` holds the
	/* original free text ('CI Card') while type_canonical holds the code ('CI'), so a
	/* type-only match would miss and duplicate. Match type_canonical when set; fall
	/* back to raw type only for still-untriaged rows (type_canonical NULL). */

	$existing = mysql_query(
		"SELECT id FROM user_licenses
		 WHERE `user` = " . $user . "
		   AND (type_canonical = '" . $typeEsc . "'
		        OR (type_canonical IS NULL AND type = '" . $typeEsc . "'))
		 LIMIT 1"
	);

	if ($existing !== false && mysql_num_rows($existing) > 0)
	{
		echo json_encode(array(
			'ok'      => true,
			'skipped' => true,
			'reason'  => 'exists',
			'type'    => $type
		));
		exit;
	}

	/*
	/* PDF handling — only if a file was actually uploaded. Require the %PDF- magic
	/* bytes (the browser MIME type is spoofable so it is not trusted). If no file
	/* is present, pdf_file is inserted as SQL NULL (metadata-only row). */

	$pdfFileSql = 'NULL';
	$savedName  = null;
	$savedPath  = null;

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

		$savedName = $user . '_' . time() . '.pdf';
		$savedPath = $targetdir . $savedName;

		if (!move_uploaded_file($_FILES['licence_pdf']['tmp_name'], $savedPath))
		{
			http_response_code(500);
			die('{"ok":false,"error":"file write failed"}');
		}

		$pdfFileSql = "'" . mysql_real_escape_string($savedName) . "'";
	}

	/*
	/* INSERT. `venue` is deliberately NOT set, so the row is a licence (empty
	/* venue), never an induction. has_image is the bare 0 the native handlers
	/* write for a non-image (PDF/metadata) licence.
	/*
	/* type_canonical = the type and type_triaged = 1: the value was validated against
	/* the 41-code catalogue above, so a crew self-add is already canonical and must
	/* not enter the triage queue (type_canonical IS NULL / type_triaged = 0). */

	mysql_query(
		"INSERT INTO user_licenses (`user`, type, type_canonical, type_triaged, pdf_file, has_image, date_certified, date_expiry)
		 VALUES (" . $user . ", '" . $typeEsc . "', '" . $typeEsc . "', 1, " . $pdfFileSql . ", 0, "
		 . $dateCertifiedSql . ", " . $dateExpirySql . ")"
	);

	/*
	/* success is gated on mysql_error() (NOT affected_rows, which is 0 on a
	/* no-op). On error, delete any file we just wrote so a failed insert never
	/* leaves an orphan referenced by no row. */

	if (mysql_error() !== '')
	{
		if ($savedPath !== null && is_file($savedPath))
		{
			@unlink($savedPath);
		}
		http_response_code(500);
		die('{"ok":false,"error":"insert failed"}');
	}

	$newId = (int) mysql_insert_id();

	echo json_encode(array(
		'ok'       => true,
		'skipped'  => false,
		'id'       => $newId,
		'pdf_file' => ($savedName !== null) ? $savedName : null
	));

?>
