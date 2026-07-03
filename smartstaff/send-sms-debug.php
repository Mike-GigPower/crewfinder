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
	/* DIAGNOSTIC ONLY — same send as send-sms.php, but it reports exactly what
	/* the MessageMedia gateway did so we can see why a message that returns "ok"
	/* might not arrive. DELETE this file once the SMS path is confirmed.
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

	$u = $db->selectFirst('id, mobile', 'users', 'id=' . (int) $userID . " AND active='1'");
	if (!$u || trim($u->mobile) === '')
	{
		http_response_code(404);
		die('{"error":"user not found, inactive, or no mobile on file"}');
	}

	$report = array();
	$report['raw_mobile'] = $u->mobile;

	/*
	/* 1) Credits check on its own connection (getCreditsRemaining closes it).
	/*    -1 = post-paid account (no credit concept), -2 = error.
	*/
	$siC = new SmsInterface(false, false);
	if ($siC->connect($settings->smsuser, $settings->smspass, true, false))
		$report['credits_remaining'] = $siC->getCreditsRemaining();
	else
		$report['credits_remaining'] = 'connect-failed';

	/*
	/* 2) The actual send on a fresh connection.
	*/
	$si = new SmsInterface(false, false);
	$report['stripped_phone'] = $si->stripInvalid($u->mobile);

	if (!$si->connect($settings->smsuser, $settings->smspass, true, false))
	{
		http_response_code(502);
		die(json_encode(array('error' => 'connect failed', 'report' => $report)));
	}

	$si->addMessage($u->mobile, $message);
	$report['queued'] = count($si->messageList);   /* 1 = message accepted into batch, 0 = dropped */

	$ok = $si->sendMessages();

	$report['send_ok']          = $ok ? true : false;
	$report['response_code']    = $si->getResponseCode();     /* 100-199 = success */
	$report['response_message'] = $si->getResponseMessage();

	echo json_encode(array(
		'ok'      => $ok ? true : false,
		'user_id' => (int) $u->id,
		'report'  => $report
	));

?>
