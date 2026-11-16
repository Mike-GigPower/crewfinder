<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include_once(dirname(__FILE__) . '/goat-db.php');
	include_once(dirname(__FILE__) . '/perf-week-lib.php');
	include_once(dirname(__FILE__) . '/perf-cost-lib.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* PERFORMANCE — WEEKLY CREW-PAY MARGIN (BRIEF-performance-cost-slice-a.md §4).
	/*
	/*   ?weeks=N (1..104, default 52)
	/*     -> {generated_at, window, weeks[], totals, exceptions, data_quality}
	/*
	/* READ ONLY. Nothing in this file writes to any table.
	/*
	/* WHO: admin cohort AND one of $GOAT_MARGIN_USERS (Mike 10261, Joe 10260;
	/* Mike, 16 Nov 2026). The same set is MARGIN_USERS in THE GOAT's app.py;
	/* both must change together. Checked here as well as in Flask because an
	/* admin logged straight into SmartStaff could otherwise open this URL in a
	/* browser.
	/*
	/* THREE READS, ONE RESPONSE (§3.1):
	/*   1. calls in the span (non-hidden bookings), by start_date -- indexed
	/*   2. pay per span call, from accounting.calculated via JSON_TABLE
	/*      (Σ timeTotal × rate). accounting has no index on callID, so this is
	/*      one scan (2.2 s over 104 weeks on prod, P3b). Flask caches the
	/*      response for 10 minutes for that reason.
	/*   3. every non-spacer line on an invoice a span call carries, with
	/*      revenue by the Xero formula IF(flatrate>0, flatrate*qty,
	/*      chargeout_rate*hours*qty). No bookings join: hand-added lines carry
	/*      bookingID 0 (P5d), and a join would drop them.
	/* The matching itself is goat_perf_cost_compute() in perf-cost-lib.php,
	/* which test-perf-cost.php exercises without a database.
	/*
	/* NEVER A PARTIAL BODY: every query is checked, and the response is built
	/* in memory before anything is echoed. A failed query is a 500 naming the
	/* query label (never the SQL).
	/*
	/* NULL calculated rows are COUNTED (data_quality.null_calculated_rows),
	/* never read as $0 pay. None exist from Nov 2024 (P2).
	/*
	/* ACCESSOR: mysqli_* on its own link, as get-booking-figures.php.
	/* PHP 5.6 -- array(), no null-coalescing, no short array syntax. */

	$GOAT_MARGIN_USERS = array(10261, 10260);

	/* ---- helpers ---- */

	function perfcost_status($code, $msg)
	{
		$proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($proto . ' ' . $code . ' ' . $msg);
	}

	function perfcost_fail($code, $msg, $body)
	{
		perfcost_status($code, $msg);
		$out = json_encode($body);
		if ($out === false)
			$out = '{"ok":false,"error":"request failed"}';
		die($out);
	}

	/* Same credential resolution as figures_mysqli() in get-booking-figures.php. */
	function perfcost_mysqli()
	{
		$host = goat_db_find_setting(array(
			'GOAT_EMERGENCY_DB_HOST','GOAT_DB_HOST','DB_HOST','DBHOST','MYSQL_HOST','SQL_HOST','HOST'));
		$user = goat_db_find_setting(array(
			'GOAT_EMERGENCY_DB_USER','GOAT_DB_USER','DB_USER','DBUSER','DB_USERNAME','MYSQL_USER','SQL_USER','USER'));
		$pass = goat_db_find_setting(array(
			'GOAT_EMERGENCY_DB_PASS','GOAT_DB_PASS','DB_PASS','DBPASS','DB_PASSWORD','DBPASSWORD','MYSQL_PASS','SQL_PASS','PASS','PASSWORD'));
		$name = goat_db_find_setting(array(
			'GOAT_EMERGENCY_DB_NAME','GOAT_DB_NAME','DB_NAME','DBNAME','MYSQL_DB','SQL_DB','DB_DATABASE','DATABASE'));

		if ($host === null) { $host = 'localhost'; }

		if ($user === null || $pass === null || $name === null)
		{
			error_log('get-performance-cost: could not resolve DB credentials');
			return null;
		}

		mysqli_report(MYSQLI_REPORT_OFF);
		$link = @mysqli_connect($host, $user, $pass, $name);

		if (!$link)
		{
			error_log('get-performance-cost: connect failed: ' . mysqli_connect_error());
			return null;
		}

		return $link;
	}

	function perfcost_query($link, $label, $sql)
	{
		$res = mysqli_query($link, $sql);

		if ($res === false)
		{
			error_log('get-performance-cost: query "' . $label . '" failed: ' . mysqli_error($link));
			perfcost_fail(500, 'Internal Server Error',
				array('error' => 'Margin query failed', 'query' => $label));
		}

		return $res;
	}

	/* ---- admin cohort, then Mike or Joe only ---- */

	if (goat_user_cohort() !== 'admin')
	{
		perfcost_fail(403, 'Forbidden', array('error' => 'Admin only'));
	}

	$sessionUser = (int) $_SESSION[SITE_KEY]['userID'];

	if (!in_array($sessionUser, $GOAT_MARGIN_USERS, true))
	{
		perfcost_fail(403, 'Forbidden', array('error' => 'Not enabled for this account'));
	}

	/* ---- window ---- */

	$win = goat_perf_window(isset($_GET['weeks']) ? $_GET['weeks'] : 52);

	$rs = (int) $win['range_start'];
	$re = (int) $win['range_end'];

	$link = perfcost_mysqli();

	if ($link === null)
	{
		perfcost_fail(500, 'Internal Server Error', array('error' => 'Database unavailable'));
	}

	/* ---- 1. calls in the span ---- */

	$calls = array();

	$res = perfcost_query($link, 'calls',
		"SELECT c.id, c.start_date, c.invoiceID, c.bookingID, c.call_name
		   FROM `calls` c
		   JOIN `bookings` b ON b.id = c.bookingID AND b.hidden = 0
		  WHERE c.start_date > 0
		    AND c.start_date BETWEEN " . $rs . " AND " . $re);

	while ($row = mysqli_fetch_assoc($res))
	{
		$calls[(int) $row['id']] = array(
			'start'   => (int) $row['start_date'],
			'invoice' => (int) $row['invoiceID'],
			'booking' => (int) $row['bookingID'],
			'name'    => (string) $row['call_name']
		);
	}

	mysqli_free_result($res);

	/* ---- 2. pay per span call ---- */

	/*
	/* The derived table parses JSON per accounting row and groups by row id,
	/* so the outer COUNT(*) counts ROWS, not buckets -- and the LEFT JOIN keeps
	/* rows whose calculated is NULL, so they are counted rather than lost (the
	/* get-booking-figures.php shape, widened from one booking to the span).
	*/

	$pay = array();

	$spanCalls = "SELECT c.id FROM `calls` c
	                JOIN `bookings` b ON b.id = c.bookingID AND b.hidden = 0
	               WHERE c.start_date > 0
	                 AND c.start_date BETWEEN " . $rs . " AND " . $re;

	$res = perfcost_query($link, 'pay',
		"SELECT a.callID,
		        COUNT(*)                  AS rows_n,
		        SUM(a.calculated IS NULL) AS null_calc,
		        SUM(p.pay)                AS pay,
		        SUM(p.chg)                AS charge
		   FROM `accounting` a
		   JOIN (" . $spanCalls . ") sc ON sc.id = a.callID
		   LEFT JOIN (SELECT a2.id,
		                     SUM(jt.t * jt.rate) AS pay,
		                     SUM(jt.t * jt.co)   AS chg
		                FROM `accounting` a2,
		                     JSON_TABLE(a2.calculated, '$[*]' COLUMNS(
		                       rate DOUBLE PATH '$.rate',
		                       co   DOUBLE PATH '$.chargeout',
		                       t    DOUBLE PATH '$.timeTotal')) jt,
		                     (" . $spanCalls . ") sc2
		               WHERE sc2.id = a2.callID
		               GROUP BY a2.id) p ON p.id = a.id
		  GROUP BY a.callID");

	while ($row = mysqli_fetch_assoc($res))
	{
		$pay[(int) $row['callID']] = array(
			'rows'      => (int) $row['rows_n'],
			'null_calc' => (int) $row['null_calc'],
			'pay'       => $row['pay']    === null ? 0.0 : (float) $row['pay'],
			'charge'    => $row['charge'] === null ? 0.0 : (float) $row['charge']
		);
	}

	mysqli_free_result($res);

	/* ---- 3. lines on the invoices those calls carry ---- */

	$lines = array();

	$res = perfcost_query($link, 'invoice lines',
		"SELECT il.invoiceID, il.callID, il.start_date,
		        IF(il.flatrate > 0, il.flatrate * il.qty,
		           il.chargeout_rate * il.hours * il.qty) AS revenue
		   FROM `invoice_lines` il
		   JOIN (SELECT DISTINCT c.invoiceID
		           FROM `calls` c
		           JOIN `bookings` b ON b.id = c.bookingID AND b.hidden = 0
		          WHERE c.start_date > 0
		            AND c.start_date BETWEEN " . $rs . " AND " . $re . "
		            AND c.invoiceID > 0) inv ON inv.invoiceID = il.invoiceID
		  WHERE NOT (il.hours = 0 AND il.flatrate = 0)");

	while ($row = mysqli_fetch_assoc($res))
	{
		$lines[] = array(
			'invoice' => (int) $row['invoiceID'],
			'call'    => $row['callID'] === null ? 0 : (int) $row['callID'],
			'start'   => (int) $row['start_date'],
			'revenue' => $row['revenue'] === null ? 0.0 : (float) $row['revenue']
		);
	}

	mysqli_free_result($res);

	/* ---- compute, then answer ---- */

	$r = goat_perf_cost_compute($win, $calls, $pay, $lines, 50);

	/*
	/* call_name is the only free text in the response. Names are stored
	/* entity-encoded, so they are normally plain ASCII -- but one stray latin1
	/* byte would make json_encode() fail and take the whole card down. Only
	/* a name that would fail is converted; every other name passes through
	/* as stored (the front end decodes entities before escaping).
	*/
	for ($i = 0; $i < count($r['exceptions']['not_on_invoice']); $i++)
	{
		$nm = $r['exceptions']['not_on_invoice'][$i]['call_name'];
		if (json_encode($nm) === false)
			$r['exceptions']['not_on_invoice'][$i]['call_name'] = iconv('ISO-8859-1', 'UTF-8//IGNORE', $nm);
	}

	$body = array(
		'generated_at' => date('Y-m-d\TH:i:s'),
		'window'       => array(
			'weeks'       => (int) $win['weeks'],
			'range_start' => $rs,
			'range_end'   => $re,
			'week_keys'   => $win['week_keys']
		),
		'weeks'        => $r['weeks'],
		'totals'       => $r['totals'],
		'exceptions'   => $r['exceptions'],
		'data_quality' => $r['data_quality']
	);

	$out = json_encode($body);

	if ($out === false)
	{
		error_log('get-performance-cost: json_encode failed: ' . json_last_error_msg());
		perfcost_fail(500, 'Internal Server Error', array('error' => 'Could not encode margin'));
	}

	echo $out;
