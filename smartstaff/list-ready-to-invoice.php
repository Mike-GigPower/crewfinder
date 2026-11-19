<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include_once(dirname(__FILE__) . '/goat-db.php');
	include_once(dirname(__FILE__) . '/accounting-ready-lib.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* READY TO INVOICE -- ONE LIST (BRIEF-accounting-slice-b.md §3.1).
	/*
	/*   ?all=0|1
	/*     -> {window_months, all, truncated, total_found, bookings:[...]}
	/*
	/* READ ONLY. Nothing in this file writes to any table. It answers one
	/* question: which bookings would SmartStaff's New Invoice page offer a
	/* Generate button for, and what would each one come to?
	/*
	/* THE WORK IS IN accounting-ready-lib.php, because generate-invoice.php
	/* has to re-read exactly the same state at the moment it writes. Read that
	/* file for what "ready", "stuck" and expected_exact mean and for which
	/* line of class.accounting.php each condition is copied from.
	/*
	/* WHO: admin cohort AND one of $GOAT_ACCOUNTING_USERS -- today 10261,
	/* Mike's admin login. The same set is ACCOUNTING_USERS in THE GOAT's
	/* app.py; ALL FIVE PLACES MUST CHANGE TOGETHER (app.py, list-invoices.php,
	/* get-invoice.php, this file, generate-invoice.php). Checked here as well
	/* as in Flask because an admin logged straight into SmartStaff could
	/* otherwise open this URL in a browser.
	/*
	/* THE DEFAULT WINDOW IS 12 MONTHS. ?all=1 drops it and caps at 300
	/* bookings, saying so with truncated -- the 2010-11 legacy block alone is
	/* 1,672 calls (P3d) and nobody is going to invoice those today.
	/*
	/* NEVER A PARTIAL BODY: the scan checks every query and the whole response
	/* is assembled in memory before anything is echoed. A failed query is a
	/* 500 NAMING the query label, never the SQL.
	/*
	/* PHP 5.6 -- array(), no null-coalescing, no short array syntax. */

	$GOAT_ACCOUNTING_USERS = array(10261);   /* = ACCOUNTING_USERS in app.py; change both together */

	/* ---- helpers ---- */

	function send_status($code, $msg)
	{
		$proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($proto . ' ' . $code . ' ' . $msg);
	}

	function ready_fail($code, $msg, $body)
	{
		send_status($code, $msg);
		$out = json_encode($body);
		if ($out === false)
			$out = '{"ok":false,"error":"request failed"}';
		die($out);
	}

	/* ---- admin cohort, then the accounting users only ---- */

	if (goat_user_cohort() !== 'admin')
	{
		ready_fail(403, 'Forbidden', array('error' => 'Admin only'));
	}

	$sessionUser = (int) $_SESSION[SITE_KEY]['userID'];

	if (!in_array($sessionUser, $GOAT_ACCOUNTING_USERS, true))
	{
		ready_fail(403, 'Forbidden', array('error' => 'Not enabled for this account'));
	}

	/* ---- parameters ---- */

	$all = (isset($_GET['all']) && (string) $_GET['all'] === '1');

	$link = goat_ready_connect('list-ready-to-invoice');

	if ($link === null)
	{
		ready_fail(500, 'Internal Server Error', array('error' => 'Database unavailable'));
	}

	/* ---- the scan ---- */

	$scan = goat_ready_scan($link, 'list-ready-to-invoice',
		array('all' => $all, 'cap' => true));

	mysqli_close($link);

	if (!$scan['ok'])
	{
		ready_fail(500, 'Internal Server Error',
			array('error' => 'Ready-to-invoice query failed', 'query' => $scan['query']));
	}

	$bookings = array();

	foreach ($scan['order'] as $bid)
		$bookings[] = $scan['bookings'][$bid];

	$body = json_encode(array(
		'window_months' => $all ? null : GOAT_READY_WINDOW_MONTHS,
		'all'           => $all,
		'truncated'     => $scan['truncated'],
		'total_found'   => $scan['total_found'],
		'bookings'      => $bookings
	));

	if ($body === false)
	{
		error_log('list-ready-to-invoice: json_encode failed: ' . json_last_error_msg());
		ready_fail(500, 'Internal Server Error',
			array('error' => 'Ready-to-invoice list could not be encoded'));
	}

	echo $body;
