<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — commit ONE triage decision for THE GOAT's Licence Triage
	/* screen. Given a queue row id and 0..N canonical catalogue codes, it stamps the
	/* row canonical (type_triaged = 1) and splits a multi-ticket card into one row
	/* per code, each copy keeping the SAME evidence (pdf_file / has_image) so the
	/* card stays reachable from any split row.
	/*
	/* Own endpoint rather than chaining edit + add: one call, no partial-write
	/* window, no allow-list round-trip per code.
	/*
	/*   POST id=<int> codes=LF,WP,DG date_expiry=YYYY-MM-DD date_certified=YYYY-MM-DD
	/*
	/*   Both dates are OPTIONAL and independent. Malformed or absent leaves the
	/*   row's existing value untouched — never blanks it.
	/*
	/*   codes empty  -> type_triaged = 2 (unresolvable), type_canonical stays NULL
	/*   first code   -> UPDATE the row canonical, type_triaged = 1, optional dates
	/*   each extra   -> INSERT a copy with its own code, type_triaged = 1
	/*
	/* The row must be a non-induction, empty-venue licence — this path can never
	/* touch an induction (handover rule #2), enforced in the guard below.
	*/

	/*
	/* 1. gate — admin only (session cohort), mirroring admin-add-license.php. */

	if (goat_user_cohort() !== 'admin')
	{
		http_response_code(403);
		die('{"ok":false,"error":"forbidden"}');
	}

	/*
	/* 2. validate id — a positive integer. The row must exist, carry an empty
	/* `venue`, and NOT be an induction. Reading venue + type back and checking them
	/* is what keeps this path off induction rows even if a bad id is posted. */

	$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

	if ($id <= 0)
	{
		http_response_code(400);
		die('{"ok":false,"error":"missing id"}');
	}

	$res = mysql_query(
		"SELECT `user`, `type`, pdf_file, has_image, date_certified, date_expiry, venue
		 FROM user_licenses
		 WHERE id = " . $id . " LIMIT 1"
	);

	if ($res === false || mysql_num_rows($res) === 0)
	{
		http_response_code(404);
		die('{"ok":false,"error":"not found"}');
	}

	$rowObj = mysql_fetch_object($res);

	$venue = $rowObj->venue;
	if (!($venue === null || $venue === '' || $venue === '0' || $venue === 0))
	{
		http_response_code(400);
		die('{"ok":false,"error":"not a licence row"}');
	}

	if ($rowObj->type === 'Induction Certificate')
	{
		http_response_code(400);
		die('{"ok":false,"error":"induction row"}');
	}

	/*
	/* 3. validate the codes. Split on comma, trim, drop blanks, and check EVERY code
	/* against the 41-value catalogue allow-list (kept in sync with app.py's
	/* LICENCE_CATALOGUE and admin-add-license.php's $allowedTypes). Any invalid code
	/* rejects the whole request — nothing is written. */

	$allowedTypes = array(
		'SB', 'SI', 'SA', 'DG', 'RB', 'RI', 'RA', 'BS', 'BA', 'TO', 'ES',
		'CT', 'CS', 'CD', 'CP', 'CB', 'CV', 'CN', 'C2', 'C6', 'C1', 'C0',
		'HM', 'HP', 'LF', 'LO', 'WP', 'RS', 'PB', 'TV',
		'CI', 'WAH', 'EWPSOA', 'FA', 'TC', 'WWCC',
		'LR', 'MR', 'HR', 'HC', 'MC'
	);

	$codesRaw = isset($_POST['codes']) ? $_POST['codes'] : '';
	$codes    = array();

	$parts = explode(',', $codesRaw);
	foreach ($parts as $part)
	{
		$c = trim($part);
		if ($c === '')
			continue;

		if (!in_array($c, $allowedTypes, true))
		{
			http_response_code(400);
			die('{"ok":false,"error":"invalid code"}');
		}

		$codes[] = $c;
	}

	/*
	/* 4. optional expiry — a strict YYYY-MM-DD or nothing. Anything malformed is
	/* ignored (the existing date_expiry is left untouched). */

	$hasExpiry = false;
	$expirySql = 'NULL';
	if (isset($_POST['date_expiry'])
	    && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($_POST['date_expiry'])))
	{
		$hasExpiry = true;
		$expirySql = "'" . mysql_real_escape_string(trim($_POST['date_expiry'])) . "'";
	}

	/*
	/* 4b. optional date of attainment — same shape, same strictness. Issue dates are
	/* on the cards and triage is the only moment anyone opens the images, so the
	/* field is captured here or not at all. Independent of expiry: supplying one
	/* never touches the other. */

	$hasCert     = false;
	$certPostSql = 'NULL';
	if (isset($_POST['date_certified'])
	    && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($_POST['date_certified'])))
	{
		$hasCert     = true;
		$certPostSql = "'" . mysql_real_escape_string(trim($_POST['date_certified'])) . "'";
	}

	/*
	/* 5. empty codes = can't-tell. Mark the row unresolvable (type_triaged = 2),
	/* leave type_canonical NULL, and stop. This is where volunteer WWCCs and
	/* unreadable cards land — out of the queue, but never falsely canonicalised. */

	if (count($codes) === 0)
	{
		mysql_query(
			"UPDATE user_licenses SET type_triaged = 2 WHERE id = " . $id
		);

		if (mysql_error() !== '')
		{
			http_response_code(500);
			die('{"ok":false,"error":"update failed"}');
		}

		echo json_encode(array(
			'ok'       => true,
			'id'       => $id,
			'triaged'  => 2,
			'inserted' => array()
		));
		exit;
	}

	/*
	/* 6. first code UPDATEs the row in place: canonical value, triaged = 1, and the
	/* new dates if they were supplied. `type` stays as the original raw string — it
	/* is the audit trail of what the card literally said. */

	$firstEsc  = mysql_real_escape_string($codes[0]);
	$setExpiry = $hasExpiry ? (", date_expiry = " . $expirySql) : '';
	$setCert   = $hasCert ? (", date_certified = " . $certPostSql) : '';

	mysql_query(
		"UPDATE user_licenses
		 SET type_canonical = '" . $firstEsc . "', type_triaged = 1" . $setExpiry . $setCert . "
		 WHERE id = " . $id
	);

	if (mysql_error() !== '')
	{
		http_response_code(500);
		die('{"ok":false,"error":"update failed"}');
	}

	/*
	/* 7. each additional code INSERTs a copy of the row, carrying the SAME evidence
	/* (pdf_file, has_image), with its own canonical code and triaged = 1. Copying
	/* the evidence is what keeps the card reachable from any split row. BOTH dates
	/* on a copy follow the same rule as the UPDATE: the supplied value if one was
	/* given, else the row's original — so a confirmed date reaches every split row
	/* rather than only the one updated in place. */

	$user     = (int) $rowObj->user;
	$typeEsc  = mysql_real_escape_string($rowObj->type);
	$hasImage = (int) $rowObj->has_image;

	$pdfSql = 'NULL';
	if ($rowObj->pdf_file !== null && $rowObj->pdf_file !== '')
		$pdfSql = "'" . mysql_real_escape_string($rowObj->pdf_file) . "'";

	$certSql = 'NULL';
	if ($hasCert)
		$certSql = $certPostSql;
	else if ($rowObj->date_certified !== null && $rowObj->date_certified !== '0000-00-00')
		$certSql = "'" . mysql_real_escape_string($rowObj->date_certified) . "'";

	if ($hasExpiry)
	{
		$copyExpirySql = $expirySql;
	}
	else if ($rowObj->date_expiry !== null && $rowObj->date_expiry !== '0000-00-00')
	{
		$copyExpirySql = "'" . mysql_real_escape_string($rowObj->date_expiry) . "'";
	}
	else
	{
		$copyExpirySql = 'NULL';
	}

	$inserted = array();

	$i = 1;
	while ($i < count($codes))
	{
		$codeEsc = mysql_real_escape_string($codes[$i]);

		mysql_query(
			"INSERT INTO user_licenses
			 (`user`, `type`, type_canonical, type_triaged, pdf_file, has_image, date_certified, date_expiry)
			 VALUES (" . $user . ", '" . $typeEsc . "', '" . $codeEsc . "', 1, "
			 . $pdfSql . ", " . $hasImage . ", " . $certSql . ", " . $copyExpirySql . ")"
		);

		if (mysql_error() !== '')
		{
			http_response_code(500);
			die('{"ok":false,"error":"insert failed"}');
		}

		$inserted[] = (int) mysql_insert_id();

		$i = $i + 1;
	}

	/*
	/* 8. done. */

	echo json_encode(array(
		'ok'        => true,
		'id'        => $id,
		'triaged'   => 1,
		'canonical' => $codes[0],
		'inserted'  => $inserted
	));

?>
