<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — SmartStaff's own floor, for THE GOAT's System tab
	/* (DESIGN-system-health-v0_3.md §5). The cheapest useful request: it takes
	/* the session lock, runs SELECT 1, and reports two numbers.
	/*
	/* Pressing "Test now" in Today › System calls this three times, ONE AT A
	/* TIME. Comparing the three shows whether calls are queueing behind each
	/* other rather than being slow in themselves.
	/*
	/* timing_ms IS MEASURED FROM REQUEST_TIME_FLOAT, NOT FROM THE TOP OF THIS
	/* FILE. That is deliberate and is the whole point of the endpoint: the span
	/* from the request arriving to this line INCLUDES the wait for PHP's
	/* file-based session lock taken inside global.php above. THE GOAT cannot
	/* see that wait from the Python side — it is inside the response time, not
	/* before the send — so measuring from the top of this file would report
	/* near-zero for a request that in fact sat in the queue for seconds and
	/* would hide exactly what we are looking for.
	/*
	/* db_ms is the SELECT 1 alone, so a slow database can be told apart from a
	/* busy session lock: high timing_ms with low db_ms is contention; both high
	/* is the database.
	/*
	/* ADMIN ONLY, matching the route that calls it. Read-only: it writes
	/* nothing and reads no table.
	/*
	/* Response: {"ok":true,"timing_ms":N,"db_ms":N} — both numbers, never
	/* strings, so the GOAT's server_ms parser takes them without coercion.
	/*
	/* Read-only. PHP 5.x — array(), no ??, no short arrays, tabs. PDO via
	/* goat_pdo().
	*/

	if (goat_user_cohort() !== 'admin')
	{
		goat_json_error(403, 'Admin only');
		exit;
	}

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		goat_json_error(503, 'Database handle unavailable');
		exit;
	}

	$t_db = microtime(true);

	try
	{
		$stmt = $pdo->prepare("SELECT 1");
		$stmt->execute();
		$stmt->fetch(PDO::FETCH_NUM);
		$stmt->closeCursor();
	}
	catch (PDOException $e)
	{
		error_log('health-ping: ' . $e->getMessage());
		goat_json_error(500, 'health ping query failed');
		exit;
	}

	$db_ms = (microtime(true) - $t_db) * 1000;

	/*
	/* From the request's arrival, so the session-lock wait inside global.php is
	/* included. See the note at the top of this file.
	*/
	$timing_ms = (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000;

	echo json_encode(array(
		'ok'        => true,
		'timing_ms' => round($timing_ms, 1),
		'db_ms'     => round($db_ms, 1)
	));

?>
