<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include_once('callout.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* CLOSE A BACKUP CALL-OUT — Close call-out in THE GOAT's call dialog.
	/* DESIGN-backup-callout-v0_2 §4.6, brief §D3.
	/*
	/* ADMIN ONLY — the same gate as Promote and open-callout.php (D9).
	/*
	/* Runs the shared goat_callout_close (callout.php) with reason 'ops':
	/* every member still at 1 goes back to Backup (7), outcome 'reverted'.
	/* Members who already answered are left as they are.
	/*
	/* Under the capacity lock, so the close cannot interleave with an accept
	/* on the same rows: otherwise a crew member could be confirmed in the gap
	/* between the revert and closed_at, and their accept would find no open
	/* call-out to record its outcome against.
	/*
	/* No open call-out is not an error: ok:true, changed:false. Closing twice
	/* is therefore harmless.
	/*
	/* Contract: POST callID.
	/*
	/* PHP 5.x — mysql_* / $db (brief §0.1), no ??, no short array syntax.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		goat_json_error(403, 'Admin only');
	}

	$callID = isset($_POST['callID']) ? (int) $_POST['callID'] : 0;

	if ($callID <= 0)
	{
		goat_json_error(400, 'callID required');
	}

	if (!goat_capacity_lock(3))
	{
		http_response_code(503);
		die('{"ok":false,"error":"busy","retry":true}');
	}

	$open = goat_callout_open_for_call($callID);

	if (!$open)
	{
		goat_capacity_unlock();
		echo json_encode(array('ok' => true, 'changed' => false));
		exit;
	}

	$res = goat_callout_close((int) $open->id, 'ops');
	$err = mysql_error();   /* before the unlock query clears it */

	goat_capacity_unlock();

	if ($res === false)
	{
		goat_json_error(500, 'call-out close failed: ' . $err);
	}

	echo json_encode(array(
		'ok'         => true,
		'changed'    => $res['changed'],
		'callout_id' => (int) $open->id,
		'reverted'   => $res['reverted']
	));

?>
