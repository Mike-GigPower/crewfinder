<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include_once('supervision-graph.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* EACH CALL'S TIER, FOR THE GOAT — slice G of BRIEF-crew-self-times (§14.1).
	/*
	/*   POST {callIDs: [int, ...]}     at most 200
	/*
	/*   200 {ok: true,
	/*        by_call: {callID: {tier, entry_user_ids, crew_boss_user_ids,
	/*                           team_lead_user_ids, multi_team_lead,
	/*                           is_boss_call, supervised_by, flags}},
	/*        names:   {userID: "First Last"}}
	/*
	/* by_call is goat_call_tiers() exactly (supervision-graph.php), minus its
	/* internal entry_how map. names covers every userID by_call mentions, so the
	/* call dialog can say "Crew Boss: Anthony Skinner" for a Crew Boss who is on
	/* a supervising call and not on this call's roster.
	/*
	/* SESSION ONLY: a logged-in SmartStaff session in the admin, leadership or
	/* operations cohort, read through goat_user_cohort(). NOT goat_can_read_all(),
	/* which also admits the service key on its own — the hole my-call-times.php
	/* documents. THE GOAT calls this on the operator's own session and holds no
	/* service key.
	/*
	/* A failed lookup is a 500, never an empty by_call: THE GOAT draws the tier
	/* line and gates the Team Lead button from this, and "no tier" must not be
	/* readable as "self".
	/*
	/* PHP 5.x — mysql_*, no ??, no short array syntax.
	*/

	$cohort = goat_user_cohort();

	if ($cohort !== 'admin' && $cohort !== 'leadership' && $cohort !== 'operations')
	{
		goat_json_error(403, 'Admin, Leadership or Operations session required');
	}

	$payload = json_decode(file_get_contents('php://input'));

	if (!$payload || !isset($payload->callIDs) || !is_array($payload->callIDs))
	{
		goat_json_error(422, 'callIDs must be an array of call ids');
	}

	if (count($payload->callIDs) > 200)
	{
		goat_json_error(422, 'at most 200 callIDs per request');
	}

	$t = goat_call_tiers($payload->callIDs);

	if (!$t['ok'])
	{
		goat_json_error(500, 'tier lookup failed: ' . $t['error']);
	}

	$byCall = array();
	$people = array();

	foreach ($t['by_call'] as $cid => $info)
	{
		unset($info['entry_how']);

		$byCall[$cid] = $info;

		foreach (array('entry_user_ids', 'crew_boss_user_ids', 'team_lead_user_ids') as $k)
		{
			foreach ($info[$k] as $uid)
			{
				$people[(int) $uid] = true;
			}
		}
	}

	$names = goat_sg_user_names(array_keys($people));

	/* forced to {} when empty, so neither field ever flips to [] */

	echo json_encode(array(
		'ok'      => true,
		'by_call' => count($byCall) ? $byCall : new stdClass(),
		'names'   => count($names)  ? $names  : new stdClass()
	));

?>
