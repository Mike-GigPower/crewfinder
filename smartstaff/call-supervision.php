<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');
	include_once('supervision-graph.php');

	header('Content-Type: application/json');

	/*
	/* Maintain crew-boss supervision edges.
	/*
	/* An edge boss_call -> child_call means whoever is confirmed on boss_call
	/* oversees child_call.
	/*
	/* IT GRANTS VISIBILITY AND AUTHORISATION ONLY. IT NEVER BOOKS ANYONE ONTO
	/* ANYTHING. `call_feeds` does that, and the two must not be confused: a
	/* feed moves crew, a supervision edge only decides who may see and contact
	/* them. This endpoint only maintains `call_supervision` — it never touches
	/* call_crew_map, calls, calendars or accounting.
	/*
	/* UNIQUE (child_call) is single-column: ONE boss call per supervised call.
	/* That is what makes rung 2 of the contact hierarchy deterministic where
	/* two boss calls overlap in time, so `set` upserts rather than inserting
	/* (see the write below).
	/*
	/*   action = "set"   : {action, boss_call, child_calls:[>=1], confirm?}
	/*                      All calls must be in the SAME booking. Warns (and
	/*                      requires confirm:true) if the boss call is not
	/*                      named as one, if it has no confirmed crew, or if a
	/*                      child is being taken from another boss call.
	/*   action = "clear" : {action, child_calls:[>=1]}
	/*   action = "list"  : {action, booking_id}
	/*
	/* Admin-only (same gate as call-feeds.php).
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

	/*
	/* Who granted the access. `call_supervision` is the only smartstaff table
	/* carrying a created_by, and it is the only record of who gave one person
	/* sight of up to 58 other people's names and mobiles.
	/*
	/* Read STRAIGHT FROM THE SESSION — deliberately NOT goat_acting_user_id().
	/* That helper falls through the session check to a service-key branch that
	/* takes userID off $_GET/$_POST, which is right for self-scoped crew
	/* endpoints where the backend asserts identity and wrong here: anything
	/* holding the service key could then stamp an arbitrary created_by and the
	/* audit column would record whoever the caller named.
	/*
	/* The admin gate above has already run, so this should never be 0. Store it
	/* anyway — a 0 is a visible anomaly in an audit column, whereas refusing at
	/* this point would be a confusing failure after a successful auth.
	*/

	$actor = isset($_SESSION[SITE_KEY]['userID']) ? (int) $_SESSION[SITE_KEY]['userID'] : 0;

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

		/* BOTH ends INNER JOINed, the same rule every helper in
		/* supervision-graph.php follows: an edge whose boss or child call has
		/* been deleted is invisible, because that is the answer those helpers
		/* give. A listing that showed rows the resolver ignores would send ops
		/* looking for a fault that is not there. */

		$res   = mysql_query("SELECT s.id, s.boss_call, s.child_call,
		                             cb.call_name AS boss_call_name,
		                             cc.call_name AS child_call_name,
		                             s.created, s.created_by
		                      FROM call_supervision s
		                      INNER JOIN calls cb ON cb.id = s.boss_call
		                      INNER JOIN calls cc ON cc.id = s.child_call
		                      WHERE s.booking_id = " . $bookingID . "
		                      ORDER BY cb.start_date, cb.start_time,
		                               cc.start_date, cc.start_time");
		$edges = array();

		if ($res !== false)
		{
			while ($row = mysql_fetch_object($res))
			{
				$edges[] = array(
					'id'              => (int) $row->id,
					'boss_call'       => (int) $row->boss_call,
					'child_call'      => (int) $row->child_call,
					'boss_call_name'  => $row->boss_call_name,
					'child_call_name' => $row->child_call_name,
					'created'         => (int) $row->created,
					'created_by'      => (int) $row->created_by
				);
			}
		}

		echo json_encode(array(
			'ok'         => true,
			'action'     => 'list',
			'booking_id' => $bookingID,
			'edges'      => $edges
		));
		die();
	}

	/* ---- set / clear share child_calls parsing ---- */

	/*
	/* boss_call is read here but required only by `set`. `clear` is keyed on
	/* the child alone — UNIQUE (child_call) means a child identifies its edge
	/* without naming the boss, so demanding one would be asking for a fact the
	/* caller does not need to know. Reading it before the loop keeps the
	/* self-edge filter one rule for both actions: on `clear` $boss is 0 and the
	/* filter is a no-op, since every id collected is already > 0.
	/*
	/* An UNRECOGNISED action also falls through to here, exactly as it does
	/* in call-feeds.php, so a bad action with no child_calls is rejected by
	/* the check below rather than by the "Unknown action" at the foot of the
	/* file. Both are 422 and the request is refused either way. Gating the
	/* action up here would read better but would make that final line dead
	/* code, and it is the one that answers a well-formed request naming an
	/* action this endpoint does not have.
	*/

	$boss = isset($payload->boss_call) ? (int) $payload->boss_call : 0;

	if ($action === 'set' && $boss <= 0)
	{
		send_status(422, 'Unprocessable Entity');
		die('{"error":"boss_call required"}');
	}

	$children = array();

	if (isset($payload->child_calls) && is_array($payload->child_calls))
	{
		foreach ($payload->child_calls as $v)
		{
			$n = (int) $v;

			if ($n > 0 && $n !== $boss && !in_array($n, $children))
			{
				$children[] = $n;
			}
		}
	}

	if (!count($children))
	{
		send_status(422, 'Unprocessable Entity');
		die('{"error":"child_calls must contain at least one call id other than boss_call"}');
	}

	/* ======================= SET ======================= */

	if ($action === 'set')
	{
		$confirm = (isset($payload->confirm) && $payload->confirm) ? true : false;

		/* all calls must exist and share one booking */

		$all = array_merge(array($boss), $children);
		$res = mysql_query("SELECT id, bookingID, call_name
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
			echo json_encode(array('error' => 'set rejected', 'errors' => $errors));
			die();
		}

		/*
		/* NO TIME-OVERLAP CHECK, AND THIS IS NOT AN OVERSIGHT. call-feeds.php
		/* warns on overlap because a feed books crew and two overlapping calls
		/* can never both be confirmed. A supervision edge books nobody, and a
		/* boss routinely oversees calls that run before, after or alongside
		/* their own. There is deliberately no time constraint here.
		/*
		/* Nor is a child that is itself boss-named refused. A boss call
		/* supervising another boss call is unusual, not incoherent — the
		/* not_boss_named warning below covers the boss end, and nothing covers
		/* the child end because nothing is wrong with it.
		/*
		/* WARNINGS — overridable with confirm:true.
		*/

		$warnings = array();

		if (!goat_is_boss_call_name($info[$boss]->call_name))
		{
			$warnings[] = array(
				'type'      => 'not_boss_named',
				'call_id'   => $boss,
				'call_name' => $info[$boss]->call_name,
				'message'   => '"' . $info[$boss]->call_name . '" is not named as a boss call. '
				             . 'Assign it as the supervisor anyway?'
			);
		}

		/* No confirmed crew on the boss call means the edge resolves to nobody.
		/* The message states the actual consequence rather than a generic
		/* caution, because the consequence is specific and provable: rung 2 of
		/* the contact hierarchy finds no one and crew on the supervised calls
		/* fall through to the on-site contact. status = 5 is Confirmed.
		/*
		/* The key column is crewmapID, NOT id. call_crew_map is the one table
		/* here that does not use `id`, and the first cut of this query got it
		/* wrong.
		/*
		/* A FAILED query is therefore a 500, not a warning. Degrading to "warn"
		/* is what hid that mistake: the query failed on every call, so
		/* no_confirmed_boss fired for every boss including one with three
		/* confirmed crew, and a broken query became indistinguishable from a
		/* real finding. A warning that always fires is worse than no warning —
		/* it trains ops to click through the interstitial that also carries the
		/* reassignment warning. */

		$cres = mysql_query("SELECT ccm.crewmapID FROM call_crew_map ccm
		                     WHERE ccm.callID = " . $boss . "
		                       AND ccm.status = 5
		                     LIMIT 1");

		if ($cres === false)
		{
			send_status(500, 'Internal Server Error');
			die('{"error":"confirmed-crew lookup failed: ' . addslashes(mysql_error()) . '"}');
		}

		if (!mysql_fetch_object($cres))
		{
			$warnings[] = array(
				'type'      => 'no_confirmed_boss',
				'call_id'   => $boss,
				'call_name' => $info[$boss]->call_name,
				'message'   => '"' . $info[$boss]->call_name . '" has no confirmed crew. '
				             . 'Crew on the supervised calls will be shown the on-site contact instead.'
			);
		}

		/* Children already bossed by a DIFFERENT call. INNER JOIN calls so a
		/* dangling edge raises no warning naming a call that no longer exists —
		/* the upsert below overwrites it either way. */

		$rres = mysql_query("SELECT s.child_call, s.boss_call, cb.call_name AS boss_call_name
		                     FROM call_supervision s
		                     INNER JOIN calls cb ON cb.id = s.boss_call
		                     WHERE s.child_call IN (" . implode(',', $children) . ")
		                       AND s.boss_call <> " . $boss);

		if ($rres !== false)
		{
			while ($rrow = mysql_fetch_object($rres))
			{
				$c = (int) $rrow->child_call;

				$warnings[] = array(
					'type'      => 'reassignment',
					'call_id'   => $c,
					'call_name' => $info[$c]->call_name,
					'message'   => '"' . $info[$c]->call_name . '" is currently bossed by '
					             . '"' . $rrow->boss_call_name . '". '
					             . 'Reassign to "' . $info[$boss]->call_name . '"?'
				);
			}
		}

		if (count($warnings) && !$confirm)
		{
			send_status(409, 'Conflict');
			echo json_encode(array('ok' => false, 'needs_confirm' => true, 'warnings' => $warnings));
			die();
		}

		/*
		/* UPSERT, NOT DELETE-THEN-INSERT.
		/*
		/* UNIQUE (child_call) means reassigning a child from boss A to boss B
		/* collides, so a plain INSERT fails. Deleting first would work on an
		/* engine with transactions — but this is MyISAM, so between the DELETE
		/* and the INSERT the child has NO supervisor at all, and a failure
		/* between the two leaves it permanently unsupervised: the contact
		/* hierarchy silently reverts to overlap for that call, which is the
		/* exact ambiguity this table exists to remove. The upsert has no such
		/* window, and is what call-feeds.php already does on this engine in
		/* production.
		/*
		/* created / created_by are updated on reassignment deliberately: the
		/* row records the CURRENT grant, not the first one. If a history of
		/* grants is ever wanted, that is a separate append-only table, not a
		/* mutable row pretending to be one.
		/*
		/* No mysql_real_escape_string anywhere in this write — every value
		/* interpolated below is an int already cast by the parsing above. There
		/* is no string in this endpoint that reaches SQL.
		*/

		$set = array();
		$now = time();

		foreach ($children as $c)
		{
			mysql_query("INSERT INTO call_supervision
			             (booking_id, boss_call, child_call, created, created_by)
			             VALUES (" . $bookingID . ", " . $boss . ", " . $c . ",
			                     " . $now . ", " . $actor . ")
			             ON DUPLICATE KEY UPDATE
			               boss_call  = VALUES(boss_call),
			               booking_id = VALUES(booking_id),
			               created    = VALUES(created),
			               created_by = VALUES(created_by)");

			if (mysql_error())
			{
				send_status(500, 'Internal Server Error');
				die('{"error":"supervision insert failed: ' . addslashes(mysql_error()) . '"}');
			}

			$set[] = $c;
		}

		echo json_encode(array(
			'ok'         => true,
			'action'     => 'set',
			'booking_id' => $bookingID,
			'boss_call'  => $boss,
			'children'   => $set,
			'warnings'   => $warnings
		));
		die();
	}

	/* ======================= CLEAR ======================= */

	if ($action === 'clear')
	{
		/* Clearing a child that has no edge is NOT an error — the caller asked
		/* for that child to be unsupervised and it is. Ids are already
		/* (int)-cast by the parsing above. */

		mysql_query("DELETE FROM call_supervision
		             WHERE child_call IN (" . implode(',', $children) . ")");

		if (mysql_error())
		{
			send_status(500, 'Internal Server Error');
			die('{"error":"supervision delete failed: ' . addslashes(mysql_error()) . '"}');
		}

		echo json_encode(array(
			'ok'       => true,
			'action'   => 'clear',
			'children' => $children
		));
		die();
	}

	send_status(422, 'Unprocessable Entity');
	die('{"error":"Unknown action"}');

?>
