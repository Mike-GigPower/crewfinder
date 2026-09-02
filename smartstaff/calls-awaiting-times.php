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
	/* ---- 1 of 4: CANDIDATE CALLS ----
	/*
	/* CANCELLED CALLS ARE EXCLUDED -- as DEFENCE IN DEPTH, not as the only
	/* guard, and the difference matters to anyone changing this later.
	/*
	/* goat_outstanding_by_call() already counts `ccm.status = 5` only. Every row
	/* on a cancelled call is status 9, so it finds no confirmed crew, the call
	/* is dropped at step 2 below, and no boss is ever resolved or pushed. That
	/* protection is INCIDENTAL, though: it holds only while that helper's
	/* predicate stays status = 5. Widen it to include backups and it vanishes
	/* silently, with no error and no signal. This clause states the intent
	/* directly, and drops the row before three further queries run on it.
	/*
	/* No capability guard, unlike get-calls-bulk.php: the file rule above is
	/* that a failed lookup is a 500, never an empty set. A missing column here
	/* SHOULD break loudly rather than degrade to "nobody is owed anything".
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
		  AND c.cancelled_at IS NULL
		ORDER BY c.start_date ASC, c.start_time ASC
	";

	$result = mysql_query($sql);

	if ($result === false)
	{
		http_response_code(500);
		die(json_encode(array('error' => 'call query failed: ' . mysql_error())));
	}

	$callInfo = array();   /* callID -> assembled call row */

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

	}

	if (count($callInfo) === 0)
	{
		echo json_encode(array('ok' => true, 'bosses' => array()));
		exit;
	}

	/*
	/* ---- 2 of 4: WHO STILL OWES ----
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
			continue;
		}

		$callInfo[$cid]['outstanding'] = $n;
	}

	if (count($callInfo) === 0)
	{
		echo json_encode(array('ok' => true, 'bosses' => array()));
		exit;
	}

	/*
	/* ---- 3 of 4: WHO IS THE BOSS ----
	/*
	/* THE INVERSION ITSELF LIVES IN supervision-graph.php, and this endpoint
	/* is one of its two readers — the Ops "Times outstanding" lane is the
	/* other. It used to be written out here in two steps; it was lifted the
	/* day the lane needed the same answer, because a second copy of "who is
	/* the boss of this call" is how the five supervision-blindness instances
	/* happened. What it does — direct, container, supervisory, precedence
	/* direct > container > supervisory, is_call_boss cast in PHP — is
	/* unchanged and documented there.
	/*
	/* THE TRANSPOSE. The helper answers callID -> bosses, because a call is
	/* what the Ops lane draws a row for. The cron needs the opposite index,
	/* boss -> calls, because a boss is who it pushes to. One nested loop,
	/* here, rather than a second traversal of the graph.
	/*
	/* ok === false IS A 500, NOT AN EMPTY SET — the file rule at the top,
	/* applied to a helper that reports its own failures rather than
	/* degrading. An empty bosses list means "nobody is owed anything"; a
	/* broken query must never be able to say that.
	*/

	$inversion = goat_bosses_by_call(array_keys($callInfo));

	if (!$inversion['ok'])
	{
		http_response_code(500);
		die(json_encode(array('error' => $inversion['error'])));
	}

	$holders = array();   /* userID -> callID -> how */

	foreach ($inversion['by_call'] as $cid => $whoMap)
	{
		foreach ($whoMap as $uid => $how)
		{
			if (!isset($holders[$uid]))
			{
				$holders[$uid] = array();
			}

			$holders[$uid][$cid] = $how;
		}
	}

	if (count($holders) === 0)
	{
		echo json_encode(array('ok' => true, 'bosses' => array()));
		exit;
	}

	/*
	/* ---- 4 of 4: EIN ----
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
