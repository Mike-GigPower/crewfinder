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
	/* ONE INVOICE -- HEADER, LINES AND THE CALLS IT COVERS
	/* (BRIEF-accounting-slice-a.md §4.3).
	/*
	/*   ?id={invoiceID}
	/*     -> {invoice:{}, booking:{}, customer:{}, contact:{}, venue:{},
	/*         lines:[], calls:[], extra_line_call_ids:[], hand_added_lines,
	/*         capabilities:{}}
	/*
	/* READ ONLY. Nothing in this file writes to any table. Generate, Edit,
	/* Delete, Email and Export all stay in SmartStaff (design D2).
	/*
	/* WHO: admin cohort AND one of $GOAT_ACCOUNTING_USERS -- today 10261,
	/* Mike's admin login. The same set is ACCOUNTING_USERS in THE GOAT's
	/* app.py; BOTH MUST CHANGE TOGETHER. Checked here as well as in Flask
	/* because an admin logged straight into SmartStaff could otherwise open
	/* this URL in a browser (the get-performance-cost.php two-step gate).
	/*
	/* THREE READS, ONE RESPONSE:
	/*   1. the header -- invoice, booking, customer, booking contact, venue
	/*   2. every line of the invoice, RAW, INCLUDING THE SPACER ROWS, in
	/*      invoice.php's own order, so THE GOAT's lines and totals match the
	/*      PDF rather than offering a second opinion about it
	/*   3. the calls named in call_list
	/*
	/* NEVER A PARTIAL BODY: every query's result is checked against false and
	/* the whole response is assembled in memory before anything is echoed. A
	/* failed query is a 500 NAMING the query label (never the SQL).
	/*
	/* MONEY AND TIMES ARE RETURNED RAW. chargeout_rate, hours, qty and
	/* flatrate are the stored values, unrounded; start_time and end_time are
	/* the stored bytes. THE GOAT does the arithmetic once, in Decimal
	/* (accounting_invoices.py), and reads the times there too -- because
	/* start_time holds EITHER a unix timestamp as text (older generated lines)
	/* OR "HH:MM" / "HH:MM:SS" (class.accounting.php writes date('H:i') on the
	/* `calculated` path and SEC_TO_TIME() on the legacy one, and SmartStaff's
	/* Edit page writes whatever was typed). Guessing which, here, would be a
	/* guess in the one place nobody would look again.
	/*
	/* '<< BLANK_LINE >>' lines are RETURNED, not filtered: they are the
	/* generator's spacer rows (class.accounting.php l. 494) and THE GOAT draws
	/* them as a divider. Dropping them here would silently change the line
	/* order the PDF uses.
	/*
	/* NAMES COME BACK AS STORED -- HTML-ENCODED. THE GOAT runs
	/* decodeEntities() then escapeHtml() on them; decoding here would decode
	/* twice.
	/*
	/* ACCESSOR: mysqli_* on its own link, as get-booking-figures.php.
	/* PHP 5.6 -- array(), no null-coalescing, no short array syntax. */

	$GOAT_ACCOUNTING_USERS = array(10261);   /* = ACCOUNTING_USERS in app.py; change both together */

	/* ---- helpers ---- */

	function send_status($code, $msg)
	{
		$proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($proto . ' ' . $code . ' ' . $msg);
	}

	function invone_fail($code, $msg, $body)
	{
		send_status($code, $msg);
		$out = json_encode($body);
		if ($out === false)
			$out = '{"ok":false,"error":"request failed"}';
		die($out);
	}

	/* Same credential resolution as figures_mysqli() in get-booking-figures.php. */
	function invone_mysqli()
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
			error_log('get-invoice: could not resolve DB credentials');
			return null;
		}

		mysqli_report(MYSQLI_REPORT_OFF);
		$link = @mysqli_connect($host, $user, $pass, $name);

		if (!$link)
		{
			error_log('get-invoice: connect failed: ' . mysqli_connect_error());
			return null;
		}

		return $link;
	}

	function invone_query($link, $label, $sql)
	{
		$res = mysqli_query($link, $sql);

		if ($res === false)
		{
			error_log('get-invoice: query "' . $label . '" failed: ' . mysqli_error($link));
			invone_fail(500, 'Internal Server Error',
				array('error' => 'Invoice query failed', 'query' => $label));
		}

		return $res;
	}

	function invone_has_column($link, $table, $column)
	{
		$sql = "SHOW COLUMNS FROM `" . $table . "` LIKE '"
		     . mysqli_real_escape_string($link, $column) . "'";

		$r = mysqli_query($link, $sql);

		return ($r !== false && mysqli_num_rows($r) > 0);
	}

	/* ---- admin cohort, then the accounting users only ---- */

	if (goat_user_cohort() !== 'admin')
	{
		invone_fail(403, 'Forbidden', array('error' => 'Admin only'));
	}

	$sessionUser = (int) $_SESSION[SITE_KEY]['userID'];

	if (!in_array($sessionUser, $GOAT_ACCOUNTING_USERS, true))
	{
		invone_fail(403, 'Forbidden', array('error' => 'Not enabled for this account'));
	}

	/* ---- the invoice id ---- */

	/*
	/* A missing or non-numeric id is a 400, NOT a 404: "you asked wrongly" and
	/* "there is no such invoice" are different answers and THE GOAT shows them
	/* differently. ctype_digit rather than (int), so "abc" is a 400 instead of
	/* becoming a lookup for invoice 0.
	*/

	$raw = isset($_GET['id']) ? trim((string) $_GET['id']) : '';

	if ($raw === '' || !ctype_digit($raw) || (int) $raw <= 0)
	{
		invone_fail(400, 'Bad Request', array('error' => 'A numeric id is required'));
	}

	$invoiceID = (int) $raw;

	$link = invone_mysqli();

	if ($link === null)
	{
		invone_fail(500, 'Internal Server Error', array('error' => 'Database unavailable'));
	}

	/*
	/* UTF-8 on the wire, for the same reason as list-invoices.php: json_encode
	/* refuses invalid UTF-8, so one latin1 apostrophe in a customer name would
	/* turn the whole invoice into a 500.
	*/
	if (function_exists('mysqli_set_charset'))
	{
		if (!@mysqli_set_charset($link, 'utf8mb4'))
			@mysqli_set_charset($link, 'utf8');
	}

	/*
	/* CAPABILITY: the billing address columns. invoice_header.tpl prints
	/* address_billing and falls back to the main address when it is empty, so
	/* THE GOAT must do the same -- but a database without the columns would
	/* fail the header query outright and cost the whole invoice. Checked and
	/* reported, in the get-booking-figures.php shape.
	*/

	$haveBilling = invone_has_column($link, 'customers', 'address_billing');

	/* ---- 1. the header ---- */

	$billingSel = $haveBilling
	            ? ', customers.address_billing, customers.suburb_billing,
	                 customers.state_billing, customers.postcode_billing'
	            : '';

	$res = invone_query($link, 'header',
		"SELECT invoices.id, invoices.bookingID, invoices.invoice_generated,
		        invoices.invoice_status, invoices.invoice_sent, invoices.invoice_paid,
		        invoices.invoice_exported, invoices.call_list,
		        bookings.name AS booking_name, bookings.reference AS booking_reference,
		        bookings.customerID, bookings.userID AS contact_user_id, bookings.venueID,
		        customers.customer_name, customers.email AS customer_email,
		        customers.phone AS customer_phone,
		        customers.address, customers.suburb, customers.state, customers.postcode"
		        . $billingSel . ",
		        users.firstname AS contact_first, users.lastname AS contact_last,
		        venues.venue AS venue_name
		   FROM `invoices`
		   LEFT JOIN `bookings`  ON bookings.id  = invoices.bookingID
		   LEFT JOIN `customers` ON customers.id = bookings.customerID
		   LEFT JOIN `users`     ON users.id     = bookings.userID
		   LEFT JOIN `venues`    ON venues.id    = bookings.venueID
		  WHERE invoices.id = " . $invoiceID . "
		  LIMIT 1");

	$h = mysqli_fetch_assoc($res);

	mysqli_free_result($res);

	if ($h === null)
	{
		mysqli_close($link);
		invone_fail(404, 'Not Found', array('error' => 'Invoice not found'));
	}

	/*
	/* THE BILLING ADDRESS, exactly as invoice_header.tpl resolves it: the
	/* billing block when it has a street line, otherwise the main address.
	/* Which one was used is reported (billing_is_fallback), because "this
	/* customer has no billing address" is a real thing for Mike to see rather
	/* than something to hide behind an identical-looking block.
	*/

	$addr = array(
		'address'  => $h['address'],
		'suburb'   => $h['suburb'],
		'state'    => $h['state'],
		'postcode' => $h['postcode']
	);

	$billingFallback = true;

	if ($haveBilling && trim((string) $h['address_billing']) !== '')
	{
		$addr = array(
			'address'  => $h['address_billing'],
			'suburb'   => $h['suburb_billing'],
			'state'    => $h['state_billing'],
			'postcode' => $h['postcode_billing']
		);

		$billingFallback = false;
	}

	/*
	/* call_list is a comma-separated text column and prod holds trailing
	/* commas and empty segments in it, so every segment is intval'd and the
	/* zeroes dropped. Order is preserved: it is the order the invoice was
	/* generated from.
	*/

	$callList = array();

	if ($h['call_list'] !== null && $h['call_list'] !== '')
	{
		foreach (explode(',', (string) $h['call_list']) as $seg)
		{
			$cid = (int) trim($seg);

			if ($cid > 0 && !in_array($cid, $callList, true)) { $callList[] = $cid; }
		}
	}

	/* ---- 2. the lines ---- */

	/*
	/* invoice.php's own ORDER BY, so the lines THE GOAT shows are in the order
	/* the PDF prints them and the spacer rows land between the right calls.
	*/

	$lines           = array();
	$lineCallIDs     = array();
	$handAddedLines  = 0;

	$res = invone_query($link, 'lines',
		"SELECT id, bookingID, callID, start_date, start_time, end_time, hours,
		        chargeout_rate, description, qty, invoiceID, flatrate, break_time
		   FROM `invoice_lines`
		  WHERE invoiceID = " . $invoiceID . "
		  ORDER BY id, callID, start_date, start_time");

	while ($row = mysqli_fetch_assoc($res))
	{
		$callID = (int) $row['callID'];

		if ($callID > 0)
		{
			if (!in_array($callID, $lineCallIDs, true)) { $lineCallIDs[] = $callID; }
		}
		else
		{
			/* callID 0: a line typed in by hand on SmartStaff's Edit page,
			/* tied to no call (P5d found these carry bookingID 0 too). */
			$handAddedLines++;
		}

		$lines[] = array(
			'id'             => (int) $row['id'],
			'booking_id'     => (int) $row['bookingID'],
			'call_id'        => $callID,
			'start_date'     => (int) $row['start_date'],
			'start_time'     => $row['start_time'],
			'end_time'       => $row['end_time'],
			'hours'          => $row['hours']          === null ? 0.0 : (float) $row['hours'],
			'chargeout_rate' => $row['chargeout_rate'] === null ? 0.0 : (float) $row['chargeout_rate'],
			'description'    => $row['description'],
			'qty'            => (int) $row['qty'],
			'invoice_id'     => (int) $row['invoiceID'],
			'flatrate'       => $row['flatrate']       === null ? 0.0 : (float) $row['flatrate'],
			'break_time'     => $row['break_time']
		);
	}

	mysqli_free_result($res);

	/*
	/* A line whose callID is NOT in call_list. The two disagreeing is the
	/* §3.6 pattern worth seeing: the invoice bills a call it does not claim to
	/* cover. Computed from the lines already fetched -- no extra query.
	*/

	$extraLineCallIDs = array();

	foreach ($lineCallIDs as $cid)
	{
		if (!in_array($cid, $callList, true)) { $extraLineCallIDs[] = $cid; }
	}

	/* ---- 3. the calls on the invoice ---- */

	/*
	/* has_lines comes from the lines above, not from a second probe, so the
	/* "invoiced but no line" pattern costs nothing. calls.invoiceID is read as
	/* it is NOW: an invoice that still lists a call the call has since moved
	/* off is exactly what current_invoice_id is for.
	/*
	/* A call_list id with no row in `calls` keeps its place with found = 0
	/* rather than vanishing: a deleted call still explains a missing line.
	*/

	$calls = array();

	if (count($callList) > 0)
	{
		$found = array();

		$res = invone_query($link, 'calls',
			"SELECT id, call_name, start_date, bookingID, invoiceID
			   FROM `calls`
			  WHERE id IN (" . implode(',', $callList) . ")");

		while ($row = mysqli_fetch_assoc($res))
		{
			$found[(int) $row['id']] = $row;
		}

		mysqli_free_result($res);

		foreach ($callList as $cid)
		{
			$hasLines = in_array($cid, $lineCallIDs, true) ? 1 : 0;

			if (isset($found[$cid]))
			{
				$row = $found[$cid];

				$calls[] = array(
					'call_id'            => $cid,
					'call_name'          => $row['call_name'],
					'start_date'         => (int) $row['start_date'],
					'booking_id'         => (int) $row['bookingID'],
					'has_lines'          => $hasLines,
					'current_invoice_id' => (int) $row['invoiceID'],
					'found'              => 1
				);
			}
			else
			{
				$calls[] = array(
					'call_id'            => $cid,
					'call_name'          => null,
					'start_date'         => 0,
					'booking_id'         => 0,
					'has_lines'          => $hasLines,
					'current_invoice_id' => 0,
					'found'              => 0
				);
			}
		}
	}

	mysqli_close($link);

	/* ---- response ---- */

	$body = array(
		'invoice' => array(
			'id'        => (int) $h['id'],
			'generated' => (int) $h['invoice_generated'],
			'status'    => $h['invoice_status'] === null ? null : (int) $h['invoice_status'],
			'sent'      => (int) $h['invoice_sent'],
			'paid'      => (int) $h['invoice_paid'],
			'exported'  => (int) $h['invoice_exported'],
			'call_list' => $callList
		),
		'booking' => array(
			'id'        => (int) $h['bookingID'],
			'name'      => $h['booking_name'],
			'reference' => $h['booking_reference']
		),
		'customer' => array(
			'id'       => $h['customerID'] === null ? 0 : (int) $h['customerID'],
			'name'     => $h['customer_name'],
			'email'    => $h['customer_email'],
			'phone'    => $h['customer_phone'],
			'address'  => $addr['address'],
			'suburb'   => $addr['suburb'],
			'state'    => $addr['state'],
			'postcode' => $addr['postcode'],
			/* true when there is no billing address and the main one is shown. */
			'billing_is_fallback' => $billingFallback ? true : false
		),
		'contact' => array(
			'user_id'    => $h['contact_user_id'] === null ? 0 : (int) $h['contact_user_id'],
			'first_name' => $h['contact_first'],
			'last_name'  => $h['contact_last']
		),
		'venue' => array(
			'id'   => $h['venueID'] === null ? 0 : (int) $h['venueID'],
			'name' => $h['venue_name']
		),
		'lines'               => $lines,
		'calls'               => $calls,
		'extra_line_call_ids' => $extraLineCallIDs,
		'hand_added_lines'    => $handAddedLines,
		'capabilities'        => array(
			'billing_address' => $haveBilling ? true : false
		)
	);

	$out = json_encode($body);

	if ($out === false)
	{
		error_log('get-invoice: json_encode failed for invoice ' . $invoiceID
		          . ': ' . json_last_error_msg());
		invone_fail(500, 'Internal Server Error', array('error' => 'Could not encode the invoice'));
	}

	echo $out;
