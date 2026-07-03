<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');
	include('../../lib/messagemedia/SmsInterface.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SERVICE endpoint — send ONE SMS to a single user's on-file mobile through
	/* SmartStaff's existing MessageMedia gateway (the same one shift offers use).
	/* Self-scoped via goat_acting_user_id(): Crew Hub asserts the userID behind
	/* the service key. The message text is composed by Crew Hub (e.g. a password
	/* reset link) and passed in; this endpoint only looks up the number and
	/* sends. The destination number is ALWAYS the user's stored mobile, never
	/* taken from the request — so this can only ever text a real user.
	*/

	$userID = goat_acting_user_id();

	if ($_SERVER['REQUEST_METHOD'] !== 'POST')
	{
		http_response_code(405);
		die('{"error":"POST required"}');
	}

	$message = isset($_POST['message']) ? trim($_POST['message']) : '';
	if ($message === '')
	{
		http_response_code(400);
		die('{"error":"message required"}');
	}

	/*
	/* The user's own mobile (active users only).
	*/
	$u = $db->selectFirst('id, mobile', 'users', 'id=' . (int) $userID . " AND active='1'");
	if (!$u || trim($u->mobile) === '')
	{
		http_response_code(404);
		die('{"error":"user not found, inactive, or no mobile on file"}');
	}

	/*
	/* Connect + send (same pattern as custom-sms.php / sms-call.php).
	*/
	$si = new SmsInterface(false, false);

	if (!$si->connect($settings->smsuser, $settings->smspass, true, false))
	{
		http_response_code(502);
		die('{"error":"SMS service unavailable"}');
	}

	$si->addMessage($u->mobile, $message);

	if (!$si->sendMessages())
	{
		$reason = $si->getResponseMessage();
		http_response_code(502);
		die('{"error":"send failed' . ($reason !== NULL ? ': ' . addslashes($reason) : '') . '"}');
	}

	/*
	/* Log the send, matching custom-sms.php's sms_sent shape (userID raw int;
	/* the rest quoted via $db->sc()).
	*/
	$db->insert('sms_sent', array(
		'userID'    => (int) $u->id,
		'phone'     => $db->sc(str_replace(' ', '', $u->mobile)),
		'message'   => $db->sc($message),
		'date_sent' => $db->sc(strtotime('now'))
	));

	echo json_encode(array('ok' => true, 'user_id' => (int) $u->id));

?>
