<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* ADMIN endpoint — stream ONE crew member's visa PDF, for the "View" control
	/* in the Manage Crew -> Visa section.
	/*
	/* Modelled on admin-get-license-file.php (anti-traversal via basename + strict
	/* regex, readfile with inline headers + nosniff), but keyed on `user` because
	/* user_visa is 1:1, and WITHOUT that file's induction guard — there are no
	/* inductions in this table and carrying a guard that can never fire is how a
	/* guard that matters gets skipped in the next copy.
	/*
	/* Deliberately a SEPARATE endpoint rather than generalising the licence one:
	/* two file servers with two narrow guards beat one with a mode flag.
	/*
	/* The client never sees or supplies the filename — it passes a user id and the
	/* endpoint resolves the name itself, so a crafted filename cannot reach outside
	/* user_uploads/.
	/*
	/* PHP 5.x — mysql_*, array(), no ??, no short arrays.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		http_response_code(403);
		header('Content-Type: application/json');
		die('{"error":"forbidden"}');
	}

	$user = isset($_GET['user']) ? (int) $_GET['user'] : 0;

	if ($user <= 0)
	{
		http_response_code(400);
		header('Content-Type: application/json');
		die('{"error":"user required"}');
	}

	$res = mysql_query("SELECT `visa_pdf` FROM `user_visa` WHERE `user` = " . $user . " LIMIT 1");

	if ($res === false || mysql_num_rows($res) == 0)
	{
		http_response_code(404);
		header('Content-Type: application/json');
		die('{"error":"no visa record"}');
	}

	$row = mysql_fetch_object($res);

	if ($row->visa_pdf === null || $row->visa_pdf === '')
	{
		http_response_code(404);
		header('Content-Type: application/json');
		die('{"error":"no file"}');
	}

	/*
	/* Anti-traversal: basename() strips any path, then a strict
	/* {digits}_{digits}.pdf whitelist — the exact shape admin-set-visa.php writes
	/* ($user . '_' . time() . '.pdf'). Anything else is rejected rather than
	/* served, however it got into the column.
	*/

	$file = basename($row->visa_pdf);

	if (!preg_match('/^[0-9]+_[0-9]+\.pdf$/', $file))
	{
		http_response_code(400);
		header('Content-Type: application/json');
		die('{"error":"bad filename"}');
	}

	$path = BASEPATH . 'user_uploads/' . $file;

	if (!is_file($path))
	{
		/*
		/* The row names a file that is not on disk. Distinct from "no file" above
		/* so the operator can tell "never uploaded" from "uploaded and missing" —
		/* the second is a data-loss problem and deserves a different word.
		*/
		http_response_code(404);
		header('Content-Type: application/json');
		die('{"error":"file not found"}');
	}

	header('Content-Type: application/pdf');
	header('Content-Length: ' . filesize($path));
	header('Content-Disposition: inline; filename="visa.pdf"');
	header('X-Content-Type-Options: nosniff');
	readfile($path);
	exit;

?>
