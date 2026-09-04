<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — ONE crew member's visa record from user_visa, for the
	/* Manage Crew -> Visa section.
	/*
	/* ADMIN ONLY, matching the recruitment work-eligibility reveal: passport
	/* numbers, grant numbers and visa conditions are immigration PII and the same
	/* gate applies wherever they surface. Not goat_can_read_all() — leadership and
	/* operations see nothing here.
	/*
	/* Returns visa: null for a crew member with no record, NOT a 404. Most of the
	/* roster has no visa and never will; "none on file" is a normal state and a
	/* 404 would read to the client as a broken lookup.
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
		"SELECT `id`, `user`, `work_eligibility_status`, `is_visa_worker`,
		        `passport_number`, `passport_country`, `visa_subclass`, `vevo_pdf`,
		        `visa_grant_number`, `trn`, `visa_grant_date`, `visa_expiry`,
		        `visa_conditions`, `has_work_limitation`, `vevo_verified_at`,
		        `vevo_verified_by`, `visa_pdf`, `updated_ts`
		 FROM `user_visa` WHERE `user` = " . $user . " LIMIT 1"
	);

	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"query failed: ' . addslashes(mysql_error()) . '"}');
	}

	if (mysql_num_rows($res) == 0)
	{
		echo '{"ok":true,"visa":null}';
		exit;
	}

	$r = mysql_fetch_object($res);

	/*
	/* Numerics are cast — mysql_* returns every value as a string, and an uncast
	/* has_work_limitation arrives as "1"/"0" and breaks a strict comparison in the
	/* consumer silently. has_work_limitation stays NULL when NULL: "unclear" is a
	/* real third state (bridging visas), distinct from "no limitation".
	*/

	echo json_encode(array(
		'ok'   => true,
		'visa' => array(
			'id'                      => (int) $r->id,
			'user'                    => (int) $r->user,
			'work_eligibility_status' => $r->work_eligibility_status,
			'is_visa_worker'          => ((int) $r->is_visa_worker === 1),
			'passport_number'         => $r->passport_number,
			'passport_country'        => $r->passport_country,
			'visa_subclass'           => $r->visa_subclass,
			/* Presence only, never the filename: the client passes a user id to
			/* admin-get-vevo-file.php and that endpoint resolves the name itself. */
			'has_vevo_pdf'            => ($r->vevo_pdf !== null && $r->vevo_pdf !== ''),
			'visa_grant_number'       => $r->visa_grant_number,
			'trn'                     => $r->trn,
			'visa_grant_date'         => $r->visa_grant_date,
			'visa_expiry'             => $r->visa_expiry,
			'visa_conditions'         => ($r->visa_conditions !== null) ? $r->visa_conditions : '',
			'has_work_limitation'     => ($r->has_work_limitation !== null) ? ((int) $r->has_work_limitation === 1) : null,
			'vevo_verified_at'        => $r->vevo_verified_at,
			'vevo_verified_by'        => $r->vevo_verified_by,
			'has_pdf'                 => ($r->visa_pdf !== null && $r->visa_pdf !== ''),
			'updated_ts'              => ($r->updated_ts !== null) ? (int) $r->updated_ts : null
		)
	));

?>
