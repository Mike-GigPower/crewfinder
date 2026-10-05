<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include('call-graph.php');
	include_once('callout.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* OPEN A BACKUP CALL-OUT — DESIGN-backup-callout-v0_2 §4.3–§4.4, brief §D2.
	/*
	/* ADMIN ONLY — the same gate as Promote (update-crew-status.php), D9.
	/*
	/* Every eligible Backup (7) on the call is turned back into an open offer
	/* (7 -> 1) on the call and on each downstream call where they are Backup,
	/* with a call_callout_member row for every row reset. They then answer in
	/* Crew Hub like any offer; respond-to-call.php's capacity check decides
	/* each accept, and closes the call-out when the call fills.
	/*
	/* Status 1, not 0: a status-1 row lands in ops' "awaiting" bucket in
	/* get-open-offers-bulk.php, which is true — they have been messaged.
	/*
	/* Because goat_user_package walks through status-1 rows, a member's
	/* package on accept is exactly this call plus the downstream rows reset
	/* with it. The peel does the rest: this call is the root and is
	/* capacity-checked; the downstream calls are non-roots and confirm with it.
	/*
	/* UPSTREAM ROWS ARE NEVER TOUCHED. DESIGN §6's check table: on every
	/* call-out path (accept with room, accept when full, decline, revert) the
	/* upstream rows are "untouched" or withdrawn by goat_decline_scope —
	/* never reset. Being Backup upstream and Confirmed downstream is legal;
	/* resetting an upstream row would offer them a call this one does not
	/* commit them to, and a revert could not tell it from a real offer.
	/*
	/* Contract: POST callID. THE GOAT sends the pushes (app.py), not this file.
	/*
	/* POST dry_run=1 runs every check and the eligibility pass, then returns
	/* the same members/skipped/places WITHOUT writing or locking — the
	/* confirmation dialog in THE GOAT shows exactly who this endpoint would
	/* call out, from the same code, so the preview and the action cannot
	/* disagree. Refusals are the same 409s. Members' `calls` in a dry run are
	/* the rows that WOULD be reset; call_names names every call in them.
	/*
	/* PHP 5.x — mysql_* / $db (brief §0.1), no ??, no short array syntax.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		goat_json_error(403, 'Admin only');
	}

	$openedBy = (int) $_SESSION[SITE_KEY]['userID'];
	$callID   = isset($_POST['callID']) ? (int) $_POST['callID'] : 0;
	$dryRun   = !empty($_POST['dry_run']);

	if ($callID <= 0)
	{
		goat_json_error(400, 'callID required');
	}

	$call = $db->selectFirst('id, call_name, required', 'calls', 'id=' . $db->sc($callID));

	if (!$call)
	{
		goat_json_error(404, 'Call not found');
	}

	function callout_refuse($error, $extra)
	{
		http_response_code(409);
		echo json_encode(array_merge(array('ok' => false, 'error' => $error), $extra));
		die();
	}

	/* ---- refusals (§4.3) ---- */

	$startTs = goat_call_start_ts($callID);

	if ($startTs !== false && $startTs <= time())
	{
		callout_refuse('started', array());
	}

	if (goat_callout_open_for_call($callID))
	{
		callout_refuse('callout_open', array());
	}

	/* Places — confirmed counts every status-5 row, including crew whose
	/* promotion is still unanswered: a pending promotion holds its place (D1). */

	function callout_places($callID, $required)
	{
		$res = mysql_query('SELECT COUNT(*) FROM call_crew_map WHERE status = 5 AND callID = ' . (int) $callID);

		return (int) $required - ($res !== false ? (int) mysql_result($res, 0) : 0);
	}

	$places = callout_places($callID, $call->required);

	if ($places <= 0)
	{
		callout_refuse('full', array('places' => $places));
	}

	/* ---- candidates: every Backup on the call ---- */

	$cands = array();   /* userID => name */

	$cres = mysql_query("SELECT m.userID, u.firstname, u.lastname
	                     FROM call_crew_map m
	                     LEFT JOIN users u ON u.id = m.userID
	                     WHERE m.callID = " . $callID . " AND m.status = 7
	                     ORDER BY u.lastname ASC, u.firstname ASC");

	if ($cres !== false)
	{
		while ($crow = mysql_fetch_object($cres))
		{
			$cands[(int) $crow->userID] = trim(html_entity_decode($crow->firstname . ' ' . $crow->lastname, ENT_QUOTES));
		}
	}

	/* Their rows on every locked downstream call. One query for everybody. */

	$down      = goat_calls_downstream($callID);   /* locked edges only */
	$downName  = array();
	$downOf    = array();   /* userID => array(callID => status) */

	if (count($down) && count($cands))
	{
		$down = array_map('intval', $down);

		$nres = mysql_query('SELECT id, call_name FROM calls WHERE id IN (' . implode(',', $down) . ')');

		if ($nres !== false)
		{
			while ($nrow = mysql_fetch_object($nres))
			{
				$downName[(int) $nrow->id] = $nrow->call_name;
			}
		}

		$dres = mysql_query('SELECT userID, callID, status FROM call_crew_map
		                     WHERE callID IN (' . implode(',', $down) . ')
		                       AND userID IN (' . implode(',', array_keys($cands)) . ')');

		if ($dres !== false)
		{
			while ($drow = mysql_fetch_object($dres))
			{
				$downOf[(int) $drow->userID][(int) $drow->callID] = (int) $drow->status;
			}
		}
	}

	/* Eligible only if they HOLD every downstream call (5 or 7): an accept on
	/* this call must never confirm them here without the calls it feeds (§3.1). */

	$members = array();   /* userID => array of call ids to reset, this call LAST */
	$skipped = array();

	foreach ($cands as $uid => $name)
	{
		$held    = isset($downOf[$uid]) ? $downOf[$uid] : array();
		$missing = array();
		$reset   = array();

		foreach ($down as $d)
		{
			if (!isset($held[$d]))
			{
				$missing[] = 'not on ' . (isset($downName[$d]) ? $downName[$d] : 'call ' . $d);
			}
			else if ($held[$d] === 7)
			{
				$reset[] = $d;
			}
			else if ($held[$d] !== 5)
			{
				$missing[] = 'not holding ' . (isset($downName[$d]) ? $downName[$d] : 'call ' . $d) . ' (status ' . $held[$d] . ')';
			}
		}

		if (count($missing))
		{
			$skipped[] = array('user_id' => $uid, 'name' => $name, 'reason' => implode('; ', $missing));
		}
		else
		{
			$reset[]       = $callID;
			$members[$uid] = $reset;
		}
	}

	if (!count($members))
	{
		callout_refuse('no_eligible_backups', array('places' => $places, 'skipped' => $skipped));
	}

	/* ---- dry run: the confirmation dialog's preview — stop before writing ---- */

	if ($dryRun)
	{
		$preview   = array();
		$callNames = array((string) $callID => $call->call_name);

		foreach ($downName as $dk => $dn)
		{
			$callNames[(string) $dk] = $dn;
		}

		foreach ($members as $uid => $reset)
		{
			$preview[] = array('user_id' => $uid, 'name' => $cands[$uid], 'calls' => $reset);
		}

		echo json_encode(array(
			'ok'         => true,
			'dry_run'    => true,
			'places'     => $places,
			'members'    => $preview,
			'skipped'    => $skipped,
			'call_names' => $callNames
		));
		exit;
	}

	/* ---- writes, under the capacity lock (brief Part B, §D2) ----
	/*
	/* The lock stops an accept landing between the places count and the
	/* resets. Re-check the open call-out and the places inside it: either can
	/* have changed while the candidates were being read. */

	if (!goat_capacity_lock(3))
	{
		http_response_code(503);
		die('{"ok":false,"error":"busy","retry":true}');
	}

	if (goat_callout_open_for_call($callID))
	{
		callout_refuse('callout_open', array());
	}

	$places = callout_places($callID, $call->required);

	if ($places <= 0)
	{
		callout_refuse('full', array('places' => $places));
	}

	$db->insert('call_callout', array(
		'callID'    => $db->sc($callID),
		'opened_by' => $db->sc($openedBy),
		'opened_at' => $db->sc(time()),
		'places'    => $db->sc($places)
	));

	if (mysql_error())
	{
		goat_json_error(500, 'call-out insert failed: ' . mysql_error());
	}

	$calloutID = (int) mysql_insert_id();
	$out       = array();

	/* Downstream rows first, this call LAST. If a request dies part-way, the
	/* worst left behind is downstream rows at 1 with member rows, which any
	/* close reverts. The reverse order could leave this call open while the
	/* calls it feeds are still 7, and an accept would then confirm them here
	/* without them — the state the feed rules forbid. */

	foreach ($members as $uid => $reset)
	{
		$done = array();

		foreach ($reset as $rc)
		{
			/* WHERE status = 7: a row that moved since it was read is left
			/* alone, and gets no member row — so a close can never revert it. */

			mysql_query('UPDATE call_crew_map SET status = 1
			             WHERE status = 7 AND callID = ' . (int) $rc . ' AND userID = ' . (int) $uid);

			if (mysql_error())
			{
				goat_json_error(500, 'call-out reset failed: ' . mysql_error());
			}

			if (mysql_affected_rows() < 1)
			{
				continue;
			}

			$db->insert('call_callout_member', array(
				'callout_id' => $db->sc($calloutID),
				'callID'     => $db->sc($rc),
				'userID'     => $db->sc($uid)
			));

			$done[] = (int) $rc;
		}

		/* Only a member if THIS call was reset. If it moved under us (ops
		/* changed it by hand), put any downstream rows reset above straight
		/* back on 7 and drop their member rows — otherwise they would sit as
		/* a lone offer on the downstream call that nobody was told about. */

		if (in_array($callID, $done))
		{
			$out[] = array('user_id' => $uid, 'name' => $cands[$uid], 'calls' => $done);
		}
		else if (count($done))
		{
			mysql_query('UPDATE call_crew_map SET status = 7
			             WHERE status = 1 AND userID = ' . (int) $uid . ' AND callID IN (' . implode(',', $done) . ')');

			mysql_query('DELETE FROM call_callout_member
			             WHERE callout_id = ' . $calloutID . ' AND userID = ' . (int) $uid);
		}
	}

	/* Every reset raced: close at once as 'empty' (reverting any stray
	/* downstream rows) rather than leave an open call-out with nobody in it. */

	if (!count($out))
	{
		goat_callout_close($calloutID, 'empty');
		callout_refuse('no_eligible_backups', array('places' => $places, 'skipped' => $skipped));
	}

	goat_capacity_unlock();

	echo json_encode(array(
		'ok'         => true,
		'callout_id' => $calloutID,
		'places'     => $places,
		'members'    => $out,
		'skipped'    => $skipped
	));

?>
