<?php

	/*
	/* global file */

	include('../../global.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SELF endpoint — any logged-in user may ask who they are. No admin gate.
	/* Mirrors the get-unavailabilities.php pattern: keyed entirely on the
	/* logged-in $_SESSION userID, so each caller can only ever learn about
	/* themselves.
	*/

	if (!$user->checkSession())
	{
		http_response_code(401);
		die('{"error":"Not logged in"}');
	}

	$userID = (int) $_SESSION[SITE_KEY]['userID'];

	/*
	/* Identity lookup.
	/*
	/* We try to read the `cohort` column first; if it does not exist yet
	/* (the ALTER TABLE has not been run), mysql_query() returns false and we
	/* retry WITHOUT it, defaulting cohort to 'crew'. This lets the endpoint be
	/* deployed safely BEFORE the migration — it degrades to "everyone is crew
	/* except admin logins" rather than 500-ing.
	*/

	$cohort_supported = true;

	$sql = "SELECT id, ein, firstname, lastname, usergroupID, cohort
	        FROM users WHERE id = $userID LIMIT 1";
	$res = mysql_query($sql);

	if ($res === false)
	{
		$cohort_supported = false;
		$sql = "SELECT id, ein, firstname, lastname, usergroupID
		        FROM users WHERE id = $userID LIMIT 1";
		$res = mysql_query($sql);
	}

	if ($res === false || mysql_num_rows($res) == 0)
	{
		http_response_code(500);
		die('{"error":"identity lookup failed"}');
	}

	$row         = mysql_fetch_object($res);
	$usergroupID = (int) $row->usergroupID;

	/*
	/* Cohort resolution rule:
	/*
	/*   usergroupID == 1 (admin login)  -> always 'admin'
	/*   otherwise                       -> users.cohort, restricted to
	/*                                      {leadership, crew}, default 'crew'
	/*
	/* IMPORTANT: a non-admin login can NEVER resolve to 'admin', even if the
	/* cohort column somehow contains 'admin'. Admin is tied to the usergroupID
	/* == 1 login only — the cohort column can grant Leadership but not Admin,
	/* so it can't be used to escalate a crew-group account to full access.
	*/

	if ($usergroupID == 1)
	{
		$cohort = 'admin';
	}
	else
	{
		$cohort = 'crew';
		if ($cohort_supported && isset($row->cohort))
		{
			$c = strtolower(trim($row->cohort));
			if ($c == 'leadership')
				$cohort = 'leadership';
		}
	}

	/*
	/* "Lastname, Firstname" — matches list-crew-bulk.php / get-shifts-bulk.php
	*/

	$display_name = trim($row->lastname);
	if (strlen(trim($row->firstname)) > 0)
		$display_name .= ', ' . trim($row->firstname);

	echo json_encode(array(
		'user_id'     => (int) $row->id,
		'ein'         => $row->ein,
		'firstname'   => $row->firstname,
		'lastname'    => $row->lastname,
		'name'        => $display_name,
		'usergroupID' => $usergroupID,
		'cohort'      => $cohort,
	));

?>
