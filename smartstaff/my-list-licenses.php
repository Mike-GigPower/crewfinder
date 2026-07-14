<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SELF endpoint — read every licence row for the LOGGED-IN / service-asserted
	/* user (Crew Hub "My Licences"). Self-scoped: the acting user comes from
	/* goat_acting_user_id() (a SmartStaff session, OR the service key + the
	/* backend-asserted userID), NEVER a client-supplied target. Returns RAW rows;
	/* the green/amber/red/grey status pill is derived in Crew Hub
	/* (lib/licences.ts), mirroring THE GOAT's compliance_status — never here.
	/*
	/* Induction rows are EXCLUDED. DISCRIMINATOR = the `venue` column, NOT the type
	/* string: native SmartStaff inductions are typed by venue+year (e.g.
	/* 'AAMI 2025', 'JCA 2026'), so a type-only filter leaks them into the licence
	/* list. What marks an induction is a SET `venue`; licence rows never set it. We
	/* filter on empty venue and keep the type check as harmless extra cover.
	/*
	/* Mirrors admin-list-licenses.php exactly, except the gate: self-scope via
	/* goat_acting_user_id() in place of the admin cohort + an explicit ?user=.
	*/

	$user = (int) goat_acting_user_id();  /* emits JSON + exits on failure */

	if ($user <= 0)
	{
		http_response_code(400);
		die('{"error":"missing user"}');
	}

	/*
	/* read the rows (never the induction rows). */

	$res = mysql_query(
		"SELECT id, `user`, type, pdf_file, has_image, date_certified, date_expiry
		 FROM user_licenses
		 WHERE `user` = " . $user . "
		   AND (venue IS NULL OR venue = 0 OR venue = '')
		   AND type != 'Induction Certificate'
		 ORDER BY type"
	);

	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"read failed"}');
	}

	/*
	/* Map SQL NULL and the junk value 0000-00-00 both to JSON null, so the app
	/* never has to defend against a zero-date masquerading as a real date. */

	$licences = array();

	while ($row = mysql_fetch_object($res))
	{
		$certified = $row->date_certified;
		if ($certified === null || $certified === '0000-00-00')
			$certified = null;

		$expiry = $row->date_expiry;
		if ($expiry === null || $expiry === '0000-00-00')
			$expiry = null;

		$licences[] = array(
			'id'             => (int) $row->id,
			'user'           => (int) $row->user,
			'type'           => $row->type,
			'pdf_file'       => ($row->pdf_file !== null && $row->pdf_file !== '') ? $row->pdf_file : null,
			'has_image'      => (int) $row->has_image,
			'date_certified' => $certified,
			'date_expiry'    => $expiry
		);
	}

	echo json_encode(array('ok' => true, 'licences' => $licences));

?>
