<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* READ-ALL endpoint — per-code licence HOLDER COUNTS for the Crew Finder
	/* licence chips. Same gate and the same job as list-groups.php: tell a filter
	/* UI which values are worth offering.
	/*
	/* COUNTS ONLY. Display names live in app.py's LICENCE_CATALOGUE, the single
	/* source, and must never be duplicated here — a second copy of the catalogue
	/* is a second thing to keep in step.
	/*
	/* Counted over ACTIVE CREW only (usergroupID = 3, active = '1') so the number
	/* beside a chip is the number of people a search could actually return.
	/* COUNT(DISTINCT `user`) because one crew member can hold two rows for the
	/* same code (a renewal filed alongside the original), and that is one holder.
	/*
	/* Induction rows can never appear: the empty-venue discriminator is the same
	/* one admin-list-licenses.php uses, with the explicit type check as harmless
	/* extra cover (handover rule #2, enforced at every licence read/write
	/* boundary). Untriaged rows are excluded by type_canonical IS NOT NULL — an
	/* unconfirmed free-text type cannot be counted against a code safely.
	*/

	if (!goat_can_read_all())
		goat_json_error(403, 'forbidden');

	$res = mysql_query(
		"SELECT l.type_canonical AS code, COUNT(DISTINCT l.`user`) AS holders
		 FROM user_licenses l
		 INNER JOIN users u ON u.id = l.`user`
		 WHERE u.usergroupID = 3 AND u.active = '1'
		   AND l.type_canonical IS NOT NULL
		   AND (l.venue IS NULL OR l.venue = 0 OR l.venue = '')
		   AND l.`type` != 'Induction Certificate'
		 GROUP BY l.type_canonical"
	);

	if ($res === false)
		goat_json_error(500, 'licence count query failed: ' . mysql_error());

	$counts = array();

	while ($row = mysql_fetch_object($res))
		$counts[$row->code] = (int) $row->holders;

	/*
	/* (object) on emit: an empty PHP array json_encodes as [], but this is a MAP
	/* keyed by licence code and a consumer expecting an object would break on an
	/* array. Same reason list-crew-bulk.php casts induction_policy.
	*/

	echo json_encode(array('ok' => true, 'counts' => (object) $counts));

?>
