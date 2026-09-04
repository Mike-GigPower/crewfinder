<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* ADMIN endpoint — stream ONE crew member's VEVO Visa Details Check PDF, for
	/* the "View" control beside the VEVO stamp in Manage Crew -> Visa.
	/*
	/* A DELIBERATE COPY of admin-get-visa-file.php, not a generalisation of it,
	/* and not a mode flag on that endpoint. The two differ in exactly one place —
	/* the filename whitelist — and that one place is the anti-traversal guard.
	/* 5.23.0 recorded the rule when the visa file server was copied from the
	/* licence one: "a shared file server with two narrow guards is how one of
	/* them gets skipped in the next copy." This is the next copy.
	/*
	/* The client never sees or supplies the filename. It passes a user id; the
	/* endpoint resolves the name from the row. A crafted filename cannot reach
	/* outside user_uploads/ because no filename from the request is ever used.
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

	$res = mysql_query("SELECT `vevo_pdf` FROM `user_visa` WHERE `user` = " . $user . " LIMIT 1");

	if ($res === false || mysql_num_rows($res) == 0)
	{
		http_response_code(404);
		header('Content-Type: application/json');
		die('{"error":"no visa record"}');
	}

	$row = mysql_fetch_object($res);

	if ($row->vevo_pdf === null || $row->vevo_pdf === '')
	{
		http_response_code(404);
		header('Content-Type: application/json');
		die('{"error":"no file"}');
	}

	/*
	/* Anti-traversal: basename() strips any path, then a strict
	/* {digits}_{digits}_vevo.pdf whitelist — the exact shape admin-set-visa.php
	/* writes ($user . '_' . time() . '_vevo.pdf').
	/*
	/* THE '_vevo' SUFFIX IS LOAD-BEARING, not decoration. user_uploads/ is one
	/* flat directory holding licence, induction, contract, visa and VEVO PDFs,
	/* all named {digits}_{digits}.pdf. Without a distinct shape this endpoint's
	/* whitelist would accept any of them, and so would the visa one — the two
	/* guards would overlap completely and neither would constrain anything. With
	/* it, this endpoint can only ever serve a VEVO check and the visa endpoint
	/* can never serve one.
	*/

	$file = basename($row->vevo_pdf);

	if (!preg_match('/^[0-9]+_[0-9]+_vevo\.pdf$/', $file))
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
		/* the second is data loss and deserves a different word. It matters more
		/* here than for the visa document: this file is the evidence behind a
		/* compliance stamp, and a stamp whose evidence has vanished should not
		/* read as a stamp with no evidence ever recorded.
		*/
		http_response_code(404);
		header('Content-Type: application/json');
		die('{"error":"file not found"}');
	}

	header('Content-Type: application/pdf');
	header('Content-Length: ' . filesize($path));
	header('Content-Disposition: inline; filename="vevo-check.pdf"');
	header('X-Content-Type-Options: nosniff');
	readfile($path);
	exit;

?>
