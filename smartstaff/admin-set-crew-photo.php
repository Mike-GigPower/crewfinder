<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — replace ONE existing crew member's profile photo
	/* (images/crewpics/crewimg_<user>.jpg). Built for THE GOAT's convert-to-crew
	/* "update the existing record" path, where a returning crew member's
	/* onboarding headshot replaces the one on file
	/* (BRIEF-convert-update-existing.md §2.2).
	/*
	/* Until this file, only add-crew.php ever wrote a crew photo, and only on
	/* creation. The render below copies add-crew.php's phpThumb settings
	/* EXACTLY (jpeg, zc=1, 125x138) so a replaced photo is indistinguishable
	/* from one set at creation — same size, same crop, same filename.
	/*
	/* The current photo is NEVER lost: if one exists it is copied to
	/* crewimg_<user>_<time>.jpg (or _<time>_2, _3, ... if that name is taken —
	/* a backup is never overwritten) BEFORE the render overwrites it, and a
	/* failed copy stops the request before anything is touched.
	/*
	/* Target must be a crew record (usergroupID 3) — the same rule as
	/* update-crew.php — so this cannot change an admin/operator account's
	/* picture. Explicit target user + admin-only gate = no IDOR.
	/*
	/* Response: {"ok":true,"user":<id>,"backup":"<filename>"|null}
	/*           {"ok":false,"error":"render failed"} (500) if phpThumb fails
	/*
	/* PHP 5.x — array(), no ??, no short arrays, tabs. PDO via goat_pdo().
	*/

	/*
	/* 1. gate — admin only. */

	if (goat_user_cohort() !== 'admin')
	{
		goat_json_error(403, 'Admin only');
		exit;
	}

	if ($_SERVER['REQUEST_METHOD'] !== 'POST')
	{
		goat_json_error(405, 'POST required');
		exit;
	}

	/*
	/* 2. validate input — a positive target user and an uploaded file. */

	$user = isset($_POST['user']) ? (int) $_POST['user'] : 0;

	if ($user <= 0)
	{
		goat_json_error(400, 'user required');
		exit;
	}

	if (!isset($_FILES['profilepic']) || !is_uploaded_file($_FILES['profilepic']['tmp_name']))
	{
		goat_json_error(400, 'profilepic required');
		exit;
	}

	/*
	/* 3. the target must exist and be a crew record. */

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		goat_json_error(503, 'Database handle unavailable');
		exit;
	}

	try
	{
		$stmt = $pdo->prepare("SELECT `usergroupID` FROM `users` WHERE `id` = ? LIMIT 1");
		$stmt->execute(array($user));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$stmt->closeCursor();
	}
	catch (PDOException $e)
	{
		error_log('admin-set-crew-photo: ' . $e->getMessage());
		goat_json_error(500, 'lookup failed');
		exit;
	}

	if (!$row)
	{
		goat_json_error(404, 'crew member not found');
		exit;
	}

	if ((int) $row['usergroupID'] !== 3)
	{
		goat_json_error(403, 'not a crew record');
		exit;
	}

	/*
	/* 4. it must really be a JPEG or PNG (the browser MIME type is spoofable, so
	/* it is not trusted) and no bigger than 10 MB. */

	if ((int) $_FILES['profilepic']['size'] > 10 * 1024 * 1024)
	{
		goat_json_error(400, 'file too large');
		exit;
	}

	$info = @getimagesize($_FILES['profilepic']['tmp_name']);

	if ($info === false || !in_array((int) $info[2], array(IMAGETYPE_JPEG, IMAGETYPE_PNG), true))
	{
		goat_json_error(400, 'not a JPEG or PNG image');
		exit;
	}

	/*
	/* 5. keep the current photo. add-crew.php writes BASEPATH .'/images/...'
	/* (BASEPATH already ends in '/', so that doubles the slash harmlessly);
	/* this uses the single-slash form admin-add-contract.php uses. */

	$picdir  = BASEPATH . 'images/crewpics/';
	$target  = $picdir . 'crewimg_' . $user . '.jpg';
	$backup  = null;

	/* An existing backup is NEVER overwritten. Two uploads in the same second
	/* would otherwise share crewimg_<user>_<time>.jpg and the second backup
	/* (already the NEW photo) would replace the first — losing the original
	/* (seen on test, T3). So: try _<time>, then _<time>_2, _3, ... and claim
	/* the first free name with fopen('x'), which fails if the file exists and
	/* is atomic, so even two simultaneous requests cannot pick the same name. */

	if (is_file($target))
	{
		$stem       = 'crewimg_' . $user . '_' . time();
		$backupName = null;

		for ($n = 1; $n <= 100; $n++)
		{
			$cand = $stem . (($n > 1) ? '_' . $n : '') . '.jpg';
			$fh   = @fopen($picdir . $cand, 'x');
			if ($fh !== false)
			{
				fclose($fh);
				$backupName = $cand;
				break;
			}
		}

		if ($backupName === null || !@copy($target, $picdir . $backupName))
		{
			if ($backupName !== null)
			{
				@unlink($picdir . $backupName);   /* drop the empty claimed file */
			}
			goat_json_error(500, 'could not back up the current photo');
			exit;
		}

		$backup = $backupName;
	}

	/*
	/* 6. render exactly as add-crew.php does. add-crew.php lives in the site
	/* root and uses a relative 'lib/phpThumb/...' path; from ajax/crew/ that
	/* would not resolve, so the path is anchored on BASEPATH instead. */

	require_once(BASEPATH . 'lib/phpThumb/phpthumb.class.php');
	$phpThumb = new phpThumb();

	$phpThumb->setParameter('config_output_format', 'jpeg');
	$phpThumb->setSourceFilename($_FILES['profilepic']['tmp_name']);
	$phpThumb->setParameter('zc', 1);
	$phpThumb->setParameter('w', 125);
	$phpThumb->setParameter('h', 138);

	$ok = $phpThumb->GenerateThumbnail() && $phpThumb->RenderToFile($target);

	if (!$ok)
	{
		error_log('admin-set-crew-photo: phpThumb failed for user ' . $user . ': '
		          . implode(' | ', (array) $phpThumb->debugmessages));
		http_response_code(500);
		die('{"ok":false,"error":"render failed"}');
	}

	/*
	/* 7. done. */

	echo json_encode(array(
		'ok'     => true,
		'user'   => $user,
		'backup' => $backup
	));

?>
