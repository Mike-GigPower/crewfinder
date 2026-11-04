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
	/* FIXTURE — TEST BOX ONLY, REMOVE BEFORE PROD (BRIEF-crew-self-times §3.8).
	/*
	/* Prints goat_call_tiers() and the contact ladder for the calls given, for
	/* smoke test rows 1-7d and 14-14b. Read-only: nothing here writes.
	/*
	/*   GET ?callIDs=38952,39205[&viewer=<userID>][&scopeUser=<userID>]
	/*
	/*   viewer     resolve each call's contact AS this userID (the ladder skips
	/*              the viewer). Omit for the view of nobody in particular.
	/*   scopeUser  also print that user's goat_boss_scope_detail() and
	/*              goat_time_entry_scope(), intersected with the calls given
	/*
	/* TWO LOCKS: an admin session, and the connected database must be
	/* smartst_test. The second is the one that matters — this prints names and
	/* mobiles of contacts, and the brief rules it off prod entirely.
	/*
	/* PHP 5.x — mysql_*, no ??, no short array syntax.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		goat_json_error(403, 'Admin only');
	}

	$dbres = mysql_query("SELECT DATABASE() AS d");
	$dbrow = ($dbres === false) ? false : mysql_fetch_object($dbres);

	if (!$dbrow || (string) $dbrow->d !== 'smartst_test')
	{
		goat_json_error(403, 'test-call-tiers.php runs on smartst_test only');
	}

	$raw = isset($_GET['callIDs']) ? (string) $_GET['callIDs'] : '';
	$ids = array();

	foreach (explode(',', $raw) as $p)
	{
		$n = (int) trim($p);

		if ($n > 0)
		{
			$ids[$n] = true;
		}
	}

	if (count($ids) === 0 || count($ids) > 50)
	{
		goat_json_error(422, 'callIDs: 1 to 50 comma-separated call ids');
	}

	$ids       = array_keys($ids);
	$viewer    = isset($_GET['viewer'])    ? (int) $_GET['viewer']    : 0;
	$scopeUser = isset($_GET['scopeUser']) ? (int) $_GET['scopeUser'] : 0;

	$tiers = goat_call_tiers($ids);

	if (!$tiers['ok'])
	{
		goat_json_error(500, $tiers['error']);
	}

	$calls = array();

	foreach ($ids as $cid)
	{
		$calls[] = array(
			'call_id'  => $cid,
			'tier'     => isset($tiers['by_call'][$cid]) ? $tiers['by_call'][$cid] : null,
			'contacts' => goat_resolve_call_contact($cid, $viewer)
		);
	}

	$out = array(
		'ok'                => true,
		'database'          => 'smartst_test',
		'have_is_crew_boss' => goat_ccm_have_crew_boss() ? 1 : 0,
		'viewer'            => $viewer,
		'calls'             => $calls
	);

	if ($scopeUser > 0)
	{
		$detail = goat_boss_scope_detail($scopeUser);
		$entry  = goat_time_entry_scope($scopeUser);
		$want   = array_flip($ids);

		$d = array();
		$e = array();

		foreach ($detail as $cid => $why)
		{
			if (isset($want[$cid])) $d[$cid] = $why;
		}

		foreach ($entry as $cid)
		{
			if (isset($want[$cid])) $e[] = (int) $cid;
		}

		$out['scope_user']       = $scopeUser;
		$out['boss_scope']       = count($d) ? $d : new stdClass();
		$out['time_entry_scope'] = $e;
	}

	echo json_encode($out);

?>
