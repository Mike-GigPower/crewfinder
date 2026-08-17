<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — the licence TRIAGE QUEUE for THE GOAT's Administration ->
	/* Licence Triage screen. Returns every crew licence row that has NOT yet been
	/* triaged: empty-venue (never an induction), type_canonical still NULL, and
	/* type_triaged = 0. Each row is joined to its crew member (ein + name) so the
	/* operator can confirm the recorded free-text type against the stored card.
	/*
	/* Rows are RAW; nothing is derived here. Ordered by the original `type` string
	/* (then id) so identical types batch together — the operator's eye stays on one
	/* card layout at a time (all the forklift rows adjacent, then the next family).
	/*
	/* Induction rows can never appear: the empty-venue filter is the same
	/* discriminator admin-list-licenses.php uses, and the explicit type check is
	/* kept as harmless extra cover (handover rule #2, enforced at every licence
	/* read/write boundary).
	*/

	/*
	/* 1. gate — admin only (session cohort), mirroring admin-list-licenses.php. */

	if (goat_user_cohort() !== 'admin')
	{
		http_response_code(403);
		die('{"error":"forbidden"}');
	}

	/*
	/* 2. read the queue. usergroupID = 3 is crew; active = '1' skips archived
	/* members so the operator never spends a decision on someone long gone. */

	$res = mysql_query(
		"SELECT l.id, l.`user`, l.`type`, l.pdf_file, l.has_image,
		        l.date_certified, l.date_expiry,
		        u.ein, u.firstname, u.lastname
		 FROM user_licenses l
		 INNER JOIN users u ON u.id = l.`user`
		 WHERE u.usergroupID = 3 AND u.active = '1'
		   AND (l.venue IS NULL OR l.venue = 0 OR l.venue = '')
		   AND l.`type` != 'Induction Certificate'
		   AND l.type_canonical IS NULL
		   AND l.type_triaged = 0
		 ORDER BY l.`type`, l.id"
	);

	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"read failed"}');
	}

	/*
	/* 3. build the rows. NULL and the junk value 0000-00-00 both collapse to JSON
	/* null, exactly as admin-list-licenses.php does, so the app never has to defend
	/* against a zero-date masquerading as a real date. */

	$rows = array();

	while ($row = mysql_fetch_object($res))
	{
		$certified = $row->date_certified;
		if ($certified === null || $certified === '0000-00-00')
			$certified = null;

		$expiry = $row->date_expiry;
		if ($expiry === null || $expiry === '0000-00-00')
			$expiry = null;

		$rows[] = array(
			'id'             => (int) $row->id,
			'user'           => (int) $row->user,
			'type'           => $row->type,
			'pdf_file'       => ($row->pdf_file !== null && $row->pdf_file !== '') ? $row->pdf_file : null,
			'has_image'      => (int) $row->has_image,
			'date_certified' => $certified,
			'date_expiry'    => $expiry,
			'ein'            => $row->ein,
			'firstname'      => $row->firstname,
			'lastname'       => $row->lastname
		);
	}

	echo json_encode(array('ok' => true, 'rows' => $rows, 'total' => count($rows)));

?>
