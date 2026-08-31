<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — the visa register: every user_visa row, joined to its crew
	/* member. Backs the Administration -> Visa screen.
	/*
	/* ADMIN ONLY, same reasoning as admin-get-visa.php.
	/*
	/* DELIBERATELY NOT FILTERED to active = '1'. This is a compliance surface, and
	/* somebody whose roster flag is wrong is exactly who needs to be visible: on
	/* this database 3,270 crew carry a blank `active` and 115 of them worked in
	/* 2026, so an active-only register would hide people who are working. The
	/* roster flag is RETURNED and the client badges it.
	/*
	/* Passport number and TRN are NOT returned. The register answers "who holds a
	/* visa, until when, and has it been checked" — the identity documents belong on
	/* the one-crew-member view, behind a deliberate click, not in a list that is
	/* open on someone's second monitor.
	/*
	/* PHP 5.x — mysql_*, array(), no ??, no short arrays.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		http_response_code(403);
		die('{"error":"Admin only"}');
	}

	/*
	/* ORDER BY (visa_expiry IS NULL) first so undated records sink to the bottom
	/* rather than sorting as the year 0000 — the screen exists to put the soonest
	/* expiry at the top, and a NULL is not "expires first".
	*/

	$res = mysql_query(
		"SELECT v.`user` AS user_id, v.`id` AS visa_id,
		        u.`ein`, u.`firstname`, u.`lastname`, u.`active`, u.`usergroupID`,
		        v.`work_eligibility_status`, v.`visa_subclass`, v.`visa_grant_date`,
		        v.`visa_expiry`, v.`visa_conditions`, v.`has_work_limitation`,
		        v.`vevo_verified_at`, v.`vevo_verified_by`, v.`visa_pdf`,
		        v.`updated_ts`
		 FROM `user_visa` v
		 INNER JOIN `users` u ON u.`id` = v.`user`
		 ORDER BY (v.`visa_expiry` IS NULL) ASC, v.`visa_expiry` ASC, u.`lastname` ASC"
	);

	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"query failed: ' . addslashes(mysql_error()) . '"}');
	}

	$rows = array();

	while ($r = mysql_fetch_object($res))
	{
		/*
		/* roster_flag mirrors the three real states of users.active on this
		/* database — '1', '0' and BLANK. Blank is not "inactive": it is the
		/* largest bucket and it contains people who work. Collapsing it into
		/* inactive is how an earlier analysis reached a confident wrong answer.
		*/

		if ($r->active === '1')
			$flag = 'active';
		else if ($r->active === '0')
			$flag = 'inactive';
		else
			$flag = 'unflagged';

		$rows[] = array(
			'user_id'                 => (int) $r->user_id,
			'visa_id'                 => (int) $r->visa_id,
			'ein'                     => $r->ein,
			'name'                    => trim(html_entity_decode($r->firstname . ' ' . $r->lastname, ENT_QUOTES, 'UTF-8')),
			'roster_flag'             => $flag,
			'is_crew'                 => ((int) $r->usergroupID === 3),
			'work_eligibility_status' => $r->work_eligibility_status,
			'visa_subclass'           => $r->visa_subclass,
			'visa_grant_date'         => $r->visa_grant_date,
			'visa_expiry'             => $r->visa_expiry,
			'visa_conditions'         => ($r->visa_conditions !== null) ? $r->visa_conditions : '',
			'has_work_limitation'     => ($r->has_work_limitation !== null) ? ((int) $r->has_work_limitation === 1) : null,
			'vevo_verified_at'        => $r->vevo_verified_at,
			'vevo_verified_by'        => $r->vevo_verified_by,
			'has_pdf'                 => ($r->visa_pdf !== null && $r->visa_pdf !== ''),
			'updated_ts'              => ($r->updated_ts !== null) ? (int) $r->updated_ts : null
		);
	}

	/*
	/* An EMPTY register is a legitimate answer here, unlike the licence catalogue
	/* where zero rows means the seed never ran. user_visa starts empty and stays
	/* empty until somebody records a visa, so ok:true with rows:[] is correct and
	/* the client must render "none on file", not an error.
	*/

	echo json_encode(array('ok' => true, 'rows' => $rows, 'total' => count($rows)));

?>
