<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');
	include('call-graph.php');

	header('Content-Type: application/json');

	/*
	/* Maintain directional call dependencies ("feeds").
	/*
	/* An edge source_call -> target_call means crew booked on source are also
	/* booked on target. This endpoint only maintains `call_feeds` — it never
	/* touches call_crew_map, calendars or accounting.
	/*
	/*   action = "add"    : {action, source_call, target_calls:[>=1], confirm?}
	/*                       All calls must be in the SAME booking. Warns (and
	/*                       requires confirm:true) if the edge would
	/*                       over-subscribe a target, or if source and target
	/*                       overlap in time.
	/*   action = "remove" : {action, source_call, target_calls:[>=1]}
	/*   action = "list"   : {action, booking_id}
	/*
	/* Admin-only (same gate as link-calls.php / update-call.php).
	/* PHP 5.x — no null-coalescing, no short array syntax.
	*/

	function send_status($code, $msg)
	{
		$proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($proto . ' ' . $code . ' ' . $msg);
	}

	/* ---- ADMIN ONLY ---- */

	if (goat_user_cohort() !== 'admin')
	{
		send_status(403, 'Forbidden');
		die('{"error":"Admin only"}');
	}

	/* ---- parse body ---- */

	$raw     = file_get_contents('php://input');
	$payload = json_decode($raw);

	if (!$payload || !isset($payload->action))
	{
		send_status(400, 'Bad Request');
		die('{"error":"Invalid or missing JSON body"}');
	}

	$action = $payload->action;

	/* ======================= LIST ======================= */

	if ($action === 'list')
	{
		$bookingID = isset($payload->booking_id) ? (int) $payload->booking_id : 0;

		if ($bookingID <= 0)
		{
			send_status(422, 'Unprocessable Entity');
			die('{"error":"booking_id required"}');
		}

		$res   = mysql_query("SELECT source_call, target_call FROM call_feeds
		                      WHERE booking_id = " . $bookingID);
		$edges = array();

		if ($res !== false)
		{
			while ($row = mysql_fetch_object($res))
			{
				$edges[] = array(
					'source_call' => (int) $row->source_call,
					'target_call' => (int) $row->target_call
				);
			}
		}

		echo json_encode(array('ok' => true, 'booking_id' => $bookingID, 'edges' => $edges));
		die();
	}

	/* ---- add / remove share source + targets parsing ---- */

	$source = isset($payload->source_call) ? (int) $payload->source_call : 0;

	if ($source <= 0)
	{
		send_status(422, 'Unprocessable Entity');
		die('{"error":"source_call required"}');
	}

	$targets = array();

	if (isset($payload->target_calls) && is_array($payload->target_calls))
	{
		foreach ($payload->target_calls as $v)
		{
			$n = (int) $v;

			if ($n > 0 && $n !== $source && !in_array($n, $targets))
			{
				$targets[] = $n;
			}
		}
	}

	if (!count($targets))
	{
		send_status(422, 'Unprocessable Entity');
		die('{"error":"target_calls must contain at least one call id other than source_call"}');
	}

	/* ======================= ADD ======================= */

	if ($action === 'add')
	{
		$confirm = (isset($payload->confirm) && $payload->confirm) ? true : false;

		/* all calls must exist and share one booking */

		$all  = array_merge(array($source), $targets);
		$res  = mysql_query("SELECT id, bookingID, start_date, start_time, est_length, call_name
		                     FROM calls WHERE id IN (" . implode(',', $all) . ")");

		if ($res === false)
		{
			send_status(500, 'Internal Server Error');
			die('{"error":"calls lookup failed: ' . addslashes(mysql_error()) . '"}');
		}

		$info      = array();
		$bookingID = null;
		$errors    = array();

		while ($row = mysql_fetch_object($res))
		{
			$info[(int) $row->id] = $row;

			if ($bookingID === null)
			{
				$bookingID = (int) $row->bookingID;
			}
			else if ((int) $row->bookingID !== $bookingID)
			{
				$errors[] = 'All calls must belong to the same booking';
			}
		}

		if (count($info) !== count($all))
		{
			$errors[] = 'One or more calls were not found';
		}

		$errors = array_values(array_unique($errors));

		if (count($errors))
		{
			send_status(422, 'Unprocessable Entity');
			echo json_encode(array('error' => 'add rejected', 'errors' => $errors));
			die();
		}

		/*
		/* WARNINGS — overridable with confirm:true.
		/*
		/* 1. Time overlap. addToCall rejects on calendar clash, and confcrew
		/*    writes a calendar row per confirmed call, so a package containing
		/*    two overlapping calls can never be fully confirmed (DESIGN §5.9).
		/* 2. Over-subscription: the target's free_to_fill would go negative
		/*    once this feeder's unfilled slots are reserved (DESIGN §3.6).
		*/

		$warnings = array();
		$srcStart = (int) $info[$source]->start_date + goat_time_to_secs($info[$source]->start_time);
		$srcEnd   = $srcStart + (int) round(((double) $info[$source]->est_length) * 3600);

		foreach ($targets as $t)
		{
			$tStart = (int) $info[$t]->start_date + goat_time_to_secs($info[$t]->start_time);
			$tEnd   = $tStart + (int) round(((double) $info[$t]->est_length) * 3600);

			if ($srcStart < $tEnd && $tStart < $srcEnd)
			{
				$warnings[] = array(
					'type'      => 'overlap',
					'call_id'   => $t,
					'call_name' => $info[$t]->call_name,
					'message'   => 'These calls overlap in time, so crew can never be confirmed for both.'
				);
			}

			/*
			/* Compute reserved AS IT WOULD BE with this edge, using the same
			/* maximal-feeder rule, rather than subtracting the source's gap
			/* unconditionally. A redundant edge adds no bodies; an edge whose
			/* source displaces an existing feeder changes reserved by the
			/* difference, not the whole gap.
			/*
			/* Warn only if THIS edge makes things worse. A call that is
			/* already over-subscribed stays flagged persistently in the
			/* booking dialog and Finder (DESIGN §3.6) — nagging about it here
			/* would train ops to click through the warning.
			*/

			$before = goat_call_feed_counts($t);
			$after  = goat_call_feed_counts_with($t, $source);

			if ($after['free_to_fill'] < 0 && $after['free_to_fill'] < $before['free_to_fill'])
			{
				$warnings[] = array(
					'type'      => 'oversubscribed',
					'call_id'   => $t,
					'call_name' => $info[$t]->call_name,
					'shortfall' => -$after['free_to_fill'],
					'message'   => 'This call would be over-subscribed by ' . (-$after['free_to_fill']) . '.'
				);
			}

			/* Redundant edge — the source already reaches this target through
			/* another feed, so the edge commits nobody who is not already
			/* committed. Harmless once reserved() ignores it, but it muddies
			/* the graph and is almost always a mistake. */

			$existingDown = goat_calls_downstream($source);

			if (in_array($t, $existingDown))
			{
				$warnings[] = array(
					'type'      => 'redundant',
					'call_id'   => $t,
					'call_name' => $info[$t]->call_name,
					'message'   => 'Crew from this call already reach ' . $info[$t]->call_name
					             . ' through another feed — this edge adds nothing.'
				);
			}
		}

		if (count($warnings) && !$confirm)
		{
			send_status(409, 'Conflict');
			echo json_encode(array('ok' => false, 'needs_confirm' => true, 'warnings' => $warnings));
			die();
		}

		/* insert, ignoring duplicates via uniq_edge */

		$added = array();
		$now   = time();

		foreach ($targets as $t)
		{
			mysql_query("INSERT IGNORE INTO call_feeds
			             (booking_id, source_call, target_call, created)
			             VALUES (" . $bookingID . ", " . $source . ", " . $t . ", " . $now . ")");

			if (mysql_error())
			{
				send_status(500, 'Internal Server Error');
				die('{"error":"feed insert failed: ' . addslashes(mysql_error()) . '"}');
			}

			$added[] = $t;
		}

		echo json_encode(array(
			'ok'          => true,
			'action'      => 'add',
			'booking_id'  => $bookingID,
			'source_call' => $source,
			'targets'     => $added,
			'warnings'    => $warnings
		));
		die();
	}

	/* ======================= REMOVE ======================= */

	if ($action === 'remove')
	{
		mysql_query("DELETE FROM call_feeds
		             WHERE source_call = " . $source . "
		               AND target_call IN (" . implode(',', $targets) . ")");

		if (mysql_error())
		{
			send_status(500, 'Internal Server Error');
			die('{"error":"feed delete failed: ' . addslashes(mysql_error()) . '"}');
		}

		echo json_encode(array(
			'ok'          => true,
			'action'      => 'remove',
			'source_call' => $source,
			'targets'     => $targets
		));
		die();
	}

	send_status(422, 'Unprocessable Entity');
	die('{"error":"Unknown action"}');

?>
