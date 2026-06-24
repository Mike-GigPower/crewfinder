<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SELF endpoint — the acting crew member confirms or declines an OFFERED
	/* call. Mirrors the crew dashboard handler (dash.php, action=callstatus):
	/*
	/*   - only rows currently status <= 1 (offered: Unconfirmed / SMS Sent)
	/*     can change, so an already-confirmed/declined call is left untouched
	/*   - status 5 = Confirmed; also added to the calendar via addToCalendar,
	/*     which is what then surfaces it in my-shifts.php
	/*   - status 6 = Declined ("Can't attend") — drops out of the offers list
	/*
	/* Reuses $db and $sss from global.php so the confirm side-effect is byte
	/* identical to SmartStaff's own dashboard.
	*/

	$userID = (int) goat_acting_user_id();

	if ($userID <= 0)
	{
		http_response_code(403);
		die('{"error":"not authorised"}');
	}

	$callID     = isset($_POST['callID']) ? (int) $_POST['callID'] : 0;
	$callStatus = isset($_POST['status']) ? (int) $_POST['status'] : 0;

	if ($callID <= 0)
	{
		http_response_code(400);
		die('{"error":"callID required"}');
	}

	/* only confirm (5) or decline (6) are valid targets */

	if ($callStatus != 5 && $callStatus != 6)
	{
		http_response_code(400);
		die('{"error":"status must be 5 (confirm) or 6 (decline)"}');
	}

	/*
	/* change only if currently offered (status <= 1), self-scoped to this crew
	/* member + this call — same guard as dash.php, so a call that has since
	/* been filled or already answered is a no-op.
	*/

	$db->update(
		'call_crew_map',
		array('status' => $db->sc($callStatus)),
		'status <= 1 AND userID=' . $db->sc($userID) . ' AND callID=' . $db->sc($callID)
	);

	$changed = mysql_affected_rows();

	if (mysql_error())
	{
		http_response_code(500);
		die('{"error":"call status update failed: ' . addslashes(mysql_error()) . '"}');
	}

	/*
	/* on a confirm that actually took effect, materialise the calendar row
	/* exactly as SmartStaff does (skip if the offer was already gone, so we
	/* never add a calendar entry the crew member isn't really confirmed on).
	*/

	if ($callStatus == 5 && $changed > 0)
	{
		$sss->addToCalendar($callID, $userID);
	}

	echo json_encode(array(
		'ok'      => true,
		'callID'  => $callID,
		'status'  => $callStatus,
		'changed' => $changed > 0 ? true : false
	));

?>
