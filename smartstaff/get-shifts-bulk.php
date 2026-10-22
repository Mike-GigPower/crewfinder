<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* admin OR leadership — this endpoint returns assignments for every crew
	/* member, not just the requester. Leadership is read-only; this is a read
	/* endpoint, so it is permitted.
	*/

	if (!goat_can_read_all())
	{
		http_response_code(403);
		die('{"error":"Admin or Leadership only"}');
	}

	/*
	/* validate input
	/*
	/* start, end:  YYYY-MM-DD (inclusive start, exclusive end)
	/* The window is bounded so accidental wide-open queries don't return
	/* months of data.
	*/

	$start_raw = isset($_GET['start']) ? $_GET['start'] : '';
	$end_raw   = isset($_GET['end'])   ? $_GET['end']   : '';

	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_raw) ||
	    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_raw))
	{
		http_response_code(400);
		die('{"error":"start and end must be YYYY-MM-DD"}');
	}

	$start_ts = strtotime($start_raw . ' 00:00:00');
	$end_ts   = strtotime($end_raw   . ' 00:00:00');

	if ($start_ts === false || $end_ts === false || $end_ts <= $start_ts)
	{
		http_response_code(400);
		die('{"error":"invalid date range"}');
	}

	/* cap the window at 120 days to protect the DB */

	if (($end_ts - $start_ts) > (120 * 86400))
	{
		http_response_code(400);
		die('{"error":"window exceeds 120 days"}');
	}

	$start_sql = date('Y-m-d 00:00:00', $start_ts);
	$end_sql   = date('Y-m-d 00:00:00', $end_ts);

	/*
	/* PDO via goat_pdo() (5.83.0). This endpoint used the legacy functions; it moved to the
	/* house PDO handle when it gained promo_pending. The OUTPUT IS UNCHANGED
	/* apart from that one added key: same keys, same order, same (int) casts.
	/* Session and cohort still come from the legacy connection global.php
	/* opens -- this is a second, read-only connection. Every Crew Finder search
	/* and the schedule lane read this, so a change of shape breaks them all.
	*/

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		http_response_code(503);
		die('{"error":"Database handle unavailable"}');
	}

	/*
	/* process the request
	/*
	/* Single query: every calendars row that overlaps the window, joined to
	/* calls + bookings + users + venues so the caller gets crew name +
	/* booking name + call_name + venue without further round trips.
	/*
	/* type = 1  → unavailability      (call FK is NULL)
	/* type = 2  → confirmed shift     (call FK populated)
	/*
	/* Schema notes (verified against live DB):
	/*   users    : name is firstname + lastname (no single name column)
	/*   bookings : name, venueID
	/*   calls    : bookingID, call_name
	/*   venues   : venue (not "name")
	/*   calendars: user, call, type, start, end
	/*
	/* Rows with type=2 but no matching call row (orphans from deleted calls)
	/* are filtered out by the inner conditional on the SELECT.
	/*
	/* call_promo_ack (5.83.0): a row with acked_at NULL on a status-5
	/* assignment is a promotion off backup the crew member has not answered.
	/* Reported as promo_pending so THE GOAT can show them as PENDING. status is
	/* left at 5 -- they hold the place, and the client's conflict rules still
	/* treat them as confirmed. Same test as get-booking.php/get-crew-shifts.php.
	*/

	$sql = "
		SELECT
			cal.id          AS event_id,
			cal.user        AS user_id,
			u.firstname     AS firstname,
			u.lastname      AS lastname,
			cal.title       AS title,
			cal.start       AS start_dt,
			cal.end         AS end_dt,
			cal.type        AS event_type,
			cal.call        AS call_id,
			c.bookingID     AS booking_id,
			c.call_name     AS call_name,
			b.name          AS booking_name,
			v.venue         AS venue_name,
			ccm.status      AS ccm_status,
			cpa.id          AS promo_id,
			cpa.acked_at    AS promo_acked_at
		FROM calendars cal
		LEFT JOIN users         u   ON u.id      = cal.user
		LEFT JOIN calls         c   ON c.id      = cal.call
		LEFT JOIN bookings      b   ON b.id      = c.bookingID
		LEFT JOIN venues        v   ON v.id      = b.venueID
		LEFT JOIN call_crew_map ccm ON ccm.callID = cal.call AND ccm.userID = cal.user
		LEFT JOIN call_promo_ack cpa ON cpa.callID = ccm.callID AND cpa.userID = ccm.userID
		WHERE cal.start < :end_dt
		  AND cal.end   > :start_dt
		  AND cal.type IN (1, 2)
		  AND (cal.type = 1 OR c.id IS NOT NULL)
		ORDER BY cal.user ASC, cal.start ASC
	";

	$shifts        = array();
	$unavails      = array();

	try
	{
		$stmt = $pdo->prepare($sql);
		$stmt->execute(array(':start_dt' => $start_sql, ':end_dt' => $end_sql));
		$rows = $stmt->fetchAll(PDO::FETCH_OBJ);
	}
	catch (PDOException $e)
	{
		http_response_code(500);
		die('{"error":"query failed: ' . addslashes($e->getMessage()) . '"}');
	}

	foreach ($rows as $row)
	{
		/* "Lastname, Firstname" — matches the scraper + list-crew-bulk.php */
		$display_name = trim($row->lastname);
		if (strlen(trim($row->firstname)) > 0)
			$display_name .= ', ' . trim($row->firstname);

		$entry = array(
			'event_id' => (int) $row->event_id,
			'user_id'  => (int) $row->user_id,
			'user'     => $display_name,
			'title'    => $row->title,
			'start'    => date('Y-m-d\TH:i:s', strtotime($row->start_dt)),
			'end'      => date('Y-m-d\TH:i:s', strtotime($row->end_dt)),
		);

		if ($row->event_type == 2)
		{
			$entry['call_id']      = (int) $row->call_id;
			$entry['booking_id']   = (int) $row->booking_id;
			$entry['call_name']    = $row->call_name;
			$entry['booking_name'] = $row->booking_name;
			$entry['venue']        = $row->venue_name;
			/* Real confirmation state from call_crew_map:
			/*   5=confirmed, 1=pending, 6=declined, 8=noshow, 0/none=unset.
			/* The client treats status==5 as a confirmed conflict (red bar, counts
			/* toward utilization) and everything else as an informational (blue)
			/* bar. This MUST be the true per-assignment status — a calendars
			/* type=2 row also exists for non-confirmed assignments, so hard-coding
			/* 5 here painted every "for information" shift as a red conflict. */
			$entry['status'] = ($row->ccm_status === null || $row->ccm_status === '')
			                   ? 0 : (int) $row->ccm_status;
			$entry['promo_pending'] = ($entry['status'] === 5
			                           && $row->promo_id !== null
			                           && $row->promo_acked_at === null) ? 1 : 0;
			$shifts[] = $entry;
		}
		else
		{
			/* type = 1 unavailability */
			$entry['reason'] = $row->title;
			$unavails[] = $entry;
		}
	}

	echo json_encode(array(
		'window'   => array('start' => $start_raw, 'end' => $end_raw),
		'shifts'   => $shifts,
		'unavails' => $unavails,
	));

?>