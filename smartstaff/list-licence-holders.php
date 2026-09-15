<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');

	header('Content-Type: application/json');

	/*
	/* THE LICENCE REGISTER — every non-induction licence row on the system,
	/* joined to its crew member, soonest expiry first. Backs THE GOAT's
	/* Administration -> Licence Register screen.
	/*
	/* One row per (crew member x licence). The Crew Finder and the Crew Records
	/* filter both answer "narrow the roster to people holding X"; this answers
	/* the different question "what is on file, and is it current".
	/*
	/* READ-ALL COHORTS, not admin-only. The visa register is admin-only because
	/* immigration data is different in kind; a licence is a capability record and
	/* Operations has a working reason to check whether somebody holds a ticket.
	/* goat_can_read_all() is the same gate list as api_licence_holders' Python
	/* decorator, so the two agree by construction rather than by coincidence.
	/*
	/* DELIBERATELY NOT FILTERED to active = '1'. This is a compliance surface and
	/* somebody whose roster flag is wrong is exactly who needs to be visible: on
	/* this database 3,270 crew carry a blank `active` and 115 of them worked in
	/* 2026, and as at 31 Aug NOT ONE untriaged licence row belonged to an
	/* active = '1' crew member. An active-only register would show the cleanest
	/* 389 records and hide the data that most needs looking at. The roster flag is
	/* RETURNED and the client badges it.
	/*
	/* UNTRIAGED ROWS ARE INCLUDED — type_canonical IS NULL is returned, not
	/* filtered. list-crew-bulk.php excludes them because a filter can only match
	/* a code; a register whose job is "what is on file" must show the row typed
	/* `Forklift LF` that nobody has classified yet, badged as untriaged. Filtering
	/* them out is how the triage queue stays invisible, and it is why the 11
	/* misfiled venue inductions and crew 10442's IMMI VISA row are findings rather
	/* than absences.
	/*
	/* DISCRIMINATOR = the `venue` column, NOT the type string, per handover rule
	/* #2 and admin-list-licenses.php: only GOAT-written inductions carry
	/* type='Induction Certificate', while NATIVE SmartStaff inductions are typed
	/* by venue+year ('AAMI 2025', 'MARVEL 2025'), so a type-only filter leaks
	/* them in. Empty venue is what marks a licence; the type check is kept as
	/* harmless extra cover.
	/*
	/* PDF BYTES ARE NOT SERVED HERE. The register reports whether a document
	/* exists; /api/licence/<id>/file serves it behind a deliberate click.
	/*
	/* PHP 5.x — array(), no ??, no short arrays. PDO via goat_pdo(), not mysql_*.
	*/

	if (!goat_can_read_all())
	{
		goat_json_error(403, 'Admin, Leadership or Operations required');
		exit;
	}

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		goat_json_error(503, 'Licence storage is unavailable');
		exit;
	}

	/*
	/* ORDER BY puts a real expiry first and sinks undated rows to the bottom.
	/* BOTH null and '0000-00-00' have to be tested: the junk value is a real
	/* stored value on this column and it sorts as the year 0000, which would put
	/* every unrecorded expiry at the TOP of a screen whose whole argument is its
	/* ordering. lastname breaks the tie so the order is stable between fetches.
	*/

	$sql = "SELECT l.`id` AS licence_id, l.`user` AS user_id,
	               u.`ein`, u.`firstname`, u.`lastname`, u.`active`, u.`usergroupID`,
	               l.`type`, l.`type_canonical`, l.`type_triaged`,
	               l.`date_certified`, l.`date_expiry`,
	               l.`pdf_file`, l.`has_image`
	        FROM `user_licenses` l
	        INNER JOIN `users` u ON u.`id` = l.`user`
	        WHERE (l.`venue` IS NULL OR l.`venue` = 0 OR l.`venue` = '')
	          AND l.`type` != 'Induction Certificate'
	        ORDER BY (l.`date_expiry` IS NULL OR l.`date_expiry` = '0000-00-00') ASC,
	                 l.`date_expiry` ASC, u.`lastname` ASC";

	/*
	/* ERRMODE_EXCEPTION means a broken query throws rather than returning false,
	/* so the try/catch is the error check -- there is no `=== false` branch to
	/* write. The message is logged, never returned: it can carry schema detail.
	*/

	try
	{
		$stmt = $pdo->query($sql);
		$found = $stmt->fetchAll();
	}
	catch (PDOException $e)
	{
		error_log('list-licence-holders: query failed: ' . $e->getMessage());
		goat_json_error(500, 'Licence register read failed');
		exit;
	}

	/*
	/* ZERO ROWS IS A READ FAILURE HERE, NOT A CLEAN RESULT — and this is the
	/* opposite of list-visa-workers.php, deliberately. user_visa starts empty and
	/* stays empty until somebody records a visa, so an empty visa register is
	/* correct. user_licenses holds ~16,800 rows; if this query returns none, the
	/* table is unreachable or the WHERE clause has been broken, and reporting
	/* "no licences on file" would be the compliance-surface equivalent of the
	/* Induction Checker rendering a perfect score off an empty payload.
	/*
	/* Refused with 500 and a message the client must relay verbatim, so nobody
	/* reads a read failure as a clean bill of health.
	*/

	if (count($found) === 0)
	{
		error_log('list-licence-holders: zero rows returned — treating as a read failure');
		goat_json_error(500, 'No licence records came back, which is a read failure rather than a clean result.');
		exit;
	}

	$rows = array();

	foreach ($found as $r)
	{
		/*
		/* roster_flag mirrors the three real states of users.active on this
		/* database — '1', '0' and BLANK. Blank is not "inactive": it is the
		/* largest bucket and it contains people who work. Collapsing it into
		/* inactive is how an earlier analysis reached a confident wrong answer.
		/* Identical mapping to list-visa-workers.php, on purpose.
		*/

		/*
		/* CAST BEFORE COMPARING, and this is the one place this endpoint
		/* cannot copy list-visa-workers.php verbatim. mysql_fetch_object()
		/* returns every column as a STRING, so `$r->active === '1'` is safe
		/* there. PDO with EMULATE_PREPARES => false does NOT promise that —
		/* mysqlnd can hand back native ints for integer columns — so an
		/* identity comparison against '1' would silently fail and badge the
		/* entire roster 'unflagged'. A wrong answer that looks like data, on a
		/* compliance screen, which is the worst shape of bug this project keeps
		/* meeting. (string) makes it true either way.
		*/

		$active = (string) $r['active'];

		if ($active === '1')
			$flag = 'active';
		else if ($active === '0')
			$flag = 'inactive';
		else
			$flag = 'unflagged';

		/*
		/* SQL NULL and the junk value 0000-00-00 both map to JSON null — the
		/* same normalisation admin-list-licenses.php and list-crew-bulk.php
		/* apply to these columns, so the app never sees a zero-date
		/* masquerading as a real one.
		*/

		$certified = $r['date_certified'];
		if ($certified === null || $certified === '0000-00-00')
			$certified = null;

		$expiry = $r['date_expiry'];
		if ($expiry === null || $expiry === '0000-00-00')
			$expiry = null;

		/* An untriaged row is NULL here; an empty string is the same fact
		/* wearing a different shape, so both land on null and the client has
		/* one untriaged test rather than two. */

		$canonical = $r['type_canonical'];
		if ($canonical === '')
			$canonical = null;

		$rows[] = array(
			'licence_id'     => (int) $r['licence_id'],
			'user_id'        => (int) $r['user_id'],
			'ein'            => $r['ein'],
			'name'           => trim(html_entity_decode($r['firstname'] . ' ' . $r['lastname'], ENT_QUOTES, 'UTF-8')),
			'roster_flag'    => $flag,
			'is_crew'        => ((int) $r['usergroupID'] === 3),
			'type'           => $r['type'],
			'type_canonical' => $canonical,
			'type_triaged'   => (int) $r['type_triaged'],
			'date_certified' => $certified,
			'date_expiry'    => $expiry,
			'has_pdf'        => ($r['pdf_file'] !== null && $r['pdf_file'] !== ''),
			'has_image'      => ((int) $r['has_image'] === 1)
		);
	}

	echo json_encode(array('ok' => true, 'rows' => $rows, 'total' => count($rows)));

?>
