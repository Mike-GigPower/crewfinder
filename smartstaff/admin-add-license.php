<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — write ONE licence row into user_licenses for an EXPLICIT
	/* target user, with an optional PDF. Built for THE GOAT's convert-to-crew
	/* "convert-B" step: after a candidate becomes a SmartStaff crew member, GOAT
	/* downloads each onboarding licence PDF from Supabase and POSTs it here, one
	/* per request.
	/*
	/* Structure mirrors add-my-induction.php (includes + JSON shape) and
	/* user-add-license.php (user_uploads/ path, {user}_{time}.pdf filename,
	/* move_uploaded_file, and the has_image literal for a non-image licence).
	/*
	/* Because the target `user` is passed explicitly and the endpoint is
	/* admin-only, there is NO session-user assumption here — so it does not carry
	/* the native user-add-license.php IDOR. (A future self-scoped Crew Hub variant
	/* MUST add its own ownership check.)
	/*
	/* Idempotent per (user, type): a second call for a licence that already exists
	/* returns { ok:true, skipped:true } and writes nothing — safe to retry.
	*/

	/*
	/* 1. gate — admin only (session cohort; GOAT posts on the shared admin
	/* SmartStaff session, exactly like add-crew / add-group). */

	if (goat_user_cohort() !== 'admin')
	{
		http_response_code(403);
		die('{"ok":false,"error":"forbidden"}');
	}

	/*
	/* 2. validate target user — must be a positive integer. */

	$user = isset($_POST['user']) ? (int) $_POST['user'] : 0;

	if ($user <= 0)
	{
		http_response_code(400);
		die('{"ok":false,"error":"missing user"}');
	}

	/*
	/* 3. validate type against the fixed allow-list. Anything else — INCLUDING
	/* 'Induction Certificate' — is rejected, so licence writes can never touch or
	/* create induction rows (handover rule #2, enforced at the write boundary). */

	$allowedTypes = array('CI', 'EWP', 'WWC', 'Forklift', 'Truck', 'Working at Heights');

	$type = isset($_POST['type']) ? trim($_POST['type']) : '';

	if (!in_array($type, $allowedTypes, true))
	{
		http_response_code(400);
		die('{"ok":false,"error":"invalid type"}');
	}

	/*
	/* 4. parse dates. Each of date_certified / date_expiry is either a strict
	/* YYYY-MM-DD string or SQL NULL — NEVER 0000-00-00. Anything empty or
	/* malformed becomes NULL. */

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
	/* 5. idempotency — if a row already exists for this (user, type), stop here.
	/* No file is written and no row inserted, so re-running a conversion is safe. */

	$existing = mysql_query(
		"SELECT id FROM user_licenses
		 WHERE `user` = " . $user . " AND type = '" . $typeEsc . "' LIMIT 1"
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
	/* 6. PDF handling — only if a file was actually uploaded. Require the %PDF-
	/* magic bytes (the browser MIME type is spoofable so it is not trusted). If no
	/* file is present, pdf_file is inserted as SQL NULL (metadata-only row). */

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

		/* Size cap — mirrors add-my-induction.php. convert-B never needed it
		/* (GOAT's onboarding PDFs are known-small), but the manual admin flow
		/* now accepts an arbitrary operator-chosen file. */
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
	/* 7. INSERT. has_image is the same literal the native handlers write for a
	/* non-image (PDF/metadata) licence — a bare 0, exactly as add-my-induction.php
	/* writes into this binary(1) column via raw mysql_query. */

	mysql_query(
		"INSERT INTO user_licenses (`user`, type, pdf_file, has_image, date_certified, date_expiry)
		 VALUES (" . $user . ", '" . $typeEsc . "', " . $pdfFileSql . ", 0, "
		 . $dateCertifiedSql . ", " . $dateExpirySql . ")"
	);

	/*
	/* 8. success is gated on mysql_error() (NOT affected_rows, which is 0 on a
	/* no-op). On error, delete the file we just wrote (if any) so a failed insert
	/* never leaves an orphan referenced by no row. */

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

	/*
	/* 9. done. */

	echo json_encode(array(
		'ok'       => true,
		'skipped'  => false,
		'id'       => $newId,
		'pdf_file' => ($savedName !== null) ? $savedName : null
	));

?>
