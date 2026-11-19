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
	/* GENERATE ONE INVOICE (BRIEF-accounting-slice-b.md §3.2).
	/*
	/*   POST {booking_id, fingerprint}
	/*     -> 200 {ok:true, invoice_id, booking_id, call_ids, line_count,
	/*             hourly_sum, flat_sum_pdf, flat_sum_qty}
	/*     -> 409 {error, reason}   busy | stuck_calls | nothing_to_invoice | changed
	/*     -> 404 {error, reason}   not_found
	/*     -> 500 {ok:false, failed:[...], ...}
	/*
	/* THIS IS THE FIRST GOAT ENDPOINT THAT WRITES TO THE ACCOUNTING TABLES.
	/* It writes exactly what SmartStaff's own Generate writes and nothing
	/* else, because it CALLS SmartStaff's own code to do it (design D2):
	/*
	/*   accounting::getInstance()->generateAccountingData($bookingID, 0)
	/*
	/* which inserts one `invoices` row, its `invoice_lines`, and stamps
	/* `calls.invoiceID` and `calls.invoice_generated`. The maths is therefore
	/* the maths SmartStaff has always used; this file adds only the guards it
	/* has never had.
	/*
	/* WHO: admin cohort AND one of $GOAT_ACCOUNTING_USERS -- today 10261,
	/* Mike's admin login. The same set is ACCOUNTING_USERS in THE GOAT's
	/* app.py; ALL FIVE PLACES MUST CHANGE TOGETHER (app.py, list-invoices.php,
	/* get-invoice.php, list-ready-to-invoice.php, this file).
	/*
	/* ---- WHY THE GUARDS, AND WHAT THEY CANNOT DO ----
	/*
	/* generateAccountingData has no transaction, takes no lock, and returns
	/* nothing. Press it twice on the same booking and the second press makes a
	/* SECOND invoice row with an empty call list -- it is an UPDATE ... WHERE
	/* id IN () away from a SQL error, which the legacy db class swallows.
	/*
	/* So, in order:
	/*   1. a named lock, so two GOAT requests cannot run together
	/*   2. the booking's state RE-READ at this moment, through the same
	/*      accounting-ready-lib.php the list used, which is what makes
	/*      `nothing_to_invoice` and `changed` possible at all
	/*   3. the write
	/*   4. the result READ BACK AND CHECKED -- success is never assumed,
	/*      because the class cannot report it
	/*
	/* THE NAMED LOCK ONLY STOPS TWO *GOAT* REQUESTS. A click on SmartStaff's
	/* own accounting.php knows nothing about it, so the window in which
	/* SmartStaff can double-generate is narrowed, not closed. Step 2's re-read
	/* is what actually catches a SmartStaff Generate that already happened.
	/*
	/* THE LOCK NAME CARRIES THE DATABASE. Test and prod share one MariaDB
	/* server and named locks are server-wide (the goat_capacity_lock_name()
	/* note in callout.php l. 31), so an unqualified name would let a test
	/* Generate make a real one wait, or vice versa.
	/*
	/* NOTHING IS ROLLED BACK. SmartStaff's own code did the writes, on the
	/* LEGACY db connection, not the mysqli link this file holds -- so even
	/* where the engine would allow it there is no transaction to roll back.
	/* When a check fails this file therefore SAYS WHAT EXISTS AND WHAT IS
	/* WRONG and never claims success. "Don't generate again, open the invoice
	/* and check it" is the only safe advice and the UI gives exactly that.
	/*
	/* EVERY EXIT RELEASES THE LOCK. There is no bare die() or exit in this
	/* file: gen_fail() and gen_ok() are the only ways out and both release
	/* first, and a shutdown function releases again as belt-and-braces for a
	/* fatal error inside SmartStaff's own code. (test_accounting_generate_php.py
	/* asserts both.)
	/*
	/* PHP 5.6 -- array(), no null-coalescing, no short array syntax. */

	$GOAT_ACCOUNTING_USERS = array(10261);   /* = ACCOUNTING_USERS in app.py; change both together */

	$GOAT_GEN_LOCK_WAIT = 10;

	/* ---- the lock, and the only two ways out ---- */

	$GOAT_GEN_LINK      = null;
	$GOAT_GEN_LOCK_NAME = null;

	function send_status($code, $msg)
	{
		$proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($proto . ' ' . $code . ' ' . $msg);
	}

	/*
	/* Release the named lock if this request holds one. Safe to call twice:
	/* RELEASE_LOCK on a lock we do not hold returns 0 and changes nothing.
	*/
	function gen_release_lock()
	{
		global $GOAT_GEN_LINK, $GOAT_GEN_LOCK_NAME;

		if ($GOAT_GEN_LINK === null || $GOAT_GEN_LOCK_NAME === null)
			return;

		@mysqli_query($GOAT_GEN_LINK,
			"SELECT RELEASE_LOCK('" . mysqli_real_escape_string($GOAT_GEN_LINK, $GOAT_GEN_LOCK_NAME) . "')");

		$GOAT_GEN_LOCK_NAME = null;
	}

	register_shutdown_function('gen_release_lock');

	function gen_fail($code, $msg, $body)
	{
		gen_release_lock();

		send_status($code, $msg);

		$out = json_encode($body);

		if ($out === false)
			$out = '{"ok":false,"error":"request failed"}';

		die($out);
	}

	function gen_ok($body)
	{
		gen_release_lock();

		$out = json_encode($body);

		if ($out === false)
		{
			send_status(500, 'Internal Server Error');
			die('{"ok":false,"error":"response could not be encoded"}');
		}

		die($out);
	}

	/* ---- POST only ---- */

	$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

	if ($method !== 'POST')
	{
		header('Allow: POST');
		gen_fail(405, 'Method Not Allowed',
			array('error' => 'Generate is POST only', 'reason' => 'method'));
	}

	/* ---- admin cohort, then the accounting users only ---- */

	if (goat_user_cohort() !== 'admin')
	{
		gen_fail(403, 'Forbidden', array('error' => 'Admin only'));
	}

	$sessionUser = (int) $_SESSION[SITE_KEY]['userID'];

	if (!in_array($sessionUser, $GOAT_ACCOUNTING_USERS, true))
	{
		gen_fail(403, 'Forbidden', array('error' => 'Not enabled for this account'));
	}

	/* ---- the body ---- */

	/*
	/* THE GOAT posts JSON; a form post is accepted too so the endpoint can be
	/* exercised with curl on test without hand-building a JSON body.
	*/
	$in = array();

	$raw = file_get_contents('php://input');

	if ($raw !== false && trim($raw) !== '')
	{
		$decoded = json_decode($raw, true);

		if (is_array($decoded))
			$in = $decoded;
	}

	if (count($in) < 1)
		$in = $_POST;

	$bookingID   = isset($in['booking_id'])  ? (int) $in['booking_id'] : 0;
	$fingerprint = isset($in['fingerprint']) ? strtolower(trim((string) $in['fingerprint'])) : '';

	if ($bookingID < 1)
	{
		gen_fail(400, 'Bad Request',
			array('error' => 'booking_id is required', 'reason' => 'bad_booking_id'));
	}

	if (!preg_match('/^[0-9a-f]{40}$/', $fingerprint))
	{
		gen_fail(400, 'Bad Request',
			array('error' => 'fingerprint is required', 'reason' => 'bad_fingerprint'));
	}

	/* ---- the link ---- */

	$link = goat_ready_connect('generate-invoice');

	if ($link === null)
	{
		gen_fail(500, 'Internal Server Error',
			array('error' => 'Database unavailable', 'reason' => 'db'));
	}

	$GOAT_GEN_LINK = $link;

	/* ---- 1. serialise ---- */

	/*
	/* The database name, so test and prod cannot block each other. Cut to 30
	/* characters: MariaDB's lock names are limited to 64 and the prefix and
	/* booking id take some of that.
	*/
	$dbName = '';

	$res = mysqli_query($link, 'SELECT DATABASE() AS db');

	if ($res !== false)
	{
		$row    = mysqli_fetch_assoc($res);
		$dbName = ($row === null || $row['db'] === null) ? '' : (string) $row['db'];
		mysqli_free_result($res);
	}

	$dbName = substr(preg_replace('/[^a-z0-9_]/i', '', $dbName), 0, 30);

	$lockName = 'goat_gen_inv_' . $dbName . '_' . $bookingID;

	$res = mysqli_query($link,
		"SELECT GET_LOCK('" . mysqli_real_escape_string($link, $lockName) . "', "
		. (int) $GOAT_GEN_LOCK_WAIT . ") AS got");

	$got = null;

	if ($res !== false)
	{
		$row = mysqli_fetch_assoc($res);
		$got = ($row === null) ? null : $row['got'];
		mysqli_free_result($res);
	}

	/*
	/* Only an exact 1 is the lock. 0 is a timeout and NULL is an error, and
	/* both mean someone else may be mid-write -- so neither may proceed.
	*/
	if ($got === null || (int) $got !== 1)
	{
		gen_fail(409, 'Conflict', array(
			'error'  => 'Someone is generating this booking right now. Try again in a moment.',
			'reason' => 'busy'));
	}

	$GOAT_GEN_LOCK_NAME = $lockName;

	/* ---- 2. re-read the booking's state, now ---- */

	$state = goat_ready_booking_state($link, 'generate-invoice', $bookingID);

	if ($state === null)
	{
		gen_fail(500, 'Internal Server Error',
			array('error' => 'Could not read the booking', 'reason' => 'db'));
	}

	if ($state !== 1)
	{
		gen_fail(404, 'Not Found', array(
			'error'  => ($state === 0)
				? 'That booking is hidden'
				: 'That booking does not exist',
			'reason' => 'not_found'));
	}

	/*
	/* The window is deliberately NOT applied here (goat_ready_scan ignores it
	/* when booking_id is set): Generate must see the booking's true state
	/* however old its calls are, or an ?all=1 listing could offer a Generate
	/* that the re-read then claimed had nothing to invoice.
	*/
	$scan = goat_ready_scan($link, 'generate-invoice', array('booking_id' => $bookingID));

	if (!$scan['ok'])
	{
		gen_fail(500, 'Internal Server Error',
			array('error' => 'Ready-to-invoice query failed', 'reason' => 'db',
			      'query' => $scan['query']));
	}

	$booking = isset($scan['bookings'][$bookingID]) ? $scan['bookings'][$bookingID] : null;

	$stuck = ($booking === null) ? array() : $booking['stuck_calls'];

	if (count($stuck) > 0)
	{
		$names = array();

		foreach ($stuck as $s)
			$names[] = '#' . $s['call_id'] . ' (invoice #' . $s['invoice_id'] . ')';

		gen_fail(409, 'Conflict', array(
			'error'  => 'This booking cannot be generated: ' . join(', ', $names)
			          . ' is on an invoice but was never marked invoiced. It needs fixing first.',
			'reason' => 'stuck_calls',
			'stuck_calls' => $stuck));
	}

	$ready = ($booking === null) ? array() : $booking['ready_calls'];

	if (count($ready) < 1)
	{
		$latest = goat_ready_latest_invoice($link, 'generate-invoice', $bookingID);

		gen_fail(409, 'Conflict', array(
			'error'  => $latest > 0
				? 'Nothing to invoice: the calls were invoiced since you looked (invoice #' . $latest . ')'
				: 'Nothing to invoice on this booking',
			'reason'          => 'nothing_to_invoice',
			'latest_invoice'  => $latest > 0 ? $latest : null));
	}

	$readyIDs = array();

	foreach ($ready as $rc)
		$readyIDs[] = (int) $rc['call_id'];

	sort($readyIDs, SORT_NUMERIC);

	$now = goat_ready_fingerprint($readyIDs);

	if ($now !== $fingerprint)
	{
		gen_fail(409, 'Conflict', array(
			'error'  => 'The calls on this booking changed since you looked. Refresh and check before generating.',
			'reason' => 'changed',
			'booking' => $booking));
	}

	/* ---- 3. what the highest invoice id is BEFORE the write ---- */

	$res = goat_ready_query($link, 'generate-invoice', 'max invoice id',
		'SELECT COALESCE(MAX(id), 0) AS m FROM `invoices`');

	if ($res === false)
	{
		gen_fail(500, 'Internal Server Error',
			array('error' => 'Could not read the invoice table', 'reason' => 'db'));
	}

	$row       = mysqli_fetch_assoc($res);
	$maxBefore = ($row === null) ? 0 : (int) $row['m'];
	mysqli_free_result($res);

	/* ---- 4. SmartStaff's own Generate ---- */

	/*
	/* STAGE 6.2, NOT YET: when a "priced by THE GOAT" switch exists, a booking
	/* carrying it must be REFUSED here, exactly as SmartStaff's own Generate
	/* must be, or the same work would be priced twice by two different engines.
	/* There is nothing to check today (design §8).
	*/

	/*
	/* NO ajax/crew ENDPOINT HAS EVER USED A LEGACY CLASS. accounting.php, a
	/* full Smarty page, is the only caller of this class today, so whether
	/* global.php's class loading reaches it under SS_NO_SMARTY is checked HERE
	/* rather than assumed. If it does not, this file writes NOTHING and says
	/* so plainly -- the one thing it must not do is reimplement the maths
	/* (design D2).
	*/
	if (!class_exists('accounting')
	 || !method_exists('accounting', 'getInstance')
	 || !method_exists('accounting', 'generateAccountingData'))
	{
		error_log('generate-invoice: accounting class unavailable under SS_NO_SMARTY'
		        . ' (booking ' . $bookingID . ', user ' . $sessionUser . ')');

		gen_fail(500, 'Internal Server Error', array(
			'ok'     => false,
			'error'  => 'SmartStaff\'s accounting class is not loaded on this page, so nothing was generated.',
			'reason' => 'class_unavailable'));
	}

	$acct = accounting::getInstance();

	$acct->generateAccountingData($bookingID, 0);

	/* ---- 5. read back, and decide ---- */

	$failed = array();

	/*
	/* THE NEW INVOICE. Exactly one row above the id we noted, on this booking.
	/* None means the class wrote nothing; more than one means something else
	/* generated at the same moment -- a click on SmartStaff's own page, which
	/* the named lock cannot stop -- and in that case the two invoices have to
	/* be sorted out by hand, so neither is reported as this request's.
	*/
	$invoiceID = 0;
	$callList  = '';
	$found     = 0;

	$res = goat_ready_query($link, 'generate-invoice', 'new invoice',
		"SELECT id, call_list FROM `invoices`
		  WHERE bookingID = " . $bookingID . " AND id > " . $maxBefore . "
		  ORDER BY id");

	if ($res === false)
	{
		$failed[] = 'could not read the new invoice back';
	}
	else
	{
		while ($row = mysqli_fetch_assoc($res))
		{
			$found++;

			if ($found === 1)
			{
				$invoiceID = (int) $row['id'];
				$callList  = ($row['call_list'] === null) ? '' : (string) $row['call_list'];
			}
		}

		mysqli_free_result($res);

		if ($found === 0)
			$failed[] = 'no new invoice row was created';
		elseif ($found > 1)
			$failed[] = $found . ' new invoice rows were created, not one';
	}

	/* THE CALL LIST must contain every ready call. */
	if ($invoiceID > 0)
	{
		$inList = array();

		foreach (explode(',', $callList) as $piece)
		{
			$piece = trim($piece);

			if ($piece !== '')
				$inList[] = (int) $piece;
		}

		$missing = array();

		foreach ($readyIDs as $cid)
		{
			if (!in_array($cid, $inList, true))
				$missing[] = $cid;
		}

		if (count($missing) > 0)
			$failed[] = 'the invoice call list is missing call(s) ' . join(', ', $missing);

		/* EVERY READY CALL must now point at the new invoice and be stamped. */
		$res = goat_ready_query($link, 'generate-invoice', 'call stamping',
			"SELECT COUNT(*) AS n
			   FROM `calls`
			  WHERE id IN (" . goat_ready_ids($readyIDs) . ")
			    AND invoiceID = " . $invoiceID . "
			    AND invoice_generated = 1");

		if ($res === false)
		{
			$failed[] = 'could not read the calls back';
		}
		else
		{
			$row     = mysqli_fetch_assoc($res);
			$stamped = ($row === null) ? 0 : (int) $row['n'];
			mysqli_free_result($res);

			if ($stamped !== count($readyIDs))
			{
				$failed[] = 'only ' . $stamped . ' of ' . count($readyIDs)
				          . ' calls were stamped onto the invoice';
			}
		}
	}

	/* THE LINES. Same three sums, and the same casts, as list-invoices.php. */
	$hourly    = '0';
	$flatPdf   = '0';
	$flatQty   = '0';
	$lineCount = 0;

	if ($invoiceID > 0)
	{
		$res = goat_ready_query($link, 'generate-invoice', 'invoice lines',
			"SELECT SUM(IF(flatrate > 0, 0,
			               qty * CAST(chargeout_rate AS DECIMAL(12,4))
			                   * CAST(hours AS DECIMAL(10,4))))          AS hourly_sum,
			        SUM(IF(flatrate > 0, CAST(flatrate AS DECIMAL(12,4)), 0))
			                                                             AS flat_sum_pdf,
			        SUM(IF(flatrate > 0, CAST(flatrate AS DECIMAL(12,4)) * qty, 0))
			                                                             AS flat_sum_qty,
			        SUM(IFNULL(description, '') <> '<< BLANK_LINE >>')   AS line_count
			   FROM `invoice_lines`
			  WHERE invoiceID = " . $invoiceID);

		if ($res === false)
		{
			$failed[] = 'could not read the invoice lines back';
		}
		else
		{
			$row = mysqli_fetch_assoc($res);
			mysqli_free_result($res);

			if ($row !== null)
			{
				$hourly    = $row['hourly_sum']   === null ? '0' : (string) $row['hourly_sum'];
				$flatPdf   = $row['flat_sum_pdf'] === null ? '0' : (string) $row['flat_sum_pdf'];
				$flatQty   = $row['flat_sum_qty'] === null ? '0' : (string) $row['flat_sum_qty'];
				$lineCount = $row['line_count']   === null ? 0    : (int) $row['line_count'];
			}

			if ($lineCount < 1)
				$failed[] = 'the invoice has no priced lines';
		}
	}

	/*
	/* THE MONEY. What the lines came to, against what step 2 predicted.
	/*
	/* Compared in whole cents. The two sides are decimal strings of about
	/* seven significant digits, so one (float) conversion and one round() per
	/* side is exact at this magnitude -- fix 1's lesson was about thousands of
	/* single-precision multiplications ACCUMULATING inside SQL, which is why
	/* both sides were summed with DECIMAL casts before they got here.
	/*
	/* SKIPPED when the booking's prediction is not exact (expected_exact
	/* false): mixed crew groups and NULL `calculated` rows make the class's
	/* own figure unpredictable in SQL, and failing a Generate that did exactly
	/* what SmartStaff always does would be a false alarm on an invoice that
	/* cannot be rolled back. The response says the check was skipped and why.
	*/
	$expectedExact = ($booking !== null && $booking['expected_exact']);
	$moneyChecked  = false;

	$expSum = 0.0;

	foreach ($ready as $rc)
		$expSum += (float) $rc['expected_charge'];

	$expected = number_format($expSum, 4, '.', '');

	if ($invoiceID > 0 && $lineCount > 0 && $expectedExact)
	{
		$moneyChecked = true;

		$gotCents = (int) round(((float) $hourly + (float) $flatPdf) * 100);
		$expCents = (int) round($expSum * 100);

		if ($gotCents !== $expCents)
		{
			$failed[] = 'the invoice came to ' . number_format($gotCents / 100, 2, '.', '')
			          . ' but ' . number_format($expCents / 100, 2, '.', '') . ' was expected';
		}
	}

	/* ---- 6. one log line, then the answer ---- */

	error_log('generate-invoice: booking ' . $bookingID
	        . ' invoice ' . ($invoiceID > 0 ? $invoiceID : 'none')
	        . ' user ' . $sessionUser
	        . ' ' . (count($failed) > 0 ? 'FAILED: ' . join('; ', $failed) : 'ok'));

	$body = array(
		'ok'              => (count($failed) < 1),
		'invoice_id'      => $invoiceID > 0 ? $invoiceID : null,
		'booking_id'      => $bookingID,
		'call_ids'        => $readyIDs,
		'line_count'      => $lineCount,
		'hourly_sum'      => $hourly,
		'flat_sum_pdf'    => $flatPdf,
		'flat_sum_qty'    => $flatQty,
		'expected_charge' => $expected,
		'expected_exact'  => $expectedExact,
		'money_checked'   => $moneyChecked
	);

	if (count($failed) > 0)
	{
		$body['failed'] = $failed;
		$body['error']  = 'SmartStaff generated the invoice but these checks failed.';

		/*
		/* Through gen_fail like every other exit, so the lock is released on
		/* this path too -- and so that the "no bare die()" invariant at the
		/* top of this file stays true and testable.
		*/
		gen_fail(500, 'Internal Server Error', $body);
	}

	gen_ok($body);
