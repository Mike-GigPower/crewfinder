<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');

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
	/* orphan-file cleanup). Unlike a licence, the PDF is REQUIRED here — a
	/* contract row with no document is meaningless.
	/*
	/* The target `user` is passed explicitly and the endpoint is admin-only, so
	/* there is NO session-user assumption and no IDOR.
	/*
	/* Idempotent per (user, 'contract'): a second call for a crew member who already
	/* has a contract returns { ok:true, skipped:true } and writes nothing (no file,
	/* no row) — safe to retry / re-convert. The Drive importer and normal convert
	/* both depend on that, so it is unchanged.
	/*
	/* replace=1 (BRIEF-convert-update-existing.md §2.1) is the one exception: the
	/* "update the existing record" convert path for a RETURNING crew member sends
	/* it to store their new signed contract. The existing row is UPDATED IN PLACE
	/* (UNIQUE(user, doc_type) — there is only ever one), so the person still has
	/* exactly one contract row. The old PDF is NOT deleted: filenames are
	/* {user}_{time}.pdf, so the new one never clashes and nothing is lost.
	/*
	/* Database: goat_pdo() (goat-db.php), prepared statements only. Errors via
	/* goat_json_error() emit {"error": "..."} with no `ok` key — ss_push_contract
	/* treats a missing `ok` as failure and reads `error`, so GOAT is unaffected.
	/* PHP 5.x — array(), no ??, no short arrays, tabs.
	*/

	/*
	/* 1. gate — admin only (session cohort; GOAT posts on the shared admin
	/* SmartStaff session, exactly like admin-add-license.php). */

	if (goat_user_cohort() !== 'admin')
	{
		goat_json_error(403, 'Admin only');
		exit;
	}

	/*
	/* 2. validate target user — must be a positive integer. */

	$user = isset($_POST['user']) ? (int) $_POST['user'] : 0;

	if ($user <= 0)
	{
		goat_json_error(400, 'missing user');
		exit;
	}

	$replace = isset($_POST['replace']) && $_POST['replace'] === '1';

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		goat_json_error(503, 'Database handle unavailable');
		exit;
	}

	/*
	/* 3. idempotency — if a contract row already exists for this user and this is
	/* NOT a replace, stop here. No file is written and no row inserted, so
	/* re-running a conversion is safe. On a replace, remember the row's id. */

	$existingId = 0;

	try
	{
		$stmt = $pdo->prepare(
			"SELECT `id` FROM `user_documents`
			 WHERE `user` = ? AND `doc_type` = 'contract' LIMIT 1"
		);
		$stmt->execute(array($user));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$stmt->closeCursor();
	}
	catch (PDOException $e)
	{
		error_log('admin-add-contract: lookup: ' . $e->getMessage());
		goat_json_error(500, 'lookup failed');
		exit;
	}

	if ($row)
	{
		if (!$replace)
		{
			echo json_encode(array(
				'ok'      => true,
				'skipped' => true,
				'reason'  => 'exists',
				'type'    => 'contract'
			));
			exit;
		}

		$existingId = (int) $row['id'];
	}

	/*
	/* 4. optional metadata — signed_at (a DATETIME) and version (contract_version).
	/* signed_at accepts 'YYYY-MM-DD HH:MM:SS' or an ISO 'YYYY-MM-DDTHH:MM:SS...'
	/* (the T and any trailing zone/millis are trimmed to the first 19 chars). Empty
	/* or malformed -> bound as NULL, never 0000-00-00. */

	$signedAt = null;
	if (isset($_POST['signed_at']))
	{
		$raw = trim($_POST['signed_at']);
		$raw = str_replace('T', ' ', substr($raw, 0, 19));
		if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw))
		{
			$signedAt = $raw;
		}
	}

	$version = null;
	if (isset($_POST['version']) && trim($_POST['version']) !== '')
	{
		$version = substr(trim($_POST['version']), 0, 64);
	}

	/*
	/* 5. PDF handling — REQUIRED. Require the %PDF- magic bytes (the browser MIME
	/* type is spoofable so it is not trusted). Missing file -> 400. */

	if (!isset($_FILES['contract_pdf']) || !is_uploaded_file($_FILES['contract_pdf']['tmp_name']))
	{
		goat_json_error(400, 'missing pdf');
		exit;
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
		goat_json_error(400, 'not a pdf');
		exit;
	}

	if ((int) $_FILES['contract_pdf']['size'] > 10 * 1024 * 1024)
	{
		goat_json_error(400, 'file too large');
		exit;
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
		goat_json_error(500, 'file write failed');
		exit;
	}

	/*
	/* 6. write the row — UPDATE the existing one in place on a replace, otherwise
	/* INSERT. On any database error delete the file we JUST wrote (never the
	/* old one) so a failed write never leaves an orphan. */

	try
	{
		if ($existingId > 0)
		{
			$stmt = $pdo->prepare(
				"UPDATE `user_documents`
				 SET `pdf_file` = ?, `signed_at` = ?, `version` = ?, `created_ts` = ?
				 WHERE `id` = ?"
			);
			$stmt->execute(array($savedName, $signedAt, $version, time(), $existingId));
			$newId = $existingId;
		}
		else
		{
			$stmt = $pdo->prepare(
				"INSERT INTO `user_documents` (`user`, `doc_type`, `pdf_file`, `signed_at`, `version`, `created_ts`)
				 VALUES (?, 'contract', ?, ?, ?, ?)"
			);
			$stmt->execute(array($user, $savedName, $signedAt, $version, time()));
			$newId = (int) $pdo->lastInsertId();
		}
	}
	catch (PDOException $e)
	{
		if (is_file($savedPath))
		{
			@unlink($savedPath);
		}
		error_log('admin-add-contract: write: ' . $e->getMessage());
		goat_json_error(500, ($existingId > 0) ? 'update failed' : 'insert failed');
		exit;
	}

	/*
	/* 7. done. */

	echo json_encode(array(
		'ok'       => true,
		'skipped'  => false,
		'replaced' => ($existingId > 0),
		'id'       => (int) $newId,
		'pdf_file' => $savedName
	));

?>
