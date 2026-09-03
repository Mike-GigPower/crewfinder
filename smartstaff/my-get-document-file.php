<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* SELF endpoint — stream ONE of the LOGGED-IN / service-asserted user's OWN
	/* documents (their signed employment agreement), for Crew Hub "My Documents".
	/*
	/* Self-scoped + OWNERSHIP-checked by construction: the row is looked up by
	/* (acting user, doc_type), so there is no id to tamper with and no way to
	/* address another crew member's row at all. This is a stronger position than
	/* my-get-license-file.php, which takes a row id and must then check the owner
	/* — worth noting rather than copying that shape out of habit.
	/*
	/* doc_type is whitelisted, not escaped-and-trusted: a new document type
	/* cannot be served to crew before somebody has decided they may see it. A
	/* contract is the crew member's own signed agreement, so it is theirs to
	/* read; that will not automatically be true of whatever lands in this table
	/* next.
	/*
	/* The client never sees or supplies the filename — the endpoint resolves it
	/* from the row. Same reasoning as the admin endpoint: user_uploads/ is one
	/* flat directory of {user}_{time}.pdf files covering licences, inductions,
	/* visas and contracts, so the {digits}_{digits}.pdf whitelist alone cannot
	/* tell them apart.
	/*
	/* PHP 5.x — mysql_*, array(), no ??, no short arrays.
	*/

	$actingUser = (int) goat_acting_user_id();  /* emits JSON + exits on failure */

	if ($actingUser <= 0)
	{
		http_response_code(400);
		header('Content-Type: application/json');
		die('{"error":"missing user"}');
	}

	$allowedTypes = array('contract');
	$docType = isset($_GET['doc_type']) ? trim($_GET['doc_type']) : 'contract';

	if (!in_array($docType, $allowedTypes))
	{
		http_response_code(400);
		header('Content-Type: application/json');
		die('{"error":"bad doc_type"}');
	}

	/*
	/* Scoped to the acting user AND the doc_type — the table's UNIQUE key, so
	/* this identifies exactly one row and can never reach another person's. */

	$res = mysql_query(
		"SELECT `pdf_file` FROM `user_documents`
		 WHERE `user` = " . $actingUser . "
		   AND `doc_type` = '" . mysql_real_escape_string($docType) . "' LIMIT 1"
	);

	if ($res === false || mysql_num_rows($res) == 0)
	{
		http_response_code(404);
		header('Content-Type: application/json');
		die('{"error":"not found"}');
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
	/* writes. Anything else is rejected rather than served, however it got into
	/* the column. */

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
		http_response_code(404);
		header('Content-Type: application/json');
		die('{"error":"file not found"}');
	}

	header('Content-Type: application/pdf');
	header('Content-Length: ' . filesize($path));
	header('Content-Disposition: inline; filename="employment-agreement.pdf"');
	header('X-Content-Type-Options: nosniff');
	readfile($path);
	exit;

?>
