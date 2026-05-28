<?php

	/*
	/* global file */

	include('../../global.php');

	/*
	/* use plain text for AJAX */

	header('Content-Type: text/plain');

	/*
	/* check user is logged in AND is an admin.
	/* Relocate is an admin-only operation (admins manage all crew calendars),
	/* so we require usergroupID == 1 here. Previously this only checked for a
	/* valid session, which let any logged-in crew member move any event by
	/* guessing its id. This guard matches sms-call.php.
	*/

	if (!$user->checkSession() || $user->info->usergroupID != 1)
		die('ERROR: Must be logged in as an admin');

	$userID = (int) $_SESSION[SITE_KEY]['userID'];

	/*
	/* validate input */

	if (!count($_GET))
		die('ERROR: No arguments specified');

	if (!isset($_GET['id']))
		die('ERROR: No event id specified');

	if (!isset($_GET['dayDelta']))
		die('ERROR: No day delta specified');

	if (!isset($_GET['minuteDelta']))
		die('ERROR: No minute delta specified');

	/*
	/* process the request */

	$day_delta = intval($_GET['dayDelta']);
	$min_delta = intval($_GET['minuteDelta']);

	$eventData = array(
		'start' => "DATE_ADD(DATE_ADD(`start`, INTERVAL $day_delta DAY), INTERVAL $min_delta MINUTE)",
		'end'   => "DATE_ADD(DATE_ADD(`end`, INTERVAL $day_delta DAY), INTERVAL $min_delta MINUTE)"
	);

	$db->update('calendars', $eventData, 'id = ' . intval($_GET['id']));

?>
