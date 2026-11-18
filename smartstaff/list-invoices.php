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
	/* EXISTING INVOICES -- ONE PAGE (BRIEF-accounting-slice-a.md §4.2).
	/*
	/*   ?q=<search>&p=<0-based page>
	/*     -> {page, per_page, total, invoices:[...], capabilities:{}}
	/*
	/* READ ONLY. Nothing in this file writes to any table, and nothing here
	/* talks to Supabase, Xero or Crew Hub. Generate, Edit, Delete, Email and
	/* Export all stay in SmartStaff (design D2); this endpoint only LISTS.
	/*
	/* WHO: admin cohort AND one of $GOAT_ACCOUNTING_USERS -- today 10261,
	/* Mike's admin login. The same set is ACCOUNTING_USERS in THE GOAT's
	/* app.py; BOTH MUST CHANGE TOGETHER. Checked here as well as in Flask
	/* because an admin logged straight into SmartStaff could otherwise open
	/* this URL in a browser (the get-performance-cost.php two-step gate).
	/*
	/* THREE READS, ONE RESPONSE:
	/*   1. the page itself -- invoices LEFT JOIN bookings LEFT JOIN customers
	/*   2. COUNT(*) on the same WHERE, so paging says how many there really are
	/*   3. the line sums for THE 50 IDS ON THIS PAGE ONLY, in one grouped scan
	/*      (invoice_lines is indexed on start_date only, so this is about 0.2 s
	/*      per page on prod, P2). NEVER one query per row.
	/*
	/* NEVER A PARTIAL BODY: every query's result is checked against false and
	/* the whole response is assembled in memory before anything is echoed. A
	/* failed query is a 500 NAMING the query label (never the SQL), because an
	/* invoice list quietly missing rows is worse than an error.
	/*
	/* MONEY IS RETURNED UNROUNDED, AS DECIMAL STRINGS. All rounding happens
	/* once, in Python, in Decimal (accounting_invoices.invoice_totals) --
	/* rounding here as well would round twice and put a total a cent out.
	/*
	/* WHY THE CASTS IN QUERY 3 (fix 1, invoice #34334 on test, 18 Nov 2026).
	/* `chargeout_rate`, `hours` and `flatrate` are FLOAT columns, which MySQL
	/* stores in SINGLE precision: the stored 89.95 is really 89.9499969...
	/* Multiplied and summed INSIDE SQL, those errors accumulate -- #34334's 22
	/* lines came to 32783.949155807 instead of 32783.95. The exact ex-GST sum
	/* is 32783.950, so its GST is 3278.395 EXACTLY, a half cent; the float
	/* noise was enough to round that down and the list showed 36,062.34 where
	/* the PDF, and THE GOAT's own invoice dialog, both showed 36,062.35.
	/*
	/* CAST(float AS DECIMAL(12,4)) rounds 89.9499969 back to 89.9500 -- which
	/* is the value mysqli's TEXT PROTOCOL returns when the column is read row
	/* by row, and therefore the value SmartStaff's own PDF prices from. So the
	/* cast does not invent a number: it makes the aggregate agree with the
	/* row-by-row read that get-invoice.php does, and with the PDF.
	/*
	/* get-invoice.php needs no such cast: it does NO arithmetic in SQL at all.
	/* It selects the raw columns and leaves every multiplication to
	/* accounting_invoices.py, which is why the dialog was already right.
	/*
	/* THE TWO FLAT SUMS ARE BOTH RETURNED ON PURPOSE (design §3.6, Q11):
	/*   flat_sum_pdf -- SUM(flatrate), what invoice.php's PDF charges
	/*   flat_sum_qty -- SUM(flatrate * qty), what the Xero export charges
	/*                   (class.accounting.php l. 1276)
	/* They differ only when a flat-rate line carries qty > 1. Slice A SHOWS
	/* the disagreement; it does not pick a winner.
	/*
	/* NAMES COME BACK AS STORED -- HTML-ENCODED (SmartStaff writes them that
	/* way). THE GOAT runs decodeEntities() then escapeHtml() on them; decoding
	/* here as well would decode twice.
	/*
	/* ACCESSOR: mysqli_* on its own link, as get-booking-figures.php.
	/* PHP 5.6 -- array(), no null-coalescing, no short array syntax. */

	$GOAT_ACCOUNTING_USERS = array(10261);   /* = ACCOUNTING_USERS in app.py; change both together */

	$GOAT_INVOICE_PAGE_SIZE = 50;

	/* ---- helpers ---- */

	function send_status($code, $msg)
	{
		$proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($proto . ' ' . $code . ' ' . $msg);
	}

	function invlist_fail($code, $msg, $body)
	{
		send_status($code, $msg);
		$out = json_encode($body);
		if ($out === false)
			$out = '{"ok":false,"error":"request failed"}';
		die($out);
	}

	/* Same credential resolution as figures_mysqli() in get-booking-figures.php. */
	function invlist_mysqli()
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
			error_log('list-invoices: could not resolve DB credentials');
			return null;
		}

		mysqli_report(MYSQLI_REPORT_OFF);
		$link = @mysqli_connect($host, $user, $pass, $name);

		if (!$link)
		{
			error_log('list-invoices: connect failed: ' . mysqli_connect_error());
			return null;
		}

		return $link;
	}

	function invlist_query($link, $label, $sql)
	{
		$res = mysqli_query($link, $sql);

		if ($res === false)
		{
			error_log('list-invoices: query "' . $label . '" failed: ' . mysqli_error($link));
			invlist_fail(500, 'Internal Server Error',
				array('error' => 'Invoice list query failed', 'query' => $label));
		}

		return $res;
	}

	/*
	/* A search term, ready to sit between two % in a LIKE literal.
	/*
	/* The LIKE wildcards go first: a customer called "100%" must not match
	/* every invoice in the database. Backslash before % and _, and the
	/* backslash itself doubled first, then mysqli_real_escape_string for the
	/* string literal. MySQL's default LIKE escape character is the backslash,
	/* so no ESCAPE clause is needed.
	*/

	function invlist_like($link, $s)
	{
		$s = str_replace('\\', '\\\\', $s);
		$s = str_replace('%',  '\\%',  $s);
		$s = str_replace('_',  '\\_',  $s);

		return mysqli_real_escape_string($link, $s);
	}

	/* ---- admin cohort, then the accounting users only ---- */

	if (goat_user_cohort() !== 'admin')
	{
		invlist_fail(403, 'Forbidden', array('error' => 'Admin only'));
	}

	$sessionUser = (int) $_SESSION[SITE_KEY]['userID'];

	if (!in_array($sessionUser, $GOAT_ACCOUNTING_USERS, true))
	{
		invlist_fail(403, 'Forbidden', array('error' => 'Not enabled for this account'));
	}

	/* ---- parameters ---- */

	$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

	/*
	/* 100 characters, cut with the multibyte-safe function when it is there:
	/* substr() on a UTF-8 name can cut a character in half, and the half
	/* character then breaks json_encode for the whole response.
	*/
	if ($q !== '')
	{
		if (function_exists('mb_substr'))
			$q = mb_substr($q, 0, 100, 'UTF-8');
		else
			$q = substr($q, 0, 100);
	}

	$page = isset($_GET['p']) ? (int) $_GET['p'] : 0;

	if ($page < 0) { $page = 0; }

	$perPage = $GOAT_INVOICE_PAGE_SIZE;

	$link = invlist_mysqli();

	if ($link === null)
	{
		invlist_fail(500, 'Internal Server Error', array('error' => 'Database unavailable'));
	}

	/*
	/* UTF-8 on the wire. The legacy connection sets no charset and the
	/* database default is latin1, which is right for endpoints that hand
	/* call_name back as the same bytes get-booking.php emits. This endpoint
	/* json_encode()s customer and booking names instead, and json_encode
	/* REFUSES invalid UTF-8 -- one latin1 apostrophe would turn the whole page
	/* of invoices into a 500. Set in the same shape as class.sss.php's connect
	/* (G27-34), with the result checked rather than assumed.
	*/
	if (function_exists('mysqli_set_charset'))
	{
		if (!@mysqli_set_charset($link, 'utf8mb4'))
			@mysqli_set_charset($link, 'utf8');
	}

	/* ---- the WHERE clause (mirrors accounting.php's own search) ---- */

	/*
	/* SmartStaff stores booking and customer names HTML-ENCODED, which is why
	/* accounting.php encodes the needle before searching. "Foo & Bar" is
	/* stored as "Foo &amp; Bar", so a raw search for "Foo & Bar" finds
	/* nothing. Both forms are tested and OR'd: the raw term still has to work,
	/* because a search for "&amp;" itself is a legitimate thing to type.
	*/

	$where     = '1';
	$exactID   = 0;
	$orderHead = '';

	if ($q !== '')
	{
		$needles = array($q);

		$enc = htmlspecialchars($q, ENT_QUOTES);

		if ($enc !== $q) { $needles[] = $enc; }

		$ors = array();

		foreach ($needles as $needle)
		{
			$like = invlist_like($link, $needle);

			$ors[] = "bookings.name LIKE '%" . $like . "%'";
			$ors[] = "customers.customer_name LIKE '%" . $like . "%'";
			$ors[] = "invoices.id LIKE '%" . $like . "%'";
		}

		/*
		/* An all-digit term is almost always an invoice number, so match it
		/* exactly as well and float that row to the top of the page -- a
		/* search for 348 must not bury #348 under #34782.
		*/
		if (ctype_digit($q))
		{
			$exactID = (int) $q;
			$ors[]   = 'invoices.id = ' . $exactID;

			$orderHead = '(invoices.id = ' . $exactID . ') DESC, ';
		}

		$where = '(' . implode(' OR ', $ors) . ')';
	}

	$from = '`invoices`
	           LEFT JOIN `bookings`  ON bookings.id  = invoices.bookingID
	           LEFT JOIN `customers` ON customers.id = bookings.customerID';

	/* ---- 1. the page ---- */

	$rows    = array();
	$pageIDs = array();

	$res = invlist_query($link, 'page',
		"SELECT invoices.id, invoices.bookingID, invoices.invoice_generated,
		        invoices.invoice_status, invoices.invoice_sent, invoices.invoice_paid,
		        invoices.invoice_exported, invoices.call_list,
		        bookings.name AS booking_name, bookings.reference AS booking_reference,
		        bookings.customerID, customers.customer_name
		   FROM " . $from . "
		  WHERE " . $where . "
		  ORDER BY " . $orderHead . "invoices.invoice_generated DESC, invoices.id DESC
		  LIMIT " . (int) ($page * $perPage) . ", " . (int) $perPage);

	while ($row = mysqli_fetch_assoc($res))
	{
		$id        = (int) $row['id'];
		$pageIDs[] = $id;

		/*
		/* call_list is a comma-separated text column and prod holds trailing
		/* commas and empty segments in it, so every segment is intval'd and
		/* the zeroes dropped. Counted here rather than in SQL because the
		/* column is text and FIND_IN_SET arithmetic on it would be a lie.
		*/
		$callCount = 0;

		if ($row['call_list'] !== null && $row['call_list'] !== '')
		{
			foreach (explode(',', (string) $row['call_list']) as $seg)
			{
				if ((int) trim($seg) > 0) { $callCount++; }
			}
		}

		$rows[] = array(
			'id'                => $id,
			'booking_id'        => (int) $row['bookingID'],
			'booking_name'      => $row['booking_name'],
			'booking_reference' => $row['booking_reference'],
			'customer_id'       => $row['customerID'] === null ? 0 : (int) $row['customerID'],
			'customer_name'     => $row['customer_name'],
			'generated'         => (int) $row['invoice_generated'],
			'status'            => $row['invoice_status'] === null ? null : (int) $row['invoice_status'],
			'sent'              => (int) $row['invoice_sent'],
			'paid'              => (int) $row['invoice_paid'],
			'exported'          => (int) $row['invoice_exported'],
			'call_count'        => $callCount,
			/* Filled by query 3. An invoice with NO LINES keeps these zeroes
			/* and is still returned: "no lines" is a finding, not a row to
			/* drop. Strings, so every invoice carries the same type whether
			/* query 3 found lines for it or not. */
			'hourly_sum'        => '0',
			'flat_sum_pdf'      => '0',
			'flat_sum_qty'      => '0',
			'line_count'        => 0
		);
	}

	mysqli_free_result($res);

	/* ---- 2. the count ---- */

	$total = 0;

	$res = invlist_query($link, 'count',
		"SELECT COUNT(*) AS n FROM " . $from . " WHERE " . $where);

	$row = mysqli_fetch_assoc($res);

	if ($row !== null) { $total = (int) $row['n']; }

	mysqli_free_result($res);

	/* ---- 3. the line sums, for this page's ids only ---- */

	/*
	/* One grouped scan for the whole page. flatrate > 0 is the flat-rate test
	/* class.accounting.php uses, so a flat line is never also counted as an
	/* hourly one. `description <> '<< BLANK_LINE >>'` counts the real lines:
	/* the spacer rows the generator writes after each call are layout, not
	/* money, and a count that included them would disagree with the PDF.
	/* IFNULL, because `NULL <> '...'` is NULL and SUM() skips it -- a line
	/* with no description at all is still a line.
	*/

	if (count($pageIDs) > 0)
	{
		$idList = array();

		foreach ($pageIDs as $id) { $idList[] = (int) $id; }

		$res = invlist_query($link, 'line_sums',
			"SELECT invoiceID,
			        SUM(IF(flatrate > 0, 0,
			               qty * CAST(chargeout_rate AS DECIMAL(12,4))
			                   * CAST(hours AS DECIMAL(10,4))))            AS hourly_sum,
			        SUM(IF(flatrate > 0, CAST(flatrate AS DECIMAL(12,4)), 0))
			                                                               AS flat_sum_pdf,
			        SUM(IF(flatrate > 0, CAST(flatrate AS DECIMAL(12,4)) * qty, 0))
			                                                               AS flat_sum_qty,
			        SUM(IFNULL(description, '') <> '<< BLANK_LINE >>')     AS line_count
			   FROM `invoice_lines`
			  WHERE invoiceID IN (" . implode(',', $idList) . ")
			  GROUP BY invoiceID");

		$sums = array();

		while ($row = mysqli_fetch_assoc($res))
		{
			/*
			/* NOT CAST TO FLOAT. mysqli returns a DECIMAL as a STRING, and
			/* that string is the exact value; (float) would put it back into a
			/* double and json_encode could then print 32783.950000000004.
			/* Python reads it with Decimal(str(v)) either way, so the honest
			/* thing is to hand over the digits MySQL computed.
			*/
			$sums[(int) $row['invoiceID']] = array(
				'hourly_sum'   => $row['hourly_sum']   === null ? '0' : (string) $row['hourly_sum'],
				'flat_sum_pdf' => $row['flat_sum_pdf'] === null ? '0' : (string) $row['flat_sum_pdf'],
				'flat_sum_qty' => $row['flat_sum_qty'] === null ? '0' : (string) $row['flat_sum_qty'],
				'line_count'   => $row['line_count']   === null ? 0   : (int)   $row['line_count']
			);
		}

		mysqli_free_result($res);

		foreach ($rows as $i => $r)
		{
			if (isset($sums[$r['id']]))
			{
				$rows[$i]['hourly_sum']   = $sums[$r['id']]['hourly_sum'];
				$rows[$i]['flat_sum_pdf'] = $sums[$r['id']]['flat_sum_pdf'];
				$rows[$i]['flat_sum_qty'] = $sums[$r['id']]['flat_sum_qty'];
				$rows[$i]['line_count']   = $sums[$r['id']]['line_count'];
			}
		}
	}

	mysqli_close($link);

	/* ---- response ---- */

	/*
	/* Assembled only now, after every query has come back. json_encode is
	/* checked: a float that will not encode (NAN/INF from a corrupt row), or a
	/* name that is not valid UTF-8, must be a 500 rather than the literal
	/* "false" written into the response body.
	*/

	$body = array(
		'page'     => $page,
		'per_page' => $perPage,
		'total'    => $total,
		'q'        => $q,
		'invoices' => $rows
	);

	$out = json_encode($body);

	if ($out === false)
	{
		error_log('list-invoices: json_encode failed (page ' . $page . '): ' . json_last_error_msg());
		invlist_fail(500, 'Internal Server Error', array('error' => 'Could not encode the invoice list'));
	}

	echo $out;
