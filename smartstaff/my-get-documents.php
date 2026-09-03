<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* SELF endpoint — list the LOGGED-IN / service-asserted user's OWN rows from
	/* user_documents, for Crew Hub "My Documents".
	/*
	/* Today that is one row: their signed employment agreement, written by
	/* admin-add-contract.php at convert-to-crew. They signed it; they are entitled
	/* to a copy, and until now the only one they had was the email from signing.
	/*
	/* Self-scoped: the owner comes from goat_acting_user_id() (a SmartStaff
	/* session, OR the service key + the backend-asserted userID), NEVER a
	/* client-supplied `user`. There is no id parameter to tamper with — the query
	/* is scoped to the acting user, so a crew member can only ever see their own.
	/*
	/* Returns documents: [] for someone with nothing on file, NOT a 404. Most of
	/* the roster predates onboarding and will never have a contract; "none on
	/* file" is the normal state for the majority.
	/*
	/* The filename is NEVER returned — only has_pdf. The client asks for a
	/* document by doc_type and my-get-document-file.php resolves the name itself.
	/* user_uploads/ is one flat directory of {user}_{time}.pdf files covering
	/* licences, inductions, visas and contracts, so a filename on the wire would
	/* be a filename a client could guess.
	/*
	/* PHP 5.x — mysql_*, array(), no ??, no short arrays.
	*/

	$actingUser = (int) goat_acting_user_id();  /* emits JSON + exits on failure */

	if ($actingUser <= 0)
	{
		http_response_code(400);
		die('{"error":"missing user"}');
	}

	$res = mysql_query(
		"SELECT `id`, `doc_type`, `pdf_file`, `signed_at`, `version`, `created_ts`
		 FROM `user_documents` WHERE `user` = " . $actingUser . "
		 ORDER BY `doc_type` ASC"
	);

	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"query failed"}');
	}

	$out = array();

	while ($r = mysql_fetch_object($res))
	{
		$out[] = array(
			'id'         => (int) $r->id,
			'doc_type'   => $r->doc_type,
			'signed_at'  => $r->signed_at,
			'version'    => $r->version,
			'has_pdf'    => ($r->pdf_file !== null && $r->pdf_file !== ''),
			'created_ts' => ($r->created_ts !== null) ? (int) $r->created_ts : null
		);
	}

	echo json_encode(array('ok' => true, 'documents' => $out));

?>
