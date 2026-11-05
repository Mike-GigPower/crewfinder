<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include_once('supervision-graph.php');   /* slice G: unlinked_calls */

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* ADMIN ONLY — the All Bookings tab sits with the other admin tabs (Crew Finder
	/* / Create Booking / Administration). Widen to goat_can_read_all() if leadership
	/* should see it too.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		http_response_code(403);
		die('{"error":"Admin only"}');
	}

	/*
	/* Chronological list of ALL bookings (past + future), most recent first, for the
	/* All Bookings tab. Booking-level rows only — the front-end fetches a booking's
	/* calls on expand via the existing get-booking.php, so this stays a single light
	/* query. Column names mirror get-booking.php's joins exactly
	/* (customers.customer_name, venues.venue). creation_date is the booking/event
	/* date (unix), so ordering by it DESC puts recently-finished shows at the top --
	/* which is where you go to enter times.
	/*
	/* Params: ?limit=50 (max 100) &offset=0 &q=<booking-name search>
	/* Returns: { ok, total, limit, offset, q, bookings:[{booking_id, name,
	/*            creation_date, date_str, status, status_id, customer, venue,
	/*            call_count}] }
	/*
	/* PHP 5.x -- no ??, no short arrays. Raw mysql_* for consistency with
	/* get-booking.php; http_response_code as that endpoint uses it.
	*/

	$limit  = isset($_GET['limit'])  ? (int) $_GET['limit']  : 50;
	$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
	$q      = isset($_GET['q'])      ? trim($_GET['q'])      : '';

	if ($limit < 1)   $limit = 50;
	if ($limit > 100) $limit = 100;
	if ($offset < 0)  $offset = 0;

	/* optional booking-name search */

	$where = '';
	if ($q !== '')
	{
		$where = " WHERE b.name LIKE '%" . mysql_real_escape_string($q) . "%'";
	}

	/* total matching count, so the front-end knows whether to offer "show more" */

	$total = 0;
	$tres  = mysql_query("SELECT COUNT(*) AS n FROM bookings b" . $where);
	if ($tres !== false)
	{
		$trow = mysql_fetch_object($tres);
		if ($trow)
			$total = (int) $trow->n;
	}

	/* page of bookings, most recent first */

	$sql = "SELECT b.id, b.name, b.creation_date, b.status,
	               c.customer_name, v.venue,
	               (SELECT COUNT(*) FROM calls WHERE calls.bookingID = b.id) AS call_count
	        FROM bookings b
	        LEFT JOIN customers c ON b.customerID = c.id
	        LEFT JOIN venues v ON b.venueID = v.id"
	        . $where .
	       " ORDER BY b.creation_date DESC, b.id DESC
	        LIMIT " . (int) $limit . " OFFSET " . (int) $offset;

	$res = mysql_query($sql);
	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"bookings query failed: ' . addslashes(mysql_error()) . '"}');
	}

	$bStatusMap = array(0 => 'Active', 1 => 'Closed');

	$bookings = array();
	while ($b = mysql_fetch_object($res))
	{
		$sid = (int) $b->status;
		$bookings[] = array(
			'booking_id'    => (int) $b->id,
			'name'          => $b->name,
			'creation_date' => (int) $b->creation_date,
			'date_str'      => ($b->creation_date ? date('M j, Y', (int) $b->creation_date) : ''),
			'status'        => (isset($bStatusMap[$sid]) ? $bStatusMap[$sid] : ('Status ' . $sid)),
			'status_id'     => $sid,
			'customer'      => $b->customer_name,
			'venue'         => $b->venue,
			'call_count'    => (int) $b->call_count
		);
	}

	/*
	/* unlinked_calls — slice G of BRIEF-crew-self-times (§14.5, D24 gate G2):
	/* how many of the booking's calls overlap a live Crew Boss call on it
	/* without being linked, from goat_unlinked_boss_overlap() — the same rule
	/* the booking dialog's chips use. One pass over the whole page. A DISPLAY:
	/* a failed lookup emits null, which THE GOAT reads as "unknown" and shows
	/* nothing for, never 0, which would read as "all linked".
	*/

	$pageIDs = array();

	foreach ($bookings as $bk)
	{
		$pageIDs[] = $bk['booking_id'];
	}

	$ul = goat_unlinked_boss_overlap($pageIDs);

	foreach ($bookings as $i => $bk)
	{
		$bookings[$i]['unlinked_calls'] = $ul['ok']
			? (isset($ul['count_by_booking'][$bk['booking_id']]) ? (int) $ul['count_by_booking'][$bk['booking_id']] : 0)
			: null;
	}

	echo json_encode(array(
		'ok'       => true,
		'total'    => $total,
		'limit'    => $limit,
		'offset'   => $offset,
		'q'        => $q,
		'bookings' => $bookings
	));

?>
