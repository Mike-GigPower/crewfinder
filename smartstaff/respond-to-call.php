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
	/* Resolve the linked set. If this call has a link_group, the response
	/* applies to EVERY call in that group — linked calls are answered as one
	/* unit (you can't confirm one and decline another). Otherwise it's just
	/* this call. Each row is still guarded by status <= 1 and self-scoped, so
	/* only THIS crew member's own still-offered rows change.
	*/

	$targetCall = $db->selectFirst('id, link_group', 'calls', 'id=' . $db->sc($callID));

	$callIDs = array();

	if ($targetCall && $targetCall->link_group !== null && (int) $targetCall->link_group > 0)
	{
		$group = (int) $targetCall->link_group;
		$grp   = $db->select('id', 'calls', 'link_group=' . $db->sc($group));

		if (is_array($grp))
		{
			foreach ($grp as $gc)
			{
				$callIDs[] = (int) $gc->id;
			}
		}
	}

	if (!count($callIDs))
	{
		$callIDs[] = $callID;   /* unlinked (or lookup failed) — just this call */
	}

	/*
	/* Apply the status to each call in the set. call_crew_map guard unchanged:
	/* only rows currently offered (status <= 1) for THIS user change, so an
	/* already-answered or filled row is a no-op. addToCalendar fires per call
	/* that actually flipped to Confirmed.
	*/

	$totalChanged = 0;
	$changedCalls = array();

	foreach ($callIDs as $cid)
	{
		$db->update(
			'call_crew_map',
			array('status' => $db->sc($callStatus)),
			'status <= 1 AND userID=' . $db->sc($userID) . ' AND callID=' . $db->sc($cid)
		);

		if (mysql_error())
		{
			http_response_code(500);
			die('{"error":"call status update failed: ' . addslashes(mysql_error()) . '"}');
		}

		$changed = mysql_affected_rows();

		if ($changed > 0)
		{
			$totalChanged += $changed;
			$changedCalls[] = $cid;

			if ($callStatus == 5)
			{
				$sss->addToCalendar($cid, $userID);
			}
		}
	}

	echo json_encode(array(
		'ok'            => true,
		'callID'        => $callID,
		'status'        => $callStatus,
		'changed'       => $totalChanged > 0 ? true : false,
		'changed_calls' => $changedCalls,
		'linked'        => count($callIDs) > 1 ? true : false
	));

?>
