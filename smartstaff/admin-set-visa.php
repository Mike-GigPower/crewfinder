<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — UPSERT ONE crew member's visa record into user_visa for an
	/* EXPLICIT target user, with the visa PDF, and set the users.is_visa_worker
	/* quick-flag. Built for THE GOAT's convert-to-crew "convert-B" step: only called
	/* for a working-visa crew member, after they become a SmartStaff user. The
	/* fields map from Supabase work_eligibility + visa_extraction + the recorded
	/* VEVO check (vevo_check). Every visa value is AI-suggested / operator-checked
	/* upstream (VEVO is the authority) — this endpoint only stores what it's given.
	/*
	/* Structure mirrors admin-add-license.php (includes, admin gate, user_uploads/
	/* path, {user}_{time}.pdf filename, %PDF- magic-bytes check, success gated on
	/* mysql_error(), orphan-file cleanup). immigration PII — admin-gated, same as
	/* the GOAT work-eligibility view.
	/*
	/* Idempotent per (user): UNIQUE(`user`) on user_visa. A row already present is
	/* UPDATED (not duplicated); a re-supplied PDF replaces the old one (unlinked).
	*/

	/*
	/* 1. gate — admin only. */

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
	/* 3. work_eligibility_status — allow-list; anything else -> NULL. is_visa_worker
	/* is derived from it (working_visa => 1). */

	$statusRaw = isset($_POST['work_eligibility_status']) ? trim($_POST['work_eligibility_status']) : '';
	$statusSql = 'NULL';
	$isVisaWorker = 0;
	if ($statusRaw === 'citizen_pr' || $statusRaw === 'working_visa')
	{
		$statusSql = "'" . mysql_real_escape_string($statusRaw) . "'";
		if ($statusRaw === 'working_visa')
		{
			$isVisaWorker = 1;
		}
	}

	/*
	/* 4. plain string fields — escaped, length-capped. Empty -> NULL. */

	function gp_str_or_null($key, $max)
	{
		if (!isset($_POST[$key]))
			return 'NULL';
		$v = trim($_POST[$key]);
		if ($v === '')
			return 'NULL';
		return "'" . mysql_real_escape_string(substr($v, 0, $max)) . "'";
	}

	$passportNumberSql  = gp_str_or_null('passport_number', 64);
	$passportCountrySql = gp_str_or_null('passport_country', 128);
	$subclassSql        = gp_str_or_null('visa_subclass', 32);
	$grantNumberSql     = gp_str_or_null('visa_grant_number', 64);
	$trnSql             = gp_str_or_null('trn', 64);
	$conditionsSql      = gp_str_or_null('visa_conditions', 2000);
	$vevoBySql          = gp_str_or_null('vevo_verified_by', 255);

	/*
	/* 5. date fields — strict YYYY-MM-DD or NULL (never 0000-00-00). */

	function gp_date_or_null($key)
	{
		if (isset($_POST[$key]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($_POST[$key])))
			return "'" . mysql_real_escape_string(trim($_POST[$key])) . "'";
		return 'NULL';
	}

	$grantDateSql = gp_date_or_null('visa_grant_date');
	$expirySql    = gp_date_or_null('visa_expiry');

	/*
	/* 6. vevo_verified_at — a DATETIME. Accepts 'YYYY-MM-DD HH:MM:SS' or an ISO
	/* 'YYYY-MM-DDTHH:MM:SS...' (T + any trailing zone/millis trimmed). Else NULL. */

	$vevoAtSql = 'NULL';
	if (isset($_POST['vevo_verified_at']))
	{
		$raw = trim($_POST['vevo_verified_at']);
		$raw = str_replace('T', ' ', substr($raw, 0, 19));
		if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw))
			$vevoAtSql = "'" . mysql_real_escape_string($raw) . "'";
	}

	/*
	/* 7. has_work_limitation — 1 / 0 / NULL (unclear). Accept '1','0','true',
	/* 'false'; anything else -> NULL. */

	$limitSql = 'NULL';
	if (isset($_POST['has_work_limitation']))
	{
		$hl = strtolower(trim($_POST['has_work_limitation']));
		if ($hl === '1' || $hl === 'true')
			$limitSql = '1';
		else if ($hl === '0' || $hl === 'false')
			$limitSql = '0';
	}

	/*
	/* 8. PDF handling — optional. Require the %PDF- magic bytes if present. */

	$savedName = null;
	$savedPath = null;

	if (isset($_FILES['visa_pdf']) && is_uploaded_file($_FILES['visa_pdf']['tmp_name']))
	{
		$head = '';
		$fh   = fopen($_FILES['visa_pdf']['tmp_name'], 'rb');
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

		if ((int) $_FILES['visa_pdf']['size'] > 10 * 1024 * 1024)
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

		if (!move_uploaded_file($_FILES['visa_pdf']['tmp_name'], $savedPath))
		{
			http_response_code(500);
			die('{"ok":false,"error":"file write failed"}');
		}
	}

	/*
	/* 9. upsert — is there already a user_visa row for this user? Capture the old
	/* PDF so we can unlink it if a new one replaces it. */

	$existId  = 0;
	$oldPdf   = null;
	$existRes = mysql_query("SELECT id, visa_pdf FROM user_visa WHERE `user` = " . $user . " LIMIT 1");
	if ($existRes !== false && mysql_num_rows($existRes) > 0)
	{
		$erow    = mysql_fetch_object($existRes);
		$existId = (int) $erow->id;
		$oldPdf  = $erow->visa_pdf;
	}

	/*
	/* visa_pdf SET clause: only touch the column when a new file was uploaded.
	/* On INSERT with no file, it is NULL; on UPDATE with no file, it is left as-is. */
	$visaPdfAssign = '';
	$visaPdfInsert = 'NULL';
	if ($savedName !== null)
	{
		$visaPdfAssign = ", visa_pdf = '" . mysql_real_escape_string($savedName) . "'";
		$visaPdfInsert = "'" . mysql_real_escape_string($savedName) . "'";
	}

	if ($existId > 0)
	{
		mysql_query(
			"UPDATE user_visa SET
				work_eligibility_status = " . $statusSql . ",
				is_visa_worker = " . $isVisaWorker . ",
				passport_number = " . $passportNumberSql . ",
				passport_country = " . $passportCountrySql . ",
				visa_subclass = " . $subclassSql . ",
				visa_grant_number = " . $grantNumberSql . ",
				trn = " . $trnSql . ",
				visa_grant_date = " . $grantDateSql . ",
				visa_expiry = " . $expirySql . ",
				visa_conditions = " . $conditionsSql . ",
				has_work_limitation = " . $limitSql . ",
				vevo_verified_at = " . $vevoAtSql . ",
				vevo_verified_by = " . $vevoBySql . ",
				updated_ts = " . time() . "
				" . $visaPdfAssign . "
			 WHERE id = " . $existId
		);
	}
	else
	{
		mysql_query(
			"INSERT INTO user_visa
				(`user`, work_eligibility_status, is_visa_worker, passport_number,
				 passport_country, visa_subclass, visa_grant_number, trn,
				 visa_grant_date, visa_expiry, visa_conditions, has_work_limitation,
				 vevo_verified_at, vevo_verified_by, visa_pdf, updated_ts)
			 VALUES (" . $user . ", " . $statusSql . ", " . $isVisaWorker . ", "
				 . $passportNumberSql . ", " . $passportCountrySql . ", "
				 . $subclassSql . ", " . $grantNumberSql . ", " . $trnSql . ", "
				 . $grantDateSql . ", " . $expirySql . ", " . $conditionsSql . ", "
				 . $limitSql . ", " . $vevoAtSql . ", " . $vevoBySql . ", "
				 . $visaPdfInsert . ", " . time() . ")"
		);
	}

	/*
	/* 10. success gated on mysql_error(). On error, delete a just-written file so a
	/* failed write never leaves an orphan. */

	if (mysql_error() !== '')
	{
		if ($savedPath !== null && is_file($savedPath))
		{
			@unlink($savedPath);
		}
		http_response_code(500);
		die('{"ok":false,"error":"write failed"}');
	}

	$rowId = ($existId > 0) ? $existId : (int) mysql_insert_id();

	/*
	/* 11. a new PDF replaced an old one -> unlink the superseded file (only after a
	/* clean write, and only when the name actually changed). */

	if ($savedName !== null && $oldPdf !== null && $oldPdf !== '' && $oldPdf !== $savedName)
	{
		$oldPath = BASEPATH . 'user_uploads/' . basename($oldPdf);
		if (is_file($oldPath))
		{
			@unlink($oldPath);
		}
	}

	/*
	/* 12. set the users.is_visa_worker quick-flag (rostering / compliance side reads
	/* this without a join). Best-effort: a failure here does not undo the visa row. */

	mysql_query("UPDATE users SET is_visa_worker = " . $isVisaWorker . " WHERE id = " . $user);

	/*
	/* 13. done. */

	echo json_encode(array(
		'ok'             => true,
		'skipped'        => false,
		'updated'        => ($existId > 0),
		'id'             => $rowId,
		'is_visa_worker' => $isVisaWorker,
		'visa_pdf'       => ($savedName !== null) ? $savedName : null
	));

?>
