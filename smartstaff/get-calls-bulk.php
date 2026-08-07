<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');
	/* For goat_feeds_have_mode() only — this endpoint deliberately keeps its
	/* own set-based copy of the reduction rather than calling the per-row
	/* helpers (see the correlated-subquery timeout note below). Including
	/* call-graph.php just defines functions; it runs no queries. */
	include('call-graph.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* admin OR leadership — returns the all-crew call list (the Schedule view).
	/* Leadership is read-only; this is a read endpoint, so it is permitted.
	/* This exists so the Schedule no longer depends on scraping the admin
	/* /bookings pages (which a leadership EIN session cannot read).
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

	$start_i = (int) $start_ts;
	$end_i   = (int) $end_ts;

	/*
	/* process the request
	/*
	/* One query: every call whose date falls in the window, joined to its
	/* booking, the booking's venue, and the booking's onsite contact.
	/*
	/* Schema notes (verified against live DB):
	/*   calls    : id, bookingID, call_name, start_time (TIME), est_length
	/*              (DOUBLE hours), start_date (INT — unix ts at local midnight),
	/*              required, booked, notes
	/*   bookings : id, name, venueID, onsiteUserID, hidden
	/*   venues   : id, venue
	/*   users    : id, firstname, lastname  (onsite contact)
	/*
	/* ASSUMPTION: calls.start_date is a unix timestamp. If the window filter
	/* returns nothing / wrong dates, this is the line to revisit.
	*/

	$sql = "
		SELECT
			c.id          AS call_id,
			c.bookingID   AS booking_id,
			c.call_name   AS call_name,
			c.start_date  AS start_date,
			c.start_time  AS start_time,
			c.est_length  AS est_length,
			c.required    AS required,
			c.link_group  AS link_group,
			/* calls.booked is not maintained live (came back 0 for every call);
			/* the live confirmed count is call_crew_map status=5. Computed with a
			/* GROUP BY join — a single pass over the windowed calls' crew rows. A
			/* correlated per-row subquery here timed the endpoint out (read>20s). */
			COUNT(CASE WHEN ccm.status = 5 THEN 1 END) AS booked,
			COUNT(CASE WHEN ccm.status IN (0,1,2,5) THEN 1 END) AS committed,
			/* Crew who have answered nothing since a timing change or a
			/* promotion. change_ack rows are DELETED on answer, so existence is
			/* the test; promo_ack rows are KEPT for the audit trail, so the test
			/* is acked_at IS NULL. Guards mirror get-booking.php exactly:
			/* change is 5/7, promotion is 5 only. */
			COUNT(CASE WHEN (ccm.status IN (5,7) AND cca.id IS NOT NULL)
			             OR (ccm.status = 5 AND cpa.id IS NOT NULL AND cpa.acked_at IS NULL)
			           THEN 1 END) AS awaiting,
			c.notes       AS notes,
			b.name        AS booking_name,
			v.venue       AS venue_name,
			ou.firstname  AS contact_fn,
			ou.lastname   AS contact_ln
		FROM calls c
		LEFT JOIN bookings      b   ON b.id       = c.bookingID
		LEFT JOIN venues        v   ON v.id       = b.venueID
		LEFT JOIN users         ou  ON ou.id      = b.onsiteUserID
		LEFT JOIN call_crew_map ccm ON ccm.callID = c.id
		LEFT JOIN call_change_ack cca ON cca.callID = ccm.callID
		                            AND cca.userID = ccm.userID
		LEFT JOIN call_promo_ack  cpa ON cpa.callID = ccm.callID
		                            AND cpa.userID = ccm.userID
		WHERE c.start_date >= $start_i
		  AND c.start_date <  $end_i
		  AND (b.hidden IS NULL OR b.hidden = 0)
		  AND b.status <> 1   /* exclude Completed bookings — match the admin /bookings view */
		GROUP BY c.id
		ORDER BY c.start_date ASC, c.start_time ASC
	";

	$result = mysql_query($sql);

	if ($result === false)
	{
		http_response_code(500);
		die('{"error":"query failed: ' . addslashes(mysql_error()) . '"}');
	}

	$calls = array();

	while ($row = mysql_fetch_object($result))
	{
		$date_i   = (int) $row->start_date;
		$time_hm  = substr($row->start_time, 0, 5);          /* HH:MM */
		if (!preg_match('/^\d{2}:\d{2}$/', $time_hm)) $time_hm = '00:00';

		list($hh, $mm) = array_map('intval', explode(':', $time_hm));
		$start_unix = $date_i + ($hh * 3600) + ($mm * 60);
		$len        = (float) $row->est_length;
		$end_unix   = $start_unix + (int) round($len * 3600);

		$required = (int) $row->required;
		$booked   = (int) $row->booked;

		$contact = trim(trim($row->contact_fn) . ' ' . trim($row->contact_ln));

		$calls[] = array(
			'booking_id'   => (int) $row->booking_id,
			'call_id'      => (int) $row->call_id,
			'booking_name' => $row->booking_name,
			'venue'        => $row->venue_name,
			'contact'      => $contact,
			'call_name'    => $row->call_name,
			'date'         => date('d/m/y',        $date_i),
			'date_iso'     => date('Y-m-d',        $date_i),
			'time'         => $time_hm,
			'length'       => $len,
			'start_iso'    => date('Y-m-d\TH:i:s', $start_unix),
			'end_iso'      => date('Y-m-d\TH:i:s', $end_unix),
			'booked'       => $booked,
			'required'     => $required,
			'committed'    => (int) $row->committed,
			'awaiting'     => (int) $row->awaiting,
			'link_group'   => ($row->link_group === null ? null : (int) $row->link_group),
			'full'         => ($booked >= $required && $required > 0),
			'notes'        => $row->notes,
		);
	}

	/*
	/* Feeds + reserved slots, in two set-based queries over the windowed calls.
	/* Deliberately NOT per-row helper calls — see the timeout note above.
	*/

	/* Edges for every booking represented in the window. Feeds never cross a
	/* booking, so this is the complete subgraph needed for reachability — and
	/* it is bounded, unlike walking the whole table. */

	$bookingIDs = array();

	foreach ($calls as $c)
	{
		$bookingIDs[(int) $c['booking_id']] = true;
	}

	$feedsOf  = array();   /* ALL modes — goat_bulk_reaches walks this */
	$fedByOf  = array();   /* ALL modes */
	$feedsRec = array();   /* recommended subset */
	$fedByRec = array();
	$edgeMode = array();   /* "source:target" -> 'locked'|'recommended' */

	if (count($bookingIDs))
	{
		$bList = implode(',', array_keys($bookingIDs));

		/* Same capability guard as get-booking.php: before the migration,
		/* selecting `mode` fails and empties the feed maps. */

		$modeSel = goat_feeds_have_mode() ? 'mode' : "'locked' AS mode";

		$fres = mysql_query("SELECT source_call, target_call, " . $modeSel . "
		                     FROM call_feeds WHERE booking_id IN (" . $bList . ")");

		if ($fres !== false)
		{
			while ($frow = mysql_fetch_object($fres))
			{
				$s = (int) $frow->source_call;
				$t = (int) $frow->target_call;

				if (!isset($feedsOf[$s])) { $feedsOf[$s] = array(); }
				if (!isset($fedByOf[$t])) { $fedByOf[$t] = array(); }

				$feedsOf[$s][] = $t;
				$fedByOf[$t][] = $s;

				/* mode of the edge s -> t, for the reserved/likely split */
				$edgeMode[$s . ':' . $t] = $frow->mode;

				if ($frow->mode === 'recommended')
				{
					if (!isset($feedsRec[$s])) { $feedsRec[$s] = array(); }
					if (!isset($fedByRec[$t])) { $fedByRec[$t] = array(); }

					$feedsRec[$s][] = $t;
					$fedByRec[$t][] = $s;
				}
			}
		}
	}

	/* every feeder id referenced — sources with targets, plus any id appearing
	/* as a source in a fed_by list — is what the gap query needs */

	$feeders = array();

	foreach ($feedsOf as $s => $ts)
	{
		$feeders[$s] = true;
	}

	foreach ($fedByOf as $t => $srcs)
	{
		foreach ($srcs as $sid)
		{
			$feeders[$sid] = true;
		}
	}

	/* required + committed for every feeder, one query */

	$gapOf = array();   /* feeder call id -> max(0, required - committed) */

	if (count($feeders))
	{
		$fList = implode(',', array_keys($feeders));

		$gres = mysql_query("SELECT c.id,
		                            c.required,
		                            COUNT(CASE WHEN ccm.status IN (0,1,2,5) THEN 1 END) AS committed
		                     FROM calls c
		                     LEFT JOIN call_crew_map ccm ON ccm.callID = c.id
		                     WHERE c.id IN (" . $fList . ")
		                     GROUP BY c.id");

		if ($gres !== false)
		{
			while ($grow = mysql_fetch_object($gres))
			{
				$gap = (int) $grow->required - (int) $grow->committed;
				$gapOf[(int) $grow->id] = ($gap > 0) ? $gap : 0;
			}
		}
	}

	/* Forward reachability over the in-memory adjacency. Depth capped at 10,
	/* visited set makes cycles (migrated symmetric links) harmless. */

	if (!function_exists('goat_bulk_reaches'))
	{
		function goat_bulk_reaches($feedsOf, $from, $to)
		{
			$seen     = array($from => true);
			$frontier = array($from);
			$depth    = 0;

			while (count($frontier) && $depth < 10)
			{
				$next = array();

				foreach ($frontier as $n)
				{
					if (!isset($feedsOf[$n]))
					{
						continue;
					}

					foreach ($feedsOf[$n] as $m)
					{
						if ($m === $to)
						{
							return true;
						}

						if (!isset($seen[$m]))
						{
							$seen[$m] = true;
							$next[]   = $m;
						}
					}
				}

				$frontier = $next;
				$depth++;
			}

			return false;
		}
	}

	/* fold into each emitted call */

	foreach ($calls as $i => $c)
	{
		$cid        = (int) $c['call_id'];
		$rowFeeders = isset($fedByOf[$cid]) ? $fedByOf[$cid] : array();
		$keep       = array();
		$lostTo     = array();

		foreach ($rowFeeders as $f)
		{
			$drop = false;

			foreach ($rowFeeders as $g)
			{
				if ($f === $g)
				{
					continue;
				}

				if (!goat_bulk_reaches($feedsOf, $f, $g))
				{
					continue;
				}

				$mutual = goat_bulk_reaches($feedsOf, $g, $f);

				if (!$mutual)
				{
					$drop       = true;
					$lostTo[$f] = $g;
					break;
				}

				if ($g < $f)
				{
					$drop       = true;
					$lostTo[$f] = $g;
					break;
				}
			}

			if (!$drop)
			{
				$keep[] = $f;
			}
		}

		/* mixed-nest rule (DESIGN §5.2): a survivor that absorbed a LOCKED
		/* feeder counts as locked, whatever its own edge mode. Over-stating
		/* reserved is the safe direction. */

		$firm = array();

		foreach ($lostTo as $dropped => $survivor)
		{
			$dm = isset($edgeMode[$dropped . ':' . $cid]) ? $edgeMode[$dropped . ':' . $cid] : 'locked';

			if ($dm === 'locked')
			{
				$firm[$survivor] = true;
			}
		}

		$reserved = 0;
		$likely   = 0;

		foreach ($keep as $s)
		{
			if (!isset($gapOf[$s]))
			{
				continue;
			}

			$sm       = isset($edgeMode[$s . ':' . $cid]) ? $edgeMode[$s . ':' . $cid] : 'locked';
			$isLocked = ($sm === 'locked') || isset($firm[$s]);

			if ($isLocked)
			{
				$reserved += $gapOf[$s];
			}
			else
			{
				$likely += $gapOf[$s];
			}
		}

		$calls[$i]['feeds']              = isset($feedsOf[$cid])  ? $feedsOf[$cid]  : array();
		$calls[$i]['fed_by']             = $rowFeeders;
		$calls[$i]['feeds_recommended']  = isset($feedsRec[$cid]) ? $feedsRec[$cid] : array();
		$calls[$i]['fed_by_recommended'] = isset($fedByRec[$cid]) ? $fedByRec[$cid] : array();
		$calls[$i]['reserved']           = $reserved;
		$calls[$i]['likely']             = $likely;
		$calls[$i]['free_to_fill']       = (int) $c['required'] - (int) $c['committed'] - $reserved - $likely;
	}

	echo json_encode(array(
		'window' => array('start' => $start_raw, 'end' => $end_raw),
		'calls'  => $calls,
	));

?>
