<?php

	/*
	/* global file */

	include('../../global.php');

	/*
	/* shared cohort resolver — single source of truth for the allow-list.
	/* Use the SAME include line the bulk endpoints (e.g. list-crew-bulk.php)
	/* already use for cohort.php; adjust the path here if theirs differs.
	*/

	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SELF endpoint — any logged-in user may ask who they are. No admin gate.
	/* Keyed entirely on the logged-in $_SESSION userID, so each caller can only
	/* ever learn about themselves.
	*/

	if (!$user->checkSession())
	{
		http_response_code(401);
		die('{"error":"Not logged in"}');
	}

	$userID = (int) $_SESSION[SITE_KEY]['userID'];

	/*
	/* Identity lookup — names / ein / usergroupID only. The cohort VALUE comes
	/* from goat_user_cohort() below, so the {leadership, operations, crew}
	/* allow-list lives in exactly ONE place (cohort.php) and this endpoint can
	/* never drift from the gating used by the bulk endpoints — or from what the
	/* Gig Power website reads here.
	*/

	$sql = "SELECT id, ein, firstname, lastname, usergroupID
	        FROM users WHERE id = $userID LIMIT 1";
	$res = mysql_query($sql);

	if ($res === false || mysql_num_rows($res) == 0)
	{
		http_response_code(500);
		die('{"error":"identity lookup failed"}');
	}

	$row         = mysql_fetch_object($res);
	$usergroupID = (int) $row->usergroupID;

	/*
	/* Resolved cohort — 'admin' | 'leadership' | 'operations' | 'crew'.
	/* Resolution rule and allow-list are defined once in
	/* cohort.php::goat_user_cohort(). Returns the normalised (lowercase) value
	/* regardless of how the column is cased, so the wire value is canonical.
	*/

	$cohort = goat_user_cohort();

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