<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — resolve a bare number to the call and/or booking it
	/* names, so THE GOAT can open either dialog from just the number
	/* (DESIGN-open-by-id-v0_1.md §3). Opening a call needs its booking id as
	/* well; this is the one place that supplies it, for calls of any age.
	/*
	/* GET ?id=<digits>
	/*
	/* Response:
	/*   {"id":38965,
	/*    "call":    {"call_id":38965,"booking_id":12117,"call_name":"...",
	/*                "booking_name":"...","date_iso":"2026-10-25",
	/*                "cancelled":0} | null,
	/*    "booking": {"booking_id":38965,"booking_name":"..."} | null}
	/*
	/* A call whose booking row is missing comes back as "call": null — it
	/* cannot be opened, and "not found" is the honest answer.
	/*
	/* A failed query is a 500, never a silent null: "not found" must only
	/* ever mean not found (goat-release: failed lookup feeding an assertion).
	/*
	/* date_iso uses the same date('Y-m-d', calls.start_date) form as
	/* get-calls-bulk.php, so it is a string, not a unix integer.
	/*
	/* Read-only. PHP 5.x — array(), no ??, no short arrays, tabs.
	*/

	/*
	/* 1. gate — admin only, GET only. */

	if (goat_user_cohort() !== 'admin')
	{
		goat_json_error(403, 'Admin only');
		exit;
	}

	if ($_SERVER['REQUEST_METHOD'] !== 'GET')
	{
		goat_json_error(405, 'GET required');
		exit;
	}

	/*
	/* 2. input */

	$raw = isset($_GET['id']) ? trim((string) $_GET['id']) : '';

	if (!preg_match('/^\d{1,9}$/', $raw))
	{
		goat_json_error(400, 'id must be a number');
		exit;
	}

	$id = (int) $raw;

	/*
	/* 3. handle */

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		goat_json_error(503, 'Database handle unavailable');
		exit;
	}

	/*
	/* 4. look up both — the same number can be a call and a booking. */

	$call    = null;
	$booking = null;

	try
	{
		$stmt = $pdo->prepare("
			SELECT
				c.id           AS call_id,
				c.bookingID    AS booking_id,
				c.call_name    AS call_name,
				c.start_date   AS start_date,
				c.cancelled_at AS cancelled_at,
				b.id           AS b_id,
				b.name         AS booking_name
			FROM calls c
			LEFT JOIN bookings b ON b.id = c.bookingID
			WHERE c.id = :id
			LIMIT 1
		");
		$stmt->execute(array(':id' => $id));
		$row = $stmt->fetch(PDO::FETCH_OBJ);

		if ($row && $row->b_id !== null)
		{
			$call = array(
				'call_id'      => (int) $row->call_id,
				'booking_id'   => (int) $row->booking_id,
				'call_name'    => $row->call_name,
				'booking_name' => $row->booking_name,
				'date_iso'     => ((int) $row->start_date > 0)
				                  ? date('Y-m-d', (int) $row->start_date) : '',
				'cancelled'    => ($row->cancelled_at !== null) ? 1 : 0,
			);
		}

		$stmt = $pdo->prepare("SELECT b.id AS booking_id, b.name AS booking_name
		                         FROM bookings b WHERE b.id = :id LIMIT 1");
		$stmt->execute(array(':id' => $id));
		$row = $stmt->fetch(PDO::FETCH_OBJ);

		if ($row)
		{
			$booking = array(
				'booking_id'   => (int) $row->booking_id,
				'booking_name' => $row->booking_name,
			);
		}
	}
	catch (PDOException $e)
	{
		error_log('lookup-id: ' . $e->getMessage());
		goat_json_error(500, 'query failed: ' . $e->getMessage());
		exit;
	}

	echo json_encode(array(
		'id'      => $id,
		'call'    => $call,
		'booking' => $booking,
	));
	exit;
