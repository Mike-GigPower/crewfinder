<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — ONE crew member's rows from user_documents, for the
	/* Manage Crew -> Documents section.
	/*
	/* First and currently only doc_type is 'contract': the signed employment
	/* agreement, written here by admin-add-contract.php during convert-to-crew.
	/* This is the READ half that convert-B shipped without — the contract has been
	/* landing in this table with nothing anywhere able to show it.
	/*
	/* ADMIN ONLY, matching admin-get-visa.php and the licence surface. An
	/* employment agreement carries the person's name, signature and commencement
	/* terms; the same gate applies wherever that surfaces. Not goat_can_read_all()
	/* — leadership and operations see nothing here.
	/*
	/* Returns documents: [] for a crew member with nothing on file, NOT a 404.
	/* Most of the roster predates onboarding and will never have a contract;
	/* "none on file" is the normal state for the majority and a 404 would read to
	/* the client as a broken lookup. Same rule admin-get-visa.php follows with
	/* visa: null.
	/*
	/* The filename is NEVER returned — only has_pdf. The client asks for a
	/* document by doc_type and admin-get-document-file.php resolves the name
	/* itself. user_uploads/ is a single flat directory holding licence, induction,
	/* visa AND contract PDFs, every one of them named {user}_{time}.pdf, so a
	/* filename on the wire would be a filename the client could guess.
	/*
	/* PHP 5.x — mysql_*, array(), no ??, no short arrays.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		http_response_code(403);
		die('{"error":"Admin only"}');
	}

	$user = isset($_GET['user']) ? (int) $_GET['user'] : 0;

	if ($user <= 0)
	{
		http_response_code(400);
		die('{"error":"user required"}');
	}

	$res = mysql_query(
		"SELECT `id`, `user`, `doc_type`, `pdf_file`, `signed_at`, `version`, `created_ts`
		 FROM `user_documents` WHERE `user` = " . $user . "
		 ORDER BY `doc_type` ASC"
	);

	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"query failed: ' . addslashes(mysql_error()) . '"}');
	}

	/*
	/* Numerics are cast — mysql_* returns every value as a string, and an uncast
	/* id or created_ts arrives as "12" and breaks a strict comparison in the
	/* consumer silently. signed_at and version stay as-is including NULL: a
	/* contract row with no recorded signing date is a real state (the metadata is
	/* optional at the write end) and must not be dressed up as a date.
	*/

	$out = array();

	while ($r = mysql_fetch_object($res))
	{
		$out[] = array(
			'id'         => (int) $r->id,
			'user'       => (int) $r->user,
			'doc_type'   => $r->doc_type,
			'signed_at'  => $r->signed_at,
			'version'    => $r->version,
			'has_pdf'    => ($r->pdf_file !== null && $r->pdf_file !== ''),
			'created_ts' => ($r->created_ts !== null) ? (int) $r->created_ts : null
		);
	}

	echo json_encode(array('ok' => true, 'documents' => $out));

?>
