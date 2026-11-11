<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include_once(dirname(__FILE__) . '/goat-db.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* BOOKING FIGURES (BRIEF-booking-documents-figures.md §4, v0.2).
	/*
	/* Everything the Figures tab needs for ONE booking, in ONE response:
	/* its calls, its confirmed and paid-cancelled crew rows, what was
	/* invoiced per call, and what the crew were paid per call.
	/*
	/*   ?id={bookingID}   ->  {booking_id, calls:[], crew:[], invoiced:{},
	/*                          pay:{}, capabilities:{}}
	/*
	/* READ ONLY. ADMIN ONLY. Nothing in this file writes to any table, and
	/* nothing here talks to Supabase, Xero or Crew Hub.
	/*
	/* ONE REQUEST, NOT THREE -- THE GOAT cannot run concurrent /ajax/crew/
	/* requests on one session (the per-session PHP lock noted in
	/* get-performance.php), so three endpoints would serialise anyway and
	/* three round trips would each pay the session lock.
	/*
	/* NEVER A PARTIAL BODY (§4.2). Every query's result is checked against
	/* false and the whole response is assembled in memory before anything is
	/* echoed. A failed query is a 500 naming the query that failed, because a
	/* Figures tab showing $0 "because a query quietly returned nothing" is
	/* exactly the silent-wrong-number this feature exists to prevent.
	/*
	/* MONEY IS RETURNED UNROUNDED (§4.4). These are floats straight from
	/* MySQL's SUM(). All rounding happens once, in Python, in Decimal
	/* (invoice_expect.py §5.2) -- rounding here as well would round twice and
	/* put the booking total a cent out.
	/*
	/* TIMES are returned exactly as the columns hold them: `on`, `off`,
	/* `break` and `break_night` are TIME columns, so they arrive as
	/* "HH:MM:SS". The §4.4 sketch writes some of them "HH:MM"; the parser in
	/* invoice_expect.py splits on ':' and reads [0] and [1], so both forms
	/* work, and passing the stored bytes through unchanged is the honest one.
	/*
	/* A2 (§14): PROMO-PENDING ROWS ARE FLAGGED, NOT DROPPED. A crew member
	/* promoted off backup who has not answered is still status 5 in
	/* call_crew_map -- the flag lives in call_promo_ack. They are returned
	/* with promo_pending = 1 so the tab can leave them out of SCHEDULED hours
	/* (matching the call dialog's filledCount, index.html l. 23933) while
	/* still pricing them in Expected and counting their times in Actual. The
	/* guard is the same one get-booking.php l. 509 uses.
	/*
	/* A7 (§14): CANCELLED ROWS CARRY BOTH FLAGS. Paid cancellations are
	/* fetched with goat_ccm_cancel_paid_sql() -- the single copy of that test
	/* in THE GOAT's PHP (cohort.php l. 256) -- and every row returns BOTH
	/* cancel_pay and cancel_charge, because the two drive different things:
	/* Expected invoice prices rows with cancel_charge = 1, while the
	/* "Cancelled - paid" hours row (Q7) counts rows with cancel_pay = 1. A
	/* pay-only cancellation that SmartStaff invoiced anyway shows amber,
	/* which is the D20 manual-fix reminder working as intended.
	/*
	/* ACCESSOR: this file's own queries are mysqli_*, on its own link, exactly
	/* as reoffer-declined.php does (l. 80-109). The two capability helpers it
	/* borrows from cohort.php (goat_ccm_have_cancel_pay) use the legacy mysql_*
	/* connection global.php opens; both reach the same database, and neither
	/* writes, so there is no transaction to span.
	/*
	/* PHP 5.6 -- array(), no null-coalescing, no short array syntax. */

	/* ---- helpers ---- */

	function send_status($code, $msg)
	{
		$proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($proto . ' ' . $code . ' ' . $msg);
	}

	function figures_fail($code, $msg, $body)
	{
		send_status($code, $msg);
		$out = json_encode($body);
		if ($out === false)
			$out = '{"ok":false,"error":"request failed"}';
		die($out);
	}

	/*
	/* A mysqli link with the same credentials goat_pdo() resolves. No charset
	/* is set, deliberately: the database default is latin1 and the legacy
	/* connection every other call-name reader uses sets none either, so
	/* call_name comes back as the same bytes get-booking.php emits. Copied in
	/* shape from reoffer_mysqli() (reoffer-declined.php l. 80).
	*/

	function figures_mysqli()
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
			error_log('get-booking-figures: could not resolve DB credentials');
			return null;
		}

		mysqli_report(MYSQLI_REPORT_OFF);
		$link = @mysqli_connect($host, $user, $pass, $name);

		if (!$link)
		{
			error_log('get-booking-figures: connect failed: ' . mysqli_connect_error());
			return null;
		}

		return $link;
	}

	/*
	/* Run one query or die with a 500 NAMING IT. $label is what the operator
	/* reads in the response and in the error log; it never carries the SQL,
	/* which would put table and column names in front of a browser.
	*/

	function figures_query($link, $label, $sql)
	{
		$res = mysqli_query($link, $sql);

		if ($res === false)
		{
			error_log('get-booking-figures: query "' . $label . '" failed: ' . mysqli_error($link));
			figures_fail(500, 'Internal Server Error',
				array('error' => 'Figures query failed', 'query' => $label));
		}

		return $res;
	}

	function figures_has_table($link, $table)
	{
		$r = mysqli_query($link, "SHOW TABLES LIKE '" . mysqli_real_escape_string($link, $table) . "'");
		return ($r !== false && mysqli_num_rows($r) > 0);
	}

	/* ---- admin only (same gate as reoffer-declined.php) ---- */

	if (goat_user_cohort() !== 'admin')
	{
		figures_fail(403, 'Forbidden', array('error' => 'Admin only'));
	}

	/* ---- the booking ---- */

	$bookingID = isset($_GET['id']) ? (int) $_GET['id'] : 0;

	if ($bookingID <= 0)
	{
		figures_fail(400, 'Bad Request', array('error' => 'id required'));
	}

	$link = figures_mysqli();

	if ($link === null)
	{
		figures_fail(500, 'Internal Server Error', array('error' => 'Database unavailable'));
	}

	/*
	/* CAPABILITIES. Both are reported in the response so the tab can say what
	/* it could not see, rather than printing a smaller number as though it
	/* were the whole truth.
	/*
	/*   cancel_pay -- goat_ccm_have_cancel_pay() (cohort.php l. 302), the
	/*   house guard for MIGRATION-late-cancellation-pay. Both columns were
	/*   confirmed present on prod on 11 Nov; the guard is here so a test box
	/*   that has not had the migration returns confirmed rows and says so,
	/*   instead of failing every query that mentions cancel_pay.
	/*
	/*   promo_ack -- call_promo_ack arrived in 5.83.0. Same reasoning.
	*/

	$haveCancelPay = goat_ccm_have_cancel_pay();
	$havePromoAck  = figures_has_table($link, 'call_promo_ack');

	/* ---- 1. calls ---- */

	/*
	/* Cancelled calls are NOT filtered out, the same decision get-booking.php
	/* l. 310 makes and for the same reason: a cancelled call is still part of
	/* its booking's history and may still be on its invoice. The tab renders
	/* them muted and leaves them out of the Scheduled total itself.
	*/

	$callCancelSel = $haveCancelPay ? ', c.cancel_pay' : '';

	$calls   = array();
	$callIDs = array();

	$res = figures_query($link, 'calls',
		"SELECT c.id, c.call_name, c.start_date, c.start_time, c.est_length,
		        c.required, c.call_locked, c.invoiceID, c.invoice_generated,
		        c.cancelled_at" . $callCancelSel . "
		   FROM `calls` c
		  WHERE c.bookingID = " . $bookingID . "
		  ORDER BY c.start_date ASC, c.start_time ASC");

	while ($row = mysqli_fetch_assoc($res))
	{
		$callID    = (int) $row['id'];
		$callIDs[] = $callID;

		$calls[] = array(
			'call_id'           => $callID,
			'call_name'         => $row['call_name'],
			'start_date'        => (int) $row['start_date'],
			'start_time'        => $row['start_time'],
			/* est_length is DECIMAL HOURS (8.5 = 8h30m) and 0 occurs on prod. */
			'est_length'        => $row['est_length'] === null ? null : (float) $row['est_length'],
			'required'          => (int) $row['required'],
			'call_locked'       => (int) $row['call_locked'],
			'invoice_id'        => (int) $row['invoiceID'],
			'invoice_generated' => (int) $row['invoice_generated'],
			'cancelled_at'      => $row['cancelled_at'] === null ? null : (int) $row['cancelled_at'],
			'cancel_pay'        => $haveCancelPay ? (int) $row['cancel_pay'] : 0
		);
	}

	mysqli_free_result($res);

	/* ---- 2. crew rows ---- */

	/*
	/* Confirmed rows, plus paid cancellations (A7). The paid test is built by
	/* goat_ccm_cancel_paid_sql() rather than written out here, so this
	/* endpoint cannot drift from cancel-call.php, update-call-times.php and
	/* the Times outstanding lane. Before the migration there is no
	/* cancel_pay column, so the clause is dropped entirely and only confirmed
	/* rows come back -- which is what an unmigrated database can still mean.
	/*
	/* is_call_boss is deliberately NOT selected: the trial method prices from
	/* the row's callpaygradeID (§5.2), not from who is wearing the boss hat.
	/*
	/* The reserved words `on`, `off` and `break` are backticked. So is
	/* `calls`, which is not reserved but reads as a keyword.
	*/

	$crewWhere = $haveCancelPay
	           ? "(ccm.status = 5 OR " . goat_ccm_cancel_paid_sql('ccm') . ")"
	           : "ccm.status = 5";

	/*
	/* A2: promo_pending. call_promo_ack carries its OWN callID, so every
	/* column here is qualified (get-call-times.php l. 88 learned that the
	/* hard way). The status = 5 guard is inside the expression, matching
	/* get-booking.php l. 509, so a stale ack row on someone since cancelled
	/* can never raise the flag.
	*/

	if ($havePromoAck)
	{
		$promoSel  = ", (ccm.status = 5 AND cpa.promoted_at IS NOT NULL
		                 AND cpa.acked_at IS NULL) AS promo_pending";
		$promoJoin = " LEFT JOIN call_promo_ack cpa
		                      ON cpa.callID = ccm.callID
		                     AND cpa.userID = ccm.userID";
	}
	else
	{
		$promoSel  = "";
		$promoJoin = "";
	}

	$cancelSel = $haveCancelPay ? ", ccm.cancel_pay, ccm.cancel_charge" : "";

	$crew = array();

	$res = figures_query($link, 'crew',
		"SELECT ccm.callID, ccm.userID, ccm.status, ccm.prev_status,
		        ccm.callpaygradeID, ccm.`on`, ccm.`off`, ccm.`break`,
		        ccm.break_night" . $cancelSel . $promoSel . "
		   FROM `call_crew_map` ccm
		   JOIN `calls` c ON c.id = ccm.callID" . $promoJoin . "
		  WHERE c.bookingID = " . $bookingID . "
		    AND " . $crewWhere);

	while ($row = mysqli_fetch_assoc($res))
	{
		$crew[] = array(
			'call_id'       => (int) $row['callID'],
			'user_id'       => (int) $row['userID'],
			'status'        => (int) $row['status'],
			'prev_status'   => $row['prev_status'] === null ? 0 : (int) $row['prev_status'],
			'paygrade_id'   => (int) $row['callpaygradeID'],
			'on'            => $row['on'],
			'off'           => $row['off'],
			'break'         => $row['break'],
			'break_night'   => $row['break_night'],
			/* A7: BOTH flags, every row. They drive different things. */
			'cancel_pay'    => $haveCancelPay ? (int) $row['cancel_pay'] : 0,
			'cancel_charge' => $haveCancelPay ? (int) $row['cancel_charge'] : 0,
			/* A2: 1 only on an unanswered promotion that is still status 5. */
			'promo_pending' => $havePromoAck ? (int) $row['promo_pending'] : 0
		);
	}

	mysqli_free_result($res);

	/* ---- 3. invoiced per call ---- */

	/*
	/* The Xero export's own formula (generateNewInvoicesCSV, design §5.5), so
	/* this figure matches what was actually sent to Xero rather than a
	/* second opinion about it. start_date > 0 drops the "<< BLANK_LINE >>"
	/* spacer rows.
	*/

	$invoiced = array();

	$res = figures_query($link, 'invoiced',
		"SELECT callID,
		        COUNT(*) AS lines_n,
		        SUM(hours * qty) AS hours,
		        SUM(IF(flatrate > 0, flatrate * qty, chargeout_rate * hours * qty)) AS ex_gst
		   FROM `invoice_lines`
		  WHERE bookingID = " . $bookingID . "
		    AND start_date > 0
		  GROUP BY callID");

	while ($row = mysqli_fetch_assoc($res))
	{
		$invoiced[(string) (int) $row['callID']] = array(
			'lines'  => (int) $row['lines_n'],
			'hours'  => $row['hours']  === null ? 0.0 : (float) $row['hours'],
			'ex_gst' => $row['ex_gst'] === null ? 0.0 : (float) $row['ex_gst']
		);
	}

	mysqli_free_result($res);

	/* ---- 4. pay per call ---- */

	/*
	/* Cost comes from each row's `calculated` JSON buckets, NEVER from
	/* day_hours/night_hours: P2 found those are 0 on all 54 rows of #12220,
	/* so the day/night columns would report $0 pay for a booking that cost
	/* $14,481.42. Sum(timeTotal x rate) is pay; Sum(timeTotal x chargeout) is
	/* the charge, which equals invoice_lines to the cent and is therefore a
	/* free self-check.
	/*
	/* MariaDB 11.4.13 has JSON_TABLE. The derived table groups the buckets per
	/* accounting row first, so the outer COUNT(*) still counts ROWS rather
	/* than buckets.
	/*
	/* Reading this per booking (not per call) in one pass keeps it to a single
	/* scan of a table with no index on callID.
	*/

	$pay = array();

	$res = figures_query($link, 'pay',
		"SELECT a.callID,
		        COUNT(*) AS rows_n,
		        SUM(a.calculated IS NULL) AS null_calc,
		        SUM(b.h) AS hours,
		        SUM(b.pay) AS pay,
		        SUM(b.chg) AS charge
		   FROM `accounting` a
		   LEFT JOIN (SELECT a2.id,
		                     SUM(jt.t) AS h,
		                     SUM(jt.t * jt.rate) AS pay,
		                     SUM(jt.t * jt.co) AS chg
		                FROM `accounting` a2,
		                     JSON_TABLE(a2.calculated, '$[*]' COLUMNS(
		                       rate DOUBLE PATH '$.rate',
		                       co   DOUBLE PATH '$.chargeout',
		                       t    DOUBLE PATH '$.timeTotal')) jt
		               WHERE a2.bookingID = " . $bookingID . "
		               GROUP BY a2.id) b ON b.id = a.id
		  WHERE a.bookingID = " . $bookingID . "
		  GROUP BY a.callID");

	while ($row = mysqli_fetch_assoc($res))
	{
		$pay[(string) (int) $row['callID']] = array(
			'rows'       => (int) $row['rows_n'],
			'null_calc'  => (int) $row['null_calc'],
			'hours'      => $row['hours']  === null ? 0.0 : (float) $row['hours'],
			'pay'        => $row['pay']    === null ? 0.0 : (float) $row['pay'],
			'charge'     => $row['charge'] === null ? 0.0 : (float) $row['charge'],
			'legacy_pay' => 0.0
		);
	}

	mysqli_free_result($res);

	/* ---- 5. legacy pay, for rows with no `calculated` ---- */

	/*
	/* §4.3 query 4: "For rows with null_calc > 0, also return
	/* SUM(day_hours*day_rate + night_hours*night_rate) for those rows only,
	/* as legacy_pay, and report the count. Don't quietly mix the two."
	/*
	/* So this is a SEPARATE number on the call, not added into `pay`. The tab
	/* decides whether to show it and says where it came from; adding it in
	/* here would produce one total nobody could take apart again.
	*/

	$res = figures_query($link, 'legacy_pay',
		"SELECT callID,
		        COUNT(*) AS rows_n,
		        SUM(day_hours * day_rate + night_hours * night_rate) AS legacy_pay,
		        SUM(day_hours + night_hours) AS legacy_hours
		   FROM `accounting`
		  WHERE bookingID = " . $bookingID . "
		    AND calculated IS NULL
		  GROUP BY callID");

	while ($row = mysqli_fetch_assoc($res))
	{
		$k = (string) (int) $row['callID'];

		if (!isset($pay[$k]))
		{
			/* Can't happen -- query 4 covers every accounting row for this
			/* booking -- but an absent key would be a silent zero, so build
			/* the entry rather than drop the money on the floor. */
			$pay[$k] = array('rows' => 0, 'null_calc' => 0, 'hours' => 0.0,
			                 'pay' => 0.0, 'charge' => 0.0, 'legacy_pay' => 0.0);
		}

		$pay[$k]['legacy_pay']   = $row['legacy_pay'] === null ? 0.0 : (float) $row['legacy_pay'];
		$pay[$k]['legacy_hours'] = $row['legacy_hours'] === null ? 0.0 : (float) $row['legacy_hours'];
		$pay[$k]['legacy_rows']  = (int) $row['rows_n'];
	}

	mysqli_free_result($res);
	mysqli_close($link);

	/* ---- response ---- */

	/*
	/* Assembled only now, after every query has come back. json_encode is
	/* checked: a float that will not encode (NAN/INF from a corrupt row)
	/* must be a 500, not the literal "false" written into the response body.
	/*
	/* JSON_OBJECT is forced on invoiced and pay: both are keyed by call ID,
	/* and PHP would emit a JSON ARRAY for either if the keys happened to run
	/* 0,1,2.. -- which would quietly change the shape slice C parses. Empty
	/* ones must be {} too, so an empty array() is cast.
	*/

	$body = array(
		'booking_id'   => $bookingID,
		'calls'        => $calls,
		'crew'         => $crew,
		'invoiced'     => (object) $invoiced,
		'pay'          => (object) $pay,
		'capabilities' => array(
			'cancel_pay' => $haveCancelPay ? true : false,
			'promo_ack'  => $havePromoAck ? true : false
		)
	);

	$out = json_encode($body);

	if ($out === false)
	{
		error_log('get-booking-figures: json_encode failed for booking ' . $bookingID
		          . ': ' . json_last_error_msg());
		figures_fail(500, 'Internal Server Error', array('error' => 'Could not encode figures'));
	}

	echo $out;
