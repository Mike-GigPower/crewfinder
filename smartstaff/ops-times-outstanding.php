<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');
	include_once('supervision-graph.php');
	include_once('time-submission-graph.php');
	include_once('call-graph.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* THE GOAT / Ops landing — the "Times outstanding" lane. One row per
	/* FINISHED call whose times have not arrived, carrying who to chase.
	/*
	/* THIS IS THE OTHER READER OF THE INVERSION. calls-awaiting-times.php asks
	/* the same graph "which bosses owe times" so a cron can push to them; this
	/* asks "who is the boss of this call" so Rich can ring them. Both go
	/* through goat_bosses_by_call() in supervision-graph.php, and neither
	/* resolves a boss for itself. If you are about to add a fourth branch to
	/* one of these, it belongs in the helper.
	/*
	/* WHAT MAKES A CALL OUTSTANDING — three conditions, and the second is the
	/* one that keeps this lane honest:
	/*
	/*   1. It has FINISHED. Scheduled end in the past; a call still running
	/*      owes nothing yet.
	/*   2. Someone confirmed on it has NO TIMES BY EITHER ROUTE — no live
	/*      submission from a boss AND nothing keyed against them in
	/*      call_crew_map. The second half matters: Ops have been typing these
	/*      numbers straight into the grid for years, and a lane that only
	/*      looked at submissions would open with a fortnight of finished work
	/*      it had no way to clear.
	/*   3. calls.times_filled is not set — the Ops review tick.
	/*
	/* WHY BOTH 2 AND 3. Condition 2 alone cannot be satisfied by accepting a
	/* call where somebody genuinely worked no hours, so the row would never
	/* leave; condition 3 alone would show every call THE GOAT has ever written
	/* times for, because update-call-times.php deliberately never sets the
	/* flag (and get-performance.php refuses to gate on it for that same
	/* reason). Accepting in THE GOAT now writes the tick — decision 28 — which
	/* is what finally gives this lane a done-condition it can reach.
	/*
	/* times_filled IS CAST IN PHP, NEVER COMPARED IN SQL. Same discipline as
	/* is_call_boss: these flags are written as STRINGS by SmartStaff's own
	/* forms, and a "= 1" comparison is not reliable across MySQL versions.
	/*
	/* PRIVACY — this returns the BOSS's name and mobile and no other person.
	/* Not the roster, not a crew name, not an EIN. The row's whole purpose is
	/* "ring this person", the caller is already gated to the read-all cohorts
	/* that can open the booking anyway, and the counts say how many crew are
	/* outstanding without naming any of them. calls-awaiting-times.php returns
	/* even less because a push body needs even less; if you are adding a
	/* column here, the question is whether the ROW renders it.
	/*
	/* A FAILED LOOKUP IS A 500, NEVER AN EMPTY LANE. An empty `calls` array
	/* means "every finished call has its times", which is the good news this
	/* lane exists to report. A broken query that degraded to empty would look
	/* exactly like that. THE GOAT renders an unavailable lane from the error,
	/* which is a visible absence rather than a false all-clear.
	/*
	/* PHP 5.x — mysql_*, no ??, no short array syntax.
	*/

	/*
	/* ---- AUTH ----
	/*
	/* The dual gate, copied from get-pending-acks-bulk.php: a service key, or
	/* a logged-in admin / leadership / operations session (THE GOAT desktop).
	*/

	$goat_key = isset($_SERVER['HTTP_X_GOAT_SERVICE_KEY'])
	          ? $_SERVER['HTTP_X_GOAT_SERVICE_KEY'] : '';

	if (!goat_service_key_ok($goat_key) && !goat_can_read_all())
	{
		goat_json_error(403, 'Service key or Admin/Leadership session required');
	}

	/*
	/* ---- WINDOW ----
	/*
	/* start / end are YYYY-MM-DD (Melbourne — global.php sets the tz), matching
	/* every other ops lane endpoint, and they bound the call's scheduled END
	/* rather than its start: a call is owed times when it has finished, and its
	/* start tells you nothing about when that was. `end` is INCLUSIVE of the
	/* whole day, as get-open-offers-bulk.php's is.
	/*
	/* DEFAULT IS THE LAST 14 DAYS. Long enough that a fortnight of backlog is
	/* visible, short enough that the lane does not open with the archive.
	/*
	/* THE 31-DAY CAP IS A GUARD, NOT A POLICY — unlike the 7 days in
	/* calls-awaiting-times.php, which encodes decision 7. Nothing here stops
	/* prompting or expires; the cap only refuses to scan `calls` back to 2009.
	/* 422 rather than a silent clamp: quietly answering a different question
	/* hides the caller's bug.
	*/

	$today = date('Y-m-d');

	$start_raw = isset($_GET['start']) ? trim($_GET['start']) : '';
	$end_raw   = isset($_GET['end'])   ? trim($_GET['end'])   : '';

	if ($start_raw === '') $start_raw = date('Y-m-d', strtotime($today . ' -14 days'));
	if ($end_raw   === '') $end_raw   = $today;

	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_raw) ||
	    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_raw))
	{
		goat_json_error(400, 'start and end must be YYYY-MM-DD');
	}

	$since = strtotime($start_raw . ' 00:00:00');
	$until = strtotime($end_raw   . ' 00:00:00') + 86400;   /* inclusive of end's whole day */

	if ($since === false || $until === false || $until <= $since)
	{
		goat_json_error(400, 'invalid window');
	}

	if (($until - $since) > (31 * 86400))
	{
		goat_json_error(422, 'window exceeds 31 days');
	}

	$now = time();

	/*
	/* ---- 1 of 6: CANDIDATE CALLS ----
	/*
	/* CANCELLED CALLS ARE EXCLUDED -- as DEFENCE IN DEPTH, not as the only
	/* guard. The roster read at 3 of 6 is `ccm.status = 5`, and every row on a
	/* cancelled call is status 9, so otl_needing() already returns 0 for it and
	/* the call is dropped at 5 of 6 without this clause. That protection is
	/* INCIDENTAL and would disappear the day the roster predicate widens. This
	/* states the intent directly, and drops the row before the sibling, roster,
	/* submission and boss queries run on it.
	/*
	/* No capability guard, unlike get-calls-bulk.php: the file rule above is
	/* that a failed lookup is a 500, never an empty lane. A missing column here
	/* SHOULD break loudly rather than report the all-clear this lane exists to
	/* earn.
	/*
	/* The SIBLING path (2 of 6) needs nothing: the roster read is
	/* `ccm.status = 5`, every row on a cancelled call is status 9, so
	/* $confirmedOf is never populated for it and otl_needing() returns 0. A
	/* cancelled sibling cannot raise sibling_times_pending.
	/*
	/* SQL PREFILTERS COARSELY ON start_date; PHP FILTERS EXACTLY ON THE END,
	/* for the reason calls-awaiting-times.php sets out at length: start_date is
	/* a unix timestamp at LOCAL MIDNIGHT and start_time a separate TIME column,
	/* so adding TIME_TO_SEC() to it in SQL is an hour wrong on the two
	/* Melbourne DST transitions. goat_call_window() does the same sum through
	/* strtotime(), which knows about them.
	/*
	/* THE BACK-MARGIN. A call ending inside the window may have STARTED well
	/* before it, so the prefilter reaches back a further 7 days.
	*/

	$scan_from = $since - (7 * 86400);

	$sql = "
		SELECT
			c.id           AS call_id,
			c.call_name    AS call_name,
			c.start_date   AS start_date,
			c.start_time   AS start_time,
			c.est_length   AS est_length,
			c.times_filled AS times_filled,
			c.bookingID    AS booking_id,
			b.name         AS booking_name,
			v.venue        AS venue_name
		FROM calls c
		LEFT JOIN bookings b ON b.id = c.bookingID
		LEFT JOIN venues   v ON v.id = b.venueID
		WHERE c.start_date >= " . (int) $scan_from . "
		  AND c.start_date <= " . (int) $until . "
		  AND c.cancelled_at IS NULL
		ORDER BY c.start_date ASC, c.start_time ASC
	";

	$result = mysql_query($sql);

	if ($result === false)
	{
		goat_json_error(500, 'call query failed: ' . mysql_error());
	}

	$callInfo = array();   /* callID -> assembled row */

	while ($row = mysql_fetch_object($result))
	{
		$win = goat_call_window($row);

		if ($win['end'] < $since || $win['end'] >= $until)
		{
			continue;   /* ends outside the window */
		}

		if ($win['end'] > $now)
		{
			continue;   /* has not finished yet — owes nothing */
		}

		if ((int) $row->times_filled === 1)
		{
			continue;   /* Ops have accepted it */
		}

		$cid = (int) $row->call_id;

		$callInfo[$cid] = array(
			'call_id'      => $cid,
			'call_name'    => html_entity_decode((string) $row->call_name, ENT_QUOTES),
			'booking_id'   => (int) $row->booking_id,
			'booking_name' => html_entity_decode((string) $row->booking_name, ENT_QUOTES),
			'venue'        => html_entity_decode((string) $row->venue_name, ENT_QUOTES),
			'start'        => date('Y-m-d H:i', $win['start']),
			'end'          => date('Y-m-d H:i', $win['end']),
			'end_unix'     => $win['end'],
		);
	}

	if (count($callInfo) === 0)
	{
		echo json_encode(array(
			'ok'           => true,
			'generated_at' => date('Y-m-d\TH:i:s', $now),
			'window'       => array('start' => $start_raw, 'end' => $end_raw),
			'calls'        => array(),
			'counts'       => otl_empty_counts()
		));
		exit;
	}

	/*
	/* ---- 2 of 6: THE SIBLINGS ----
	/*
	/* Locked call_feeds neighbours, in BOTH directions, of every candidate.
	/* They are pulled now — before anything is filtered — so that the roster
	/* and submission reads below cover them in the same two queries the
	/* candidates need, rather than a second round afterwards.
	/*
	/* WHY THE LANE CARES. derive_times() tops a short engagement up to the
	/* four-hour minimum across a continuous block, and when a sibling's real
	/* times have not arrived it uses that sibling's SCHEDULED hours and returns
	/* sibling_hours_estimated. A figure computed that way can change once the
	/* sibling's times land. Nothing in THE GOAT derives yet — derivation.py is
	/* still only reachable from its own tests — so this flags the CONDITION
	/* that makes such a figure provisional rather than the figure itself: a
	/* linked call whose own times are still missing. It is the same question
	/* asked one step earlier, and it does not pretend to be the derivation.
	/*
	/* LOCKED EDGES ONLY, matching continuous_block(): a `recommended` edge is
	/* a suggestion about booking, not a statement that the two calls are one
	/* engagement. The mode column is absent on older schemas, where every edge
	/* reads as locked — the same fallback goat_feed_step() makes.
	*/

	$owedIDs  = array_keys($callInfo);
	$owedList = implode(',', array_map('intval', $owedIDs));

	$modeSql = goat_feeds_have_mode() ? " AND f.mode = 'locked'" : '';

	$feedRes = mysql_query("SELECT f.source_call AS source_call, f.target_call AS target_call
	                        FROM call_feeds f
	                        INNER JOIN calls cs ON cs.id = f.source_call
	                        INNER JOIN calls ct ON ct.id = f.target_call
	                        WHERE (f.source_call IN (" . $owedList . ")
	                            OR f.target_call IN (" . $owedList . "))" . $modeSql);

	if ($feedRes === false)
	{
		goat_json_error(500, 'feed query failed: ' . mysql_error());
	}

	$siblingsOf = array();   /* callID -> array(siblingID => true) */

	while ($frow = mysql_fetch_object($feedRes))
	{
		$a = (int) $frow->source_call;
		$b = (int) $frow->target_call;

		if ($a <= 0 || $b <= 0 || $a === $b)
		{
			continue;
		}

		if (isset($callInfo[$a]))
		{
			if (!isset($siblingsOf[$a])) $siblingsOf[$a] = array();
			$siblingsOf[$a][$b] = true;
		}

		if (isset($callInfo[$b]))
		{
			if (!isset($siblingsOf[$b])) $siblingsOf[$b] = array();
			$siblingsOf[$b][$a] = true;
		}
	}

	$allIDs = array();   /* candidates + siblings, the read set for 3 and 4 */

	foreach ($owedIDs as $cid)
	{
		$allIDs[$cid] = true;
	}

	foreach ($siblingsOf as $cid => $sibs)
	{
		foreach ($sibs as $sid => $ignored)
		{
			$allIDs[$sid] = true;
		}
	}

	$allList = implode(',', array_map('intval', array_keys($allIDs)));

	/*
	/* ---- 3 of 6: THE ROSTER, AND WHO ALREADY HAS TIMES KEYED ----
	/*
	/* One query for both, over candidates AND siblings.
	/*
	/* THE KEYED TEST IS get-performance.php's, DELIBERATELY: a row counts as
	/* keyed unless BOTH `on` and `off` are still 00:00:00. That endpoint
	/* measures delivered work the same way and says why — times_filled is a
	/* human-review flag THE GOAT's own writer never sets, so the per-row
	/* on/off test is the honest one. Two surfaces disagreeing about whether a
	/* call's times are in would be visible as a lane that will not clear.
	*/

	$rosterRes = mysql_query("SELECT ccm.callID AS call_id, ccm.userID AS user_id,
	                                 ccm.`on` AS on_time, ccm.`off` AS off_time
	                          FROM call_crew_map ccm
	                          WHERE ccm.callID IN (" . $allList . ")
	                            AND ccm.status = 5");

	if ($rosterRes === false)
	{
		goat_json_error(500, 'roster query failed: ' . mysql_error());
	}

	$confirmedOf = array();   /* callID -> array(userID => true) */
	$keyedOf     = array();   /* callID -> array(userID => true) */

	while ($rrow = mysql_fetch_object($rosterRes))
	{
		$cid = (int) $rrow->call_id;
		$uid = (int) $rrow->user_id;

		if ($cid <= 0 || $uid <= 0)
		{
			continue;
		}

		if (!isset($confirmedOf[$cid]))
		{
			$confirmedOf[$cid] = array();
			$keyedOf[$cid]     = array();
		}

		$confirmedOf[$cid][$uid] = true;

		$on  = trim((string) $rrow->on_time);
		$off = trim((string) $rrow->off_time);

		if (!($on === '00:00:00' && $off === '00:00:00'))
		{
			$keyedOf[$cid][$uid] = true;
		}
	}

	/*
	/* ---- 4 of 6: WHO HAS SUBMITTED ----
	/*
	/* Through the slice 1 helper, which owns the "live submission" predicate.
	/* Do not ask this question with a second query: two opinions about who has
	/* submitted is how a call shows as chased on one surface and outstanding
	/* on another.
	*/

	$submitted = goat_submitted_by_call(array_keys($allIDs));

	if (!$submitted['ok'])
	{
		goat_json_error(500, $submitted['error']);
	}

	$submittedOf = $submitted['by_call'];

	/*
	/* ---- 5 of 6: WHAT IS STILL NEEDED ----
	/*
	/* A confirmed crew member needs times when they have NEITHER a live
	/* submission NOR anything keyed against them. Computed for the siblings
	/* too, because a sibling still needing times is exactly the condition that
	/* makes a minimum top-up provisional.
	*/

	function otl_needing($callID, $confirmedOf, $keyedOf, $submittedOf)
	{
		if (!isset($confirmedOf[$callID]))
		{
			return 0;   /* nobody confirmed — nothing can be outstanding */
		}

		$n = 0;

		foreach ($confirmedOf[$callID] as $uid => $ignored)
		{
			if (isset($submittedOf[$callID][$uid]))  continue;
			if (isset($keyedOf[$callID][$uid]))      continue;

			$n++;
		}

		return $n;
	}

	function otl_empty_counts()
	{
		return array(
			'total'  => 0,
			'status' => array('awaiting_review' => 0, 'not_submitted' => 0, 'no_boss' => 0),
			'age'    => array('under24' => 0, 'from24to72' => 0, 'over72' => 0)
		);
	}

	foreach ($callInfo as $cid => $ignored)
	{
		$needing = otl_needing($cid, $confirmedOf, $keyedOf, $submittedOf);

		if ($needing <= 0)
		{
			unset($callInfo[$cid]);
			continue;
		}

		$callInfo[$cid]['needing'] = $needing;
	}

	if (count($callInfo) === 0)
	{
		echo json_encode(array(
			'ok'           => true,
			'generated_at' => date('Y-m-d\TH:i:s', $now),
			'window'       => array('start' => $start_raw, 'end' => $end_raw),
			'calls'        => array(),
			'counts'       => otl_empty_counts()
		));
		exit;
	}

	/*
	/* ---- 6 of 6: WHO TO CHASE ----
	/*
	/* THE INVERSION, through the shared helper. A call with an EMPTY entry is
	/* the no_boss case and the most important row in the lane: nobody can
	/* submit for it, so nothing is coming on its own and Rich has to ring
	/* somebody who was on the call. It is not an edge case and it is never
	/* folded into "not submitted".
	*/

	$inversion = goat_bosses_by_call(array_keys($callInfo));

	if (!$inversion['ok'])
	{
		goat_json_error(500, $inversion['error']);
	}

	$bossOf = $inversion['by_call'];

	/* names and mobiles for every boss found, in one query */

	$bossIDs = array();

	foreach ($bossOf as $cid => $whoMap)
	{
		foreach ($whoMap as $uid => $how)
		{
			$bossIDs[$uid] = true;
		}
	}

	$userOf = array();

	if (count($bossIDs) > 0)
	{
		$uList = implode(',', array_map('intval', array_keys($bossIDs)));

		$uRes = mysql_query("SELECT id, firstname, lastname, mobile, phone
		                     FROM users WHERE id IN (" . $uList . ")");

		/*
		/* A FAILED NAME LOOKUP IS A 500. The row's purpose is "ring this
		/* person": a lane that dropped the name and mobile would render every
		/* call as though nobody were responsible, which is the one thing this
		/* lane must never say by accident.
		*/

		if ($uRes === false)
		{
			goat_json_error(500, 'boss lookup failed: ' . mysql_error());
		}

		while ($urow = mysql_fetch_object($uRes))
		{
			$userOf[(int) $urow->id] = $urow;
		}
	}

	/*
	/* ---- ASSEMBLE ----
	/*
	/* Bosses are ordered by HOW first — direct, then container, then
	/* supervisory — so the row's first name is the most specific answer to
	/* "who is responsible", and by name within a rank so two runs over
	/* unchanged data produce identical output.
	*/

	$rank = array('direct' => 3, 'container' => 2, 'supervisory' => 1);

	$rows = array();

	$c_status = array('awaiting_review' => 0, 'not_submitted' => 0, 'no_boss' => 0);
	$c_age    = array('under24' => 0, 'from24to72' => 0, 'over72' => 0);

	foreach ($callInfo as $cid => $info)
	{
		$bosses = array();

		if (isset($bossOf[$cid]))
		{
			foreach ($bossOf[$cid] as $uid => $how)
			{
				if (!isset($userOf[$uid]))
				{
					continue;   /* boss row vanished between queries */
				}

				$c = goat_contact_from_user($userOf[$uid], $how, '');

				$bosses[] = array(
					'user_id' => (int) $uid,
					'name'    => $c['name'],
					'mobile'  => $c['mobile'],
					'how'     => $how,
					'_rank'   => $rank[$how]
				);
			}
		}

		usort($bosses, 'otl_by_rank_then_name');

		foreach ($bosses as $i => $b)
		{
			unset($bosses[$i]['_rank']);
		}

		/*
		/* STATUS — three segments, and the order of these tests is the whole
		/* meaning. Anything submitted is awaiting_review even when only some
		/* of the crew are covered: there is something on the screen to accept,
		/* and accepting is reviewing (decision, 27 Aug). Otherwise a call with
		/* nobody to submit for it is no_boss, and only then is it a boss who
		/* has not got round to it.
		*/

		$subCount = isset($submittedOf[$cid]) ? count($submittedOf[$cid]) : 0;

		if ($subCount > 0)          $status = 'awaiting_review';
		else if (count($bosses) === 0) $status = 'no_boss';
		else                        $status = 'not_submitted';

		/*
		/* AGE from the call's scheduled END, in the same buckets the other
		/* lanes use (24h / 72h), so a bucket means the same thing on every
		/* lane of the dashboard.
		*/

		$age_secs = $now - (int) $info['end_unix'];

		if ($age_secs < 86400)       $age_bucket = 'under24';
		else if ($age_secs < 259200) $age_bucket = 'from24to72';
		else                         $age_bucket = 'over72';

		/* the sibling condition — see 2 of 6 */

		$sibPending = 0;

		if (isset($siblingsOf[$cid]))
		{
			foreach ($siblingsOf[$cid] as $sid => $ignored)
			{
				if (otl_needing($sid, $confirmedOf, $keyedOf, $submittedOf) > 0)
				{
					$sibPending++;
				}
			}
		}

		$confirmed = isset($confirmedOf[$cid]) ? count($confirmedOf[$cid]) : 0;

		$rows[] = array(
			'call_id'      => $info['call_id'],
			'booking_id'   => $info['booking_id'],
			'booking_name' => $info['booking_name'],
			'call_name'    => $info['call_name'],
			'venue'        => $info['venue'],
			'start'        => $info['start'],
			'end'          => $info['end'],
			'confirmed'    => $confirmed,
			'needing'      => (int) $info['needing'],
			'submitted'    => $subCount,
			'status'       => $status,
			'age_bucket'   => $age_bucket,
			'bosses'       => array_values($bosses),
			'sibling_times_pending' => ($sibPending > 0),
			'sibling_pending_count' => $sibPending
		);

		$c_status[$status]++;
		$c_age[$age_bucket]++;
	}

	usort($rows, 'otl_by_end');

	/*
	/* IDENTITIES the smoke test asserts:
	/*   total = sum(status) = sum(age)   — each row lands in exactly one of each
	/* `needing` deliberately does NOT sum to anything here: it counts people,
	/* the badge counts calls, and conflating them is how a badge stops meaning
	/* "rows to work through".
	*/

	echo json_encode(array(
		'ok'           => true,
		'generated_at' => date('Y-m-d\TH:i:s', $now),
		'window'       => array('start' => $start_raw, 'end' => $end_raw),
		'calls'        => $rows,
		'counts'       => array(
			'total'  => count($rows),
			'status' => $c_status,
			'age'    => $c_age
		)
	));

	function otl_by_rank_then_name($a, $b)
	{
		if ($a['_rank'] !== $b['_rank'])
		{
			return ($a['_rank'] > $b['_rank']) ? -1 : 1;
		}

		return strcasecmp($a['name'], $b['name']);
	}

	/* oldest first — the call that finished longest ago is the one going cold */

	function otl_by_end($a, $b)
	{
		if ($a['end'] === $b['end'])
		{
			return ($a['call_id'] < $b['call_id']) ? -1 : 1;
		}

		return ($a['end'] < $b['end']) ? -1 : 1;
	}

?>
