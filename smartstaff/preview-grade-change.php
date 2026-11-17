<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');
	include(dirname(__FILE__) . '/grade-ladder-lib.php');

	header('Content-Type: application/json');

	/*
	/* CHANGE PAY GRADE — PREVIEW (read only). BRIEF-promotion-paygrade-slice1
	/* §2.2; DESIGN-promotion-paygrade-cascade v0.2 D1-D15.
	/*
	/* GET ?user_id=&new_grade=&wef=
	/*   new_grade  10, 26 or 25 (a ladder base grade)
	/*   wef        Y-m-d, pay-period start <= wef <= today (Melbourne). Optional:
	/*              defaults to today.
	/*
	/* Returns the person, the rows a change would update (each with
	/* default_ticked), the rows staying before WEF, and the locked rows that
	/* would need a manual fix. Writes nothing.
	/*
	/* A row on a HIGHER tier than the new grade while the person moves UP is
	/* listed unticked (D12): someone deliberately booked above their grade.
	/*
	/* PHP 5.6.
	*/

	if (goat_user_cohort() !== 'admin')
		goat_json_error(403, 'Admin only');

	$userId   = isset($_GET['user_id'])   ? (int) $_GET['user_id']   : 0;
	$newGrade = isset($_GET['new_grade']) ? (int) $_GET['new_grade'] : 0;
	$today    = goat_today_iso();
	$ppStart  = goat_pay_period_start($today);
	$wef      = (isset($_GET['wef']) && $_GET['wef'] !== '') ? (string) $_GET['wef'] : $today;

	if ($userId <= 0)
		goat_json_error(400, 'user_id required');
	if (!in_array($newGrade, goat_grade_ladder(), true))
		goat_json_error(400, 'new_grade must be one of ' . implode(', ', goat_grade_ladder()));
	if (!goat_valid_iso_date($wef))
		goat_json_error(400, 'wef must be a date (YYYY-MM-DD)');

	if ($wef < $ppStart || $wef > $today)
	{
		http_response_code(400);
		die(json_encode(array(
			'error'            => 'WEF must be between ' . $ppStart . ' (start of the current pay period) and ' . $today,
			'code'             => 'wef_out_of_range',
			'pay_period_start' => $ppStart,
			'today'            => $today
		)));
	}

	try
	{
		$pdo = goat_pdo();

		$lm = goat_grade_ladder_map($pdo);
		if (!$lm['ok'])
			goat_json_error(500, $lm['error']);
		$map = $lm['map'];

		$st = $pdo->prepare('SELECT id, ein, firstname, lastname, paygradeID, active, usergroupID
		                     FROM users WHERE id = ? LIMIT 1');
		$st->execute(array($userId));
		$u = $st->fetch(PDO::FETCH_ASSOC);

		if (!$u || (int) $u['usergroupID'] !== 3)
			goat_json_error(404, 'crew member not found');

		$cur      = (int) $u['paygradeID'];
		$curOnLad = isset($map[$cur]) && $map[$cur]['kind'] === 'base';

		$split = goat_grade_change_rows($pdo, $userId, $newGrade, $wef, $map, null);
	}
	catch (Exception $e)
	{
		goat_json_error(500, 'preview failed: ' . $e->getMessage());
	}

	/* D12: a row booked ABOVE the new tier stays unticked unless the person
	/* themselves is moving DOWN (a demotion takes their rows down with them).
	/* Moving up, or staying on the same grade (the "Review…" path from the
	/* crew record's banner), leaves a higher booking alone by default. */
	$personDown = $curOnLad && $map[$newGrade]['tier'] < $map[$cur]['tier'];
	foreach ($split['rows'] as $i => $r)
		$split['rows'][$i]['default_ticked'] = ($r['direction'] === 'up') || $personDown;

	/* The usual next step: 10 -> 26 (probation done), 26 -> 25 (leadership). */
	$nextStep = false;
	if ($curOnLad)
		$nextStep = ($map[$newGrade]['tier'] === $map[$cur]['tier'] + 1);

	$ladder = array();
	foreach ($map as $id => $g)
		$ladder[] = array('id' => (int) $id, 'tier' => $g['tier'], 'kind' => $g['kind'],
		                  'name' => goat_grade_name($id, $map));

	echo json_encode(array(
		'ok'               => true,
		'person'           => array(
			'user_id'            => (int) $u['id'],
			'ein'                => (string) $u['ein'],
			'name'               => trim(html_entity_decode($u['lastname'], ENT_QUOTES) . ', '
			                          . html_entity_decode($u['firstname'], ENT_QUOTES), ', '),
			'active'             => (string) $u['active'] === '1',
			'current_grade'      => $cur,
			'current_grade_name' => $curOnLad ? goat_grade_name($cur, $map) : '',
			'current_on_ladder'  => $curOnLad
		),
		'new_grade'        => $newGrade,
		'new_grade_name'   => goat_grade_name($newGrade, $map),
		'next_step'        => $nextStep,
		'same_grade'       => ($newGrade === $cur),
		'wef'              => $wef,
		'today'            => $today,
		'pay_period_start' => $ppStart,
		'rows'             => $split['rows'],
		'before_wef'       => $split['before_wef'],
		'locked'           => $split['locked'],
		'ladder'           => $ladder
	));

?>
