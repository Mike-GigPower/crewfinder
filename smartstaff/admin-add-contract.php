<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — record ONE crew member's signed employment contract in
	/* user_documents (doc_type='contract') for an EXPLICIT target user, with the
	/* signed PDF. Built for THE GOAT's convert-to-crew "convert-B" step: after a
	/* candidate becomes a SmartStaff crew member, GOAT downloads the signed contract
	/* PDF from Supabase and POSTs it here.
	/*
	/* Structure mirrors admin-add-license.php (includes, gate, user_uploads/ path,
	/* {user}_{time}.pdf filename, move_uploaded_file, %PDF- magic-bytes check,
	/* success gated on mysql_error(), orphan-file cleanup). Unlike a licence, the
	/* PDF is REQUIRED here — a contract row with no document is meaningless.
	/*
	/* The target `user` is passed explicitly and the endpoint is admin-only, so
	/* there is NO session-user assumption and no IDOR.
	/*
	/* Idempotent per (user, 'contract'): a second call for a crew member who already
	/* has a contract returns { ok:true, skipped:true } and writes nothing (no file,
	/* no row) — safe to retry / re-convert.
	*/

	/*
	/* 1. gate — admin only (session cohort; GOAT posts on the shared admin
	/* SmartStaff session, exactly like admin-add-license.php). */

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
	/* 3. idempotency — if a contract row already exists for this user, stop here.
	/* No file is written and no row inserted, so re-running a conversion is safe. */

	$existing = mysql_query(
		"SELECT id FROM user_documents
		 WHERE `user` = " . $user . " AND doc_type = 'contract' LIMIT 1"
	);

	if ($existing !== false && mysql_num_rows($existing) > 0)
	{
		echo json_encode(array(
			'ok'      => true,
			'skipped' => true,
			'reason'  => 'exists',
			'type'    => 'contract'
		));
		exit;
	}

	/*
	/* 4. optional metadata — signed_at (a DATETIME) and version (contract_version).
	/* signed_at accepts 'YYYY-MM-DD HH:MM:SS' or an ISO 'YYYY-MM-DDTHH:MM:SS...'
	/* (the T and any trailing zone/millis are trimmed to the first 19 chars). Empty
	/* or malformed -> SQL NULL, never 0000-00-00. */

	$signedAtSql = 'NULL';
	if (isset($_POST['signed_at']))
	{
		$raw = trim($_POST['signed_at']);
		$raw = str_replace('T', ' ', substr($raw, 0, 19));
		if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw))
		{
			$signedAtSql = "'" . mysql_real_escape_string($raw) . "'";
		}
	}

	$versionSql = 'NULL';
	if (isset($_POST['version']) && trim($_POST['version']) !== '')
	{
		$versionSql = "'" . mysql_real_escape_string(substr(trim($_POST['version']), 0, 64)) . "'";
	}

	/*
	/* 5. PDF handling — REQUIRED. Require the %PDF- magic bytes (the browser MIME
	/* type is spoofable so it is not trusted). Missing file -> 400. */

	if (!isset($_FILES['contract_pdf']) || !is_uploaded_file($_FILES['contract_pdf']['tmp_name']))
	{
		http_response_code(400);
		die('{"ok":false,"error":"missing pdf"}');
	}

	$head = '';
	$fh   = fopen($_FILES['contract_pdf']['tmp_name'], 'rb');
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

	if ((int) $_FILES['contract_pdf']['size'] > 10 * 1024 * 1024)
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

	if (!move_uploaded_file($_FILES['contract_pdf']['tmp_name'], $savedPath))
	{
		http_response_code(500);
		die('{"ok":false,"error":"file write failed"}');
	}

	$pdfFileSql = "'" . mysql_real_escape_string($savedName) . "'";

	/*
	/* 6. INSERT the contract document row. */

	mysql_query(
		"INSERT INTO user_documents (`user`, doc_type, pdf_file, signed_at, version, created_ts)
		 VALUES (" . $user . ", 'contract', " . $pdfFileSql . ", "
		 . $signedAtSql . ", " . $versionSql . ", " . time() . ")"
	);

	/*
	/* 7. success is gated on mysql_error() (NOT affected_rows). On error, delete
	/* the file we just wrote so a failed insert never leaves an orphan. */

	if (mysql_error() !== '')
	{
		if (is_file($savedPath))
		{
			@unlink($savedPath);
		}
		http_response_code(500);
		die('{"ok":false,"error":"insert failed"}');
	}

	$newId = (int) mysql_insert_id();

	/*
	/* 8. done. */

	echo json_encode(array(
		'ok'       => true,
		'skipped'  => false,
		'id'       => $newId,
		'pdf_file' => $savedName
	));

?>
