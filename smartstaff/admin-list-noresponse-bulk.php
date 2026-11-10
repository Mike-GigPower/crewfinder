<?php

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');

	header('Content-Type: application/json');

	/*
	/* RECORDS — No Response counts per crew member, from offer_history
	/* (DESIGN-offer-reminders v0.3 §5, §7; BRIEF slice 2 §3.4).
	/*
	/* Only outcome = 'no_response' counts against a person. not_delivered and
	/* withdrawn_call_started are never shown on Reliability (D2, D3).
	/*
	/* 12-month window on outcome_at, matching the Late / No-show 12 MO
	/* convention in list-crew-bulk.php. Shape mirrors admin-list-last-shift-
	/* bulk.php: {ok, no_response: {userID: {...}}, timing_ms}.
	*/

	$t0 = microtime(true);

	if (goat_user_cohort() !== 'admin')
	{
		goat_json_error(403, 'Admin only');
	}

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		goat_json_error(503, 'Database handle unavailable');
	}

	$sql = "
		SELECT userID                                                   AS user_id,
		       SUM(outcome_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH))     AS n_12mo,
		       COUNT(*)                                                 AS n_all,
		       DATE_FORMAT(MAX(outcome_at), '%Y-%m-%d')                 AS last_iso
		  FROM offer_history
		 WHERE outcome = 'no_response'
		 GROUP BY userID
	";

	$out = array();

	try
	{
		$stmt = $pdo->prepare($sql);
		$stmt->execute();

		while ($r = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$out[(int) $r['user_id']] = array(
				'n_12mo'   => (int) $r['n_12mo'],
				'n_all'    => (int) $r['n_all'],
				'last_iso' => $r['last_iso']
			);
		}

		$stmt->closeCursor();
	}
	catch (PDOException $e)
	{
		error_log('admin-list-noresponse-bulk: ' . $e->getMessage());
		goat_json_error(500, 'no response query failed');
	}

	echo json_encode(array(
		'ok'          => true,
		'no_response' => (object) $out,
		'timing_ms'   => (int) round((microtime(true) - $t0) * 1000)
	));

?>
