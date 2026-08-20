<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');
	include('licence-catalogue-lib.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* READ-ALL endpoint — the licence catalogue, for THE GOAT and Crew Hub.
	/*
	/* THE GOAT has no direct MySQL access, so this is how it reads the table that
	/* replaces the five hardcoded copies of the taxonomy: app.py's
	/* LICENCE_CATALOGUE, website/lib/licences.ts, and the $allowedTypes array in
	/* admin-add-license.php, admin-edit-license.php and my-add-license.php.
	/*
	/* Gated on goat_can_read_all() rather than admin-only, mirroring
	/* get-induction-catalogue.php and list-licences.php: the Crew Finder licence
	/* chips are the sibling of the group chips, and an admin-only gate would break
	/* them the day Crew Finder opens to Operations.
	/*
	/* PUBLISHED ONLY by default. Unpublishing a code hides its Crew Finder chip
	/* and drops it from the entry dropdowns; rows already on file keep rendering,
	/* because consumers fall back to their own name map and then to the bare code.
	/*
	/* ?all=1 drops the published filter, for the Phase 2 editor — which cannot
	/* edit a row it can't see. ADMIN-ONLY on that branch: the unpublished set is
	/* the editor's working state, not a read-all concern.
	/*
	/* Row shape, casting and the NULL handling on validity_months all live in
	/* licence-catalogue-lib.php, which every other consumer of this table shares.
	*/

	if (!goat_can_read_all())
		goat_json_error(403, 'forbidden');

	$wantAll = (isset($_GET['all']) && $_GET['all'] === '1');

	if ($wantAll && goat_user_cohort() !== 'admin')
		goat_json_error(403, 'forbidden');

	/*
	/* The query itself lives in licence-catalogue-lib.php, shared with the three
	/* write endpoints and my-list-licenses.php, so there is exactly one SELECT
	/* against this table.
	*/

	$rows = goat_licence_catalogue_rows($wantAll);

	if ($rows === false)
		goat_json_error(500, 'licence catalogue unavailable');

	echo json_encode(array('ok' => true, 'rows' => $rows));

?>
