<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');
	include_once('supervision-graph.php');
	include_once('link-button.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* MARK / UNMARK "NO CREW BOSS" (BRIEF-default-link-self-submit-off,
	/* Release 2, B4; DESIGN v0.6 §4.3, J7).
	/*
	/* A call that overlaps a Crew Boss call but really has no Crew Boss. The
	/* marker silences the amber "Not linked" warning (and probe P3) for it and
	/* the Link button stops offering it. It does NOT change the call's tier and
	/* does NOT switch self-submit on (J1). Linking the call clears it.
	/*
	/*   POST {action: "set" | "clear", call_ids: [int]}
	/*     200 {ok, action, results: [{call_id, action, result: done|refused,
	/*          reason}], counts}
	/*
	/*   set    per call, refused when the call has a call_supervision link
	/*          ("Unlink it first.") or is not one the warning applies to —
	/*          cancelled, a Crew Boss call itself, or overlapping no live Crew
	/*          Boss call on its booking (goat_no_crew_boss_refusals()).
	/*          Otherwise insert or replace, with set_by / set_at.
	/*   clear  per call, delete. A call that was not marked is done, not an
	/*          error: the caller asked for it to be unmarked and it is.
	/*
	/*   403 not an admin session (J18)
	/*   422 bad body
	/*   500 the checks or the database failed
	/*
	/* Callers: THE GOAT's amber "Not linked" banner (bulk Mark) and the Undo
	/* on the marker chip. auto-link-supervision.php's `mark` list runs the
	/* same checks and the same write (link-button.php).
	/*
	/* Per call, and a partial failure never hides the successes.
	/*
	/* DATABASE: checks through supervision-graph.php (mysql_*), writes through
	/* goat_pdo() in link-button.php — set-team-lead.php's split.
	/*
	/* PHP 5.x — array(), no ??, no short array syntax.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		goat_json_error(403, 'Admin only');
	}

	$actor = isset($_SESSION[SITE_KEY]['userID']) ? (int) $_SESSION[SITE_KEY]['userID'] : 0;

	$payload = json_decode(file_get_contents('php://input'));

	if (!$payload || !isset($payload->action) || !isset($payload->call_ids))
	{
		goat_json_error(422, 'action and call_ids are required');
	}

	$action = (string) $payload->action;
	$ids    = goat_lb_ids($payload->call_ids);

	if ($action !== 'set' && $action !== 'clear')
	{
		goat_json_error(422, 'action must be set or clear');
	}

	if (count($ids) === 0)
	{
		goat_json_error(422, 'call_ids must contain at least one call id');
	}

	if (count($ids) > 500)
	{
		goat_json_error(422, 'At most 500 calls');
	}

	if (!goat_sg_have_no_crew_boss())
	{
		goat_json_error(500, 'The No Crew Boss table isn\'t on this server yet (MIGRATION-link-button.sql).');
	}

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		goat_json_error(500, 'database unavailable');
	}

	$now     = time();
	$results = array();
	$counts  = array('done' => 0, 'refused' => 0);

	$ref = array('ok' => true, 'error' => '', 'by_call' => array());

	if ($action === 'set')
	{
		$ref = goat_no_crew_boss_refusals($ids, $now);

		if (!$ref['ok'])
		{
			error_log('no-crew-boss: check: ' . $ref['error']);
			goat_json_error(500, 'Couldn\'t check the calls: ' . $ref['error']);
		}
	}

	foreach ($ids as $cid)
	{
		if ($action === 'set')
		{
			$why = isset($ref['by_call'][$cid]) ? $ref['by_call'][$cid] : 'Call not found.';

			if ($why === '')
			{
				$why = goat_lb_mark($pdo, $cid, $actor, $now);
			}
		}
		else
		{
			$why = goat_lb_unmark($pdo, $cid);
		}

		$results[] = array('call_id' => $cid, 'action' => $action,
		                   'result' => ($why === '') ? 'done' : 'refused', 'reason' => $why);
		$counts[($why === '') ? 'done' : 'refused']++;
	}

	$json = json_encode(array('ok' => true, 'action' => $action, 'results' => $results, 'counts' => $counts));

	if ($json === false)
	{
		goat_json_error(500, 'could not encode the reply');
	}

	echo $json;

?>
