<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SERVICE endpoint — find a person by username, email, or mobile so Crew Hub
	/* can start a "forgot username / forgot password" flow. There is no acting
	/* user yet (we are searching FOR one), so this guards on the raw service key
	/* directly — the same check goat_acting_user_id() uses on its service path.
	/* Crew Hub calls this server-to-server and NEVER forwards the returned
	/* contact details to the browser.
	/*
	/* Scope: active users only, and never a Super Admin (usergroupID 1) — those
	/* accounts are not reset through the public portal.
	*/

	$key = isset($_SERVER['HTTP_X_GOAT_SERVICE_KEY'])
	     ? $_SERVER['HTTP_X_GOAT_SERVICE_KEY'] : '';

	if (!goat_service_key_ok($key))
	{
		http_response_code(401);
		die('{"error":"Not authorised"}');
	}

	/*
	/* One identifier, matched against username OR email OR mobile. Mobile is
	/* compared with spaces stripped on both sides, since numbers are stored
	/* inconsistently with and without spaces.
	*/
	$q = isset($_GET['q']) ? trim($_GET['q']) : '';
	if ($q === '' && isset($_POST['q']))
		$q = trim($_POST['q']);

	if ($q === '')
	{
		http_response_code(400);
		die('{"error":"q required"}');
	}

	$qEsc    = mysql_real_escape_string($q);
	$qMobile = mysql_real_escape_string(str_replace(' ', '', $q));

	$sql = "SELECT id, ein, firstname, lastname, email, mobile "
	     . "FROM users "
	     . "WHERE active = '1' AND usergroupID <> 1 AND ("
	     .     "username = '" . $qEsc . "' "
	     .     "OR email = '" . $qEsc . "' "
	     .     "OR REPLACE(mobile, ' ', '') = '" . $qMobile . "'"
	     . ") LIMIT 1";

	$res = mysql_query($sql);

	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"lookup failed: ' . addslashes(mysql_error()) . '"}');
	}

	/* Not found is a normal, successful answer — Crew Hub decides what to show
	/* (and shows the same "if it matches, we've sent a link" either way). */
	if (mysql_num_rows($res) == 0)
	{
		echo json_encode(array('found' => false));
		exit;
	}

	$row = mysql_fetch_object($res);

	echo json_encode(array(
		'found'     => true,
		'user_id'   => (int) $row->id,
		'ein'       => (int) $row->ein,
		'firstname' => $row->firstname,
		'lastname'  => $row->lastname,
		'email'     => $row->email,
		'mobile'    => $row->mobile
	));

?>
