<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');
	include_once('supervision-graph.php');
	include_once('time-submission-graph.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* THE INVERSION — "given a window of calls, which bosses owe times on
	/* them". Called by the Vercel time-entry-prompts cron, never by a browser.
	/*
	/* WHY THIS IS A NEW ENDPOINT AND NOT A COMPOSITION OF THE EXISTING ONES.
	/* Every helper in this estate is keyed on a userID —
	/* goat_boss_scope($userID), goat_boss_calls_for_user($userID) — because
	/* every surface so far has been a crew member asking about themselves. The
	/* cron asks the opposite question and there is no boss list to loop over
	/* until this endpoint has answered it. Looping my-boss-calls.php per boss
	/* would be serial against SmartStaff's per-session file lock inside a
	/* 60-second cron ceiling. This is an inversion, not a composition.
	/*
	/* THE ENDPOINT RETURNS DATA. THE CRON DECIDES THE STAGE. Nothing here
	/* knows about 12 hours, 24 hours or 09:00 Tuesday. That policy — and with
	/* it Melbourne DST, which PHP on this box and the Node cron would each
	/* answer their own way — lives in one place, in TypeScript, beside the
	/* three crons that already exist. Adding "is this due?" here would be the
	/* second implementation of a rule that has to agree with itself.
	/*
	/* SERVICE KEY ONLY. No session path and no goat_acting_user_id(): there is
	/* no acting user. A cron is not a person, and there is no userID it could
	/* honestly assert. get-open-offers-bulk.php accepts either; this accepts
	/* one, because the browser case does not exist.
	/*
	/* PRIVACY — THIS RETURNS NO CREW. Not a name, not a mobile, not an EIN
	/* other than the boss's own. The cron needs to know WHO to notify and HOW
	/* MANY are outstanding; it does not need to know who they are, and the
	/* push it sends says "4 crew still to enter", never a list. If you are
	/* adding a column here, the question is whether a notification body could
	/* justify it.
	/*
	/* A FAILED LOOKUP IS A 500, NEVER AN EMPTY SET. An empty `bosses` array
	/* means "nobody is owed anything", and the cron acts on that by staying
	/* silent. A broken query that degrades to empty would look identical and
	/* would silently stop the whole feature — no error, no push, no signal.
	/* Same rule as call-supervision.php, the opposite of get-booking.php.
	/*
	/* PHP 5.x — mysql_*, no ??, no short array syntax.
	*/

	/*
	/* ---- AUTH ----
	*/

	$goat_key = isset($_SERVER['HTTP_X_GOAT_SERVICE_KEY'])
	          ? $_SERVER['HTTP_X_GOAT_SERVICE_KEY'] : '';

	if (!goat_service_key_ok($goat_key))
	{
		http_response_code(403);
		die('{"error":"service key required"}');
	}

	/*
	/* ---- WINDOW ----
	/*
	/* since and until are UNIX TIMESTAMPS, both required, and they bound a
	/* call's scheduled END rather than its start — a call is owed times when
	/* it has finished, and its start tells you nothing about when that was.
	/*
	/* THE 7-DAY CAP IS A POLICY, NOT A PERFORMANCE GUARD. A call older than a
	/* week stops prompting (decision 7), so the caller must not be able to ask
	/* for the archive and reawaken a boss's whole history. 422 rather than a
	/* silent clamp: a cron asking for more than it should is a bug in the
	/* cron, and quietly answering a different question hides it.
	*/

	$since_raw = isset($_GET['since']) ? $_GET['since'] : '';
	$until_raw = isset($_GET['until']) ? $_GET['until'] : '';

	if (!preg_match('/^\d{1,11}$/', (string) $since_raw) ||
	    !preg_match('/^\d{1,11}$/', (string) $until_raw))
	{
		http_response_code(400);
		die('{"error":"since and until are required unix timestamps"}');
	}

	$since = (int) $since_raw;
	$until = (int) $until_raw;

	if ($since <= 0 || $until <= $since)
	{
		http_response_code(400);
		die('{"error":"invalid window"}');
	}

	if (($until - $since) > (7 * 86400))
	{
		http_response_code(422);
		die('{"error":"window exceeds 7 days"}');
	}

	/*
	/* ---- 1 of 5: CANDIDATE CALLS ----
	/*
	/* SQL PREFILTERS COARSELY ON start_date; PHP FILTERS EXACTLY ON THE END.
	/*
	/* The end of a call is start_date + start_time + est_length, and
	/* start_date is a unix timestamp at LOCAL MIDNIGHT while start_time is a
	/* separate TIME column. Adding TIME_TO_SEC() to that midnight in SQL is
	/* correct on 363 days a year and an hour wrong on the two Melbourne DST
	/* transitions, because it adds clock seconds to an absolute instant.
	/* goat_call_window() does the same arithmetic through strtotime(), which
	/* knows about the transition — so the exact test runs there, in PHP, and
	/* SQL only narrows the rows. It is also the one implementation of this
	/* sum: if the est_length convention ever changes, this endpoint follows
	/* without being edited.
	/*
	/* THE BACK-MARGIN. A call ending inside the window may have STARTED well
	/* before it, so the prefilter reaches back a further 7 days. A call longer
	/* than that would be missed; nothing in the data comes close, and the
	/* alternative — no lower bound at all — scans `calls` to 2009.
	*/

	$scan_from = $since - (7 * 86400);

	$sql = "
		SELECT
			c.id          AS call_id,
			c.call_name   AS call_name,
			c.start_date  AS start_date,
			c.start_time  AS start_time,
			c.est_length  AS est_length,
			c.bookingID   AS booking_id,
			b.name        AS booking_name,
			v.venue       AS venue_name
		FROM calls c
		LEFT JOIN bookings b ON b.id = c.bookingID
		LEFT JOIN venues   v ON v.id = b.venueID
		WHERE c.start_date >= $scan_from
		  AND c.start_date <= $until
		ORDER BY c.start_date ASC, c.start_time ASC
	";

	$result = mysql_query($sql);

	if ($result === false)
	{
		http_response_code(500);
		die(json_encode(array('error' => 'call query failed: ' . mysql_error())));
	}

	$callInfo = array();   /* callID -> assembled call row */
	$isBossCall = array(); /* callID -> bool, the name test, run once */

	while ($row = mysql_fetch_object($result))
	{
		$win = goat_call_window($row);

		if ($win['end'] < $since || $win['end'] >= $until)
		{
			continue;   /* ends outside the window */
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
		);

		$isBossCall[$cid] = goat_is_boss_call_name($row->call_name);
	}

	if (count($callInfo) === 0)
	{
		echo json_encode(array('ok' => true, 'bosses' => array()));
		exit;
	}

	/*
	/* ---- 2 of 5: WHO STILL OWES ----
	/*
	/* ONE CALL over the whole window, never one per call — the helper takes an
	/* array for exactly this reason. Calls with nothing outstanding are
	/* dropped here, before any boss is resolved, so a fully-entered call costs
	/* nothing downstream and can never produce a prompt.
	*/

	$outstanding = goat_outstanding_by_call(array_keys($callInfo));

	foreach ($callInfo as $cid => $ignored)
	{
		$n = isset($outstanding[$cid]) ? (int) $outstanding[$cid] : 0;

		if ($n <= 0)
		{
			unset($callInfo[$cid]);
			unset($isBossCall[$cid]);
			continue;
		}

		$callInfo[$cid]['outstanding'] = $n;
	}

	if (count($callInfo) === 0)
	{
		echo json_encode(array('ok' => true, 'bosses' => array()));
		exit;
	}

	$owedList = implode(',', array_map('intval', array_keys($callInfo)));

	/*
	/* ---- 3 of 5: DIRECT AND CONTAINER ----
	/*
	/* One query serves both branches, because both ask the same thing of the
	/* same table — every confirmed row on the owed calls — and differ only in
	/* what makes the row count:
	/*
	/*   DIRECT    — the row is flagged is_call_boss.
	/*   CONTAINER — the CALL is a dedicated boss call, and the row is anyone
	/*               confirmed on it. Q4: scope belongs to every confirmed
	/*               resource on a boss call, not only the flagged one, which
	/*               on test is 18 of 22 containers.
	/*
	/* is_call_boss IS binary(50) — SELECTED and cast in PHP, NEVER compared
	/* with "= 1" in SQL. makeboss writes the STRING '1', stored as byte 0x31
	/* null-padded to 50 bytes.
	*/

	$holders = array();   /* userID -> callID -> how */

	function caw_claim(&$holders, $userID, $callID, $how)
	{
		/*
		/* PRECEDENCE: direct beats container beats supervisory. A boss
		/* reachable two ways is ONE entry with the most specific reason — the
		/* field exists to answer "why did this person get prompted", and the
		/* weaker answer is the less useful one. Test 6 is the assertion that
		/* this collapses rather than duplicating.
		*/

		$rank = array('direct' => 3, 'container' => 2, 'supervisory' => 1);

		if (!isset($holders[$userID]))
		{
			$holders[$userID] = array();
		}

		if (!isset($holders[$userID][$callID])
		    || $rank[$how] > $rank[$holders[$userID][$callID]])
		{
			$holders[$userID][$callID] = $how;
		}
	}

	$crewSql = "
		SELECT ccm.callID AS call_id, ccm.userID AS user_id,
		       ccm.is_call_boss AS is_call_boss
		FROM call_crew_map ccm
		WHERE ccm.callID IN ($owedList)
		  AND ccm.status = 5
	";

	$crewRes = mysql_query($crewSql);

	if ($crewRes === false)
	{
		http_response_code(500);
		die(json_encode(array('error' => 'crew query failed: ' . mysql_error())));
	}

	while ($crow = mysql_fetch_object($crewRes))
	{
		$cid = (int) $crow->call_id;
		$uid = (int) $crow->user_id;

		if ($uid <= 0 || !isset($callInfo[$cid]))
		{
			continue;
		}

		if ((int) $crow->is_call_boss === 1)
		{
			caw_claim($holders, $uid, $cid, 'direct');
		}

		if ($isBossCall[$cid])
		{
			caw_claim($holders, $uid, $cid, 'container');
		}
	}

	/*
	/* ---- 4 of 5: SUPERVISORY ----
	/*
	/* Two queries, never one per call. First every supervision edge whose
	/* CHILD is an owed call, then the confirmed roster of the boss calls those
	/* edges point at.
	/*
	/* The boss call itself is usually OUTSIDE the window — a Crew Boss call
	/* runs in the morning and the load-out it supervises finishes at midnight
	/* — so it is looked up by id rather than found among the candidates.
	/*
	/* INNER JOIN calls ON the boss call, matching every other traversal of
	/* this table: an edge left dangling by a deleted call resolves to nothing
	/* rather than to a boss who does not exist. sss::deleteCall does not know
	/* call_supervision exists.
	*/

	$edgeSql = "
		SELECT s.boss_call AS boss_call, s.child_call AS child_call
		FROM call_supervision s
		INNER JOIN calls cb ON cb.id = s.boss_call
		WHERE s.child_call IN ($owedList)
	";

	$edgeRes = mysql_query($edgeSql);

	if ($edgeRes === false)
	{
		http_response_code(500);
		die(json_encode(array('error' => 'supervision query failed: ' . mysql_error())));
	}

	$childrenOf = array();   /* bossCallID -> [childCallID] */

	while ($erow = mysql_fetch_object($edgeRes))
	{
		$bc = (int) $erow->boss_call;
		$cc = (int) $erow->child_call;

		if ($bc <= 0 || !isset($callInfo[$cc]))
		{
			continue;
		}

		if (!isset($childrenOf[$bc]))
		{
			$childrenOf[$bc] = array();
		}

		$childrenOf[$bc][] = $cc;
	}

	if (count($childrenOf) > 0)
	{
		$bossList = implode(',', array_map('intval', array_keys($childrenOf)));

		$bossCrewSql = "
			SELECT ccm.callID AS call_id, ccm.userID AS user_id
			FROM call_crew_map ccm
			WHERE ccm.callID IN ($bossList)
			  AND ccm.status = 5
		";

		$bossCrewRes = mysql_query($bossCrewSql);

		if ($bossCrewRes === false)
		{
			http_response_code(500);
			die(json_encode(array('error' => 'boss roster query failed: ' . mysql_error())));
		}

		while ($brow = mysql_fetch_object($bossCrewRes))
		{
			$bc  = (int) $brow->call_id;
			$uid = (int) $brow->user_id;

			if ($uid <= 0 || !isset($childrenOf[$bc]))
			{
				continue;
			}

			/*
			/* NO is_call_boss TEST, and the asymmetry is Q4 again: every
			/* confirmed resource on a dedicated boss call holds its children,
			/* not only the nominated one. Nomination governs who the crew
			/* RING, not who is responsible for the times.
			*/

			foreach ($childrenOf[$bc] as $cc)
			{
				caw_claim($holders, $uid, $cc, 'supervisory');
			}
		}
	}

	if (count($holders) === 0)
	{
		echo json_encode(array('ok' => true, 'bosses' => array()));
		exit;
	}

	/*
	/* ---- 5 of 5: EIN ----
	/*
	/* REQUIRED, NOT OPTIONAL. push_subscriptions and sendPushToEin are keyed
	/* on EIN; a userID reaches nobody. One query for every boss found.
	/*
	/* EIN IS NOT THE userID AND NEVER HAS BEEN — v3.4.10, EIN 5925 resolves to
	/* userID 9734. Anything that treats them as interchangeable notifies the
	/* wrong person, which here means telling a stranger how many of someone
	/* else's crew are outstanding.
	/*
	/* A BOSS WITH NO EIN IS SKIPPED AND LOGGED. They cannot be pushed to, so
	/* emitting them would hand the cron a row it can only drop — and a silent
	/* drop is how "why did nobody get told" becomes unanswerable. Per Mike,
	/* 26 Aug: every engaged worker has an EIN, so this is a data fault worth
	/* seeing rather than a normal case.
	*/

	$userList = implode(',', array_map('intval', array_keys($holders)));

	$einRes = mysql_query("SELECT id, ein FROM users WHERE id IN ($userList)");

	if ($einRes === false)
	{
		http_response_code(500);
		die(json_encode(array('error' => 'ein query failed: ' . mysql_error())));
	}

	$einOf = array();

	while ($urow = mysql_fetch_object($einRes))
	{
		$einOf[(int) $urow->id] = (int) $urow->ein;
	}

	/*
	/* ---- ASSEMBLE ----
	/*
	/* Ordered by user_id, and each boss's calls by start, so two runs over
	/* unchanged data produce byte-identical output. A cron diffing its own
	/* results should never see churn that is really just hash ordering.
	*/

	$bosses = array();

	ksort($holders);

	foreach ($holders as $uid => $callMap)
	{
		$ein = isset($einOf[$uid]) ? $einOf[$uid] : 0;

		if ($ein <= 0)
		{
			error_log('calls-awaiting-times: userID ' . $uid
			          . ' holds ' . count($callMap)
			          . ' call(s) awaiting times but has no EIN — skipped');
			continue;
		}

		$calls = array();

		foreach ($callMap as $cid => $how)
		{
			$entry = $callInfo[$cid];
			$entry['how'] = $how;
			$calls[] = $entry;
		}

		usort($calls, 'caw_by_start');

		$bosses[] = array(
			'user_id' => $uid,
			'ein'     => $ein,
			'calls'   => $calls,
		);
	}

	echo json_encode(array('ok' => true, 'bosses' => $bosses));

	function caw_by_start($a, $b)
	{
		if ($a['start'] === $b['start'])
		{
			return $a['call_id'] < $b['call_id'] ? -1 : 1;
		}

		return ($a['start'] < $b['start']) ? -1 : 1;
	}

?>
