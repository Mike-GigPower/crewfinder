<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* ADMIN endpoint — stream ONE crew member's document PDF, for the "View"
	/* control in the Manage Crew -> Documents section.
	/*
	/* Modelled on admin-get-visa-file.php (anti-traversal via basename + strict
	/* regex, readfile with inline headers + nosniff), keyed on `user` + `doc_type`
	/* because user_documents is 1:1 per (user, doc_type) — that pair is the table's
	/* UNIQUE key, so it identifies exactly one row.
	/*
	/* Deliberately a SEPARATE endpoint rather than generalising the licence or visa
	/* one: three file servers with three narrow guards beat one with a mode flag.
	/* That is the same call 5.23.0 recorded when admin-get-visa-file.php was
	/* copied rather than merged, and the reasoning has not changed — a shared
	/* server is how one guard gets skipped in the next copy.
	/*
	/* The client never sees or supplies the filename. It passes a user id and a
	/* doc_type; the endpoint resolves the name from the row. This matters more
	/* here than it did for visas: user_uploads/ is one flat directory holding
	/* licence, induction, visa AND contract PDFs, every one named
	/* {user}_{time}.pdf. The {digits}_{digits}.pdf whitelist alone cannot tell a
	/* contract from any other PDF in there, so if a filename came in on the query
	/* string the whitelist would happily serve somebody else's licence. Looking
	/* the name up from the row makes that structurally impossible instead of
	/* merely unlikely.
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

	/*
	/* doc_type is whitelisted, not escaped-and-trusted. Only types this app
	/* actually writes may be fetched, so a new doc_type cannot be served before
	/* somebody has decided who is allowed to see it.
	*/

	$allowedTypes = array('contract');
	$docType = isset($_GET['doc_type']) ? trim($_GET['doc_type']) : 'contract';

	if (!in_array($docType, $allowedTypes))
	{
		http_response_code(400);
		header('Content-Type: application/json');
		die('{"error":"bad doc_type"}');
	}

	$res = mysql_query(
		"SELECT `pdf_file` FROM `user_documents`
		 WHERE `user` = " . $user . "
		   AND `doc_type` = '" . mysql_real_escape_string($docType) . "' LIMIT 1"
	);

	if ($res === false || mysql_num_rows($res) == 0)
	{
		http_response_code(404);
		header('Content-Type: application/json');
		die('{"error":"no document record"}');
	}

	$row = mysql_fetch_object($res);

	if ($row->pdf_file === null || $row->pdf_file === '')
	{
		http_response_code(404);
		header('Content-Type: application/json');
		die('{"error":"no file"}');
	}

	/*
	/* Anti-traversal: basename() strips any path, then a strict
	/* {digits}_{digits}.pdf whitelist — the exact shape admin-add-contract.php
	/* writes ($user . '_' . time() . '.pdf'). Anything else is rejected rather
	/* than served, however it got into the column.
	*/

	$file = basename($row->pdf_file);

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
		/* the second is a data-loss problem and deserves a different word. For a
		/* signed employment agreement it is also the more serious of the two.
		*/
		http_response_code(404);
		header('Content-Type: application/json');
		die('{"error":"file not found"}');
	}

	header('Content-Type: application/pdf');
	header('Content-Length: ' . filesize($path));
	header('Content-Disposition: inline; filename="' . $docType . '.pdf"');
	header('X-Content-Type-Options: nosniff');
	readfile($path);
	exit;

?>
