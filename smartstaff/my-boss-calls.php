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
	/* SELF-SCOPED SUPERVISORY READ — "the calls I am the boss of, and who is
	/* on them". Called by Crew Hub through the Edge Function.
	/*
	/* THIS IS THE FIRST ENDPOINT IN THE ESTATE THAT RETURNS ANOTHER CREW
	/* MEMBER'S DATA TO A NON-ADMIN. Read the privacy rules below before
	/* adding a single column to either query.
	/*
	/* WHY goat_acting_user_id() AND NOT THE SESSION DIRECTLY. Slice B
	/* (grant-supervision) deliberately reads $_SESSION[SITE_KEY]['userID'] and
	/* refuses this helper, because it records created_by for an admin
	/* maintenance action and a service-key caller naming its own userID would
	/* hollow out that audit. Slice E is the opposite trust model: a crew-facing
	/* self-scoped READ where the service key IS the trust anchor — the Crew Hub
	/* backend has already authenticated the crew member and asserts their
	/* userID, exactly as it does for my-shifts.php, my-call-offers.php and
	/* my-backups.php. The helper also owns the 400 and 401 paths, which is why
	/* none of those endpoints hand-roll them.
	/*
	/* TWO TOP-LEVEL GROUPS, AND WHY ONE SHAPE WILL NOT DO:
	/*
	/*   boss_calls   — "I am running this job from a dedicated boss call".
	/*                  They carry `children` AND, since 27 Aug 2026, their own
	/*                  roster: a boss call is a real call with real people
	/*                  confirmed on it, not only a grouping device.
	/*   direct_calls — "I am working this call and happen to be the nominated
	/*                  boss on it". Rich's Pro Stage factory case: five crew,
	/*                  one in charge, no boss call above them. Confirmed as the
	/*                  MORE COMMON case, not an edge case.
	/*
	/* Synthesising a fake boss call so everything fits one shape was considered
	/* and rejected: tidier in the response, a lie in the data, and Crew Hub
	/* would only have to undo it to render sensibly.
	/*
	/* MEMBERSHIP RULE — NO CALL APPEARS IN BOTH GROUPS. A call the viewer is
	/* flagged on lands in direct_calls unless it has ALREADY been emitted in
	/* boss_calls, as a container or as a child.
	/*
	/* The brief's original rule keyed on goat_supervision_boss_call() === 0
	/* instead, and lost calls: one supervised by a boss call the viewer is NOT
	/* on rendered nowhere at all, despite sitting in their goat_boss_scope().
	/* The flagged boss is the person who must not lose it — the overarching
	/* call boss may not be on duty when the call runs. Full reasoning, and the
	/* test that caught it, at the direct_calls assembly below.
	/*
	/* EMPTY SCOPE IS 200 WITH EMPTY ARRAYS, NEVER 403. An ordinary crew member
	/* calling this is doing nothing wrong; they simply have no scope. A 403
	/* would make Crew Hub's tile logic fight an error path for the normal case.
	/*
	/* PHP 5.x — mysql_*, no ??, no short array syntax.
	*/


	$userID = goat_acting_user_id();

	/*
	/* validate input — start, end: YYYY-MM-DD (inclusive start, exclusive end)
	/*
	/* Lifted from my-shifts.php lines 42-70 rather than reinvented. The parent
	/* brief's hardcoded -1/+14 day window is deliberately NOT used: the client
	/* chooses the window, the server caps it.
	*/

	$start_raw = isset($_GET['start']) ? $_GET['start'] : '';
	$end_raw   = isset($_GET['end'])   ? $_GET['end']   : '';

	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_raw) ||
	    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_raw))
	{
		goat_json_error(400, 'start and end must be YYYY-MM-DD');
	}

	$start_ts = strtotime($start_raw . ' 00:00:00');
	$end_ts   = strtotime($end_raw   . ' 00:00:00');

	if ($start_ts === false || $end_ts === false || $end_ts <= $start_ts)
	{
		goat_json_error(400, 'invalid date range');
	}

	/* cap the window at 120 days to protect the DB */

	if (($end_ts - $start_ts) > (120 * 86400))
	{
		goat_json_error(400, 'window exceeds 120 days');
	}

	/*
	/* DIVERGENCE FROM my-shifts.php, AND IT IS DELIBERATE. That endpoint
	/* windows `calendars`, whose start/end are DATETIME, so it hands $db->sc()
	/* a 'Y-m-d H:i:s' string. This endpoint windows `calls`, whose start_date
	/* is an INT unix timestamp at LOCAL MIDNIGHT (verified in
	/* get-calls-bulk.php). Comparing an INT column against a quoted datetime
	/* string is not a style choice, it is a wrong answer — so the bounds are
	/* carried as ints, which is also the get-calls-bulk.php idiom. No $db->sc()
	/* is needed because nothing interpolated below is a string.
	*/

	$start_i = (int) $start_ts;
	$end_i   = (int) $end_ts;

	/*
	/* SCOPE.
	/*
	/*   $scope    — the calls this user may ACT ON (direct + supervisory).
	/*   $bossCall — this user's own DEDICATED boss calls, the grouping
	/*               containers. Separate on purpose: a boss call reaches
	/*               $scope only if the user also happens to be flagged on it.
	/*
	/* goat_boss_scope() is unwindowed BY DESIGN (slice A 4.6). The window is
	/* applied here, in query 1 — filter there or a boss with three years of
	/* history pulls three years of rosters.
	*/

	$scope    = goat_boss_scope($userID);
	$bossCall = goat_boss_calls_for_user($userID);

	/* keyed for O(1) membership tests further down */

	$scopeSet = array();
	$bossSet  = array();

	foreach ($scope    as $cid) { $scopeSet[(int) $cid] = true; }
	foreach ($bossCall as $cid) { $bossSet[(int) $cid]  = true; }

	$wantIDs = array_keys($scopeSet + $bossSet);

	if (count($wantIDs) === 0)
	{
		/* no scope — a perfectly ordinary crew member. 200, not 403. */

		echo json_encode(array(
			'ok'           => true,
			'window'       => array('start' => $start_raw, 'end' => $end_raw),
			'boss_calls'   => array(),
			'direct_calls' => array(),
		));

		exit;
	}

	$idList = implode(',', array_map('intval', $wantIDs));

	/*
	/* QUERY 1 of 3 — the calls themselves, windowed, with booking and venue
	/* context. One query over scope UNION boss calls, never one per call.
	/*
	/* viewer_is_boss is read by a CORRELATED SUBQUERY keyed on
	/* (userID, callID) — the existing index — rather than by joining
	/* call_crew_map, which would multiply call rows if a (callID, userID) pair
	/* ever carried more than one row. MyISAM enforces nothing here, and
	/* silently duplicating a boss's job list is a worse failure than one extra
	/* indexed lookup. Same reasoning as my-shifts.php.
	/*
	/* is_call_boss is binary(50) — SELECTED and cast in PHP, NEVER compared
	/* with "= 1" in SQL. makeboss writes the STRING '1', stored as byte 0x31
	/* null-padded to 50 bytes.
	/*
	/* PRIVACY: no callrate*, no callchargeout*, no paygrade, no EIN. Nothing
	/* from the crew profile. If you are adding a column here, it needs to
	/* survive the question "would Rich read this down the phone to a stranger?"
	*/

	$sql = "
		SELECT
			c.id          AS call_id,
			c.call_name   AS call_name,
			c.start_date  AS start_date,
			c.start_time  AS start_time,
			c.est_length  AS est_length,
			c.required    AS required,
			c.bookingID   AS booking_id,
			b.name        AS booking_name,
			v.venue       AS venue_name,
			(
			  SELECT ccb.is_call_boss
			  FROM call_crew_map ccb
			  WHERE ccb.callID = c.id
			    AND ccb.userID = $userID
			    AND ccb.status = 5
			  LIMIT 1
			)             AS viewer_is_boss
		FROM calls c
		LEFT JOIN bookings b ON b.id = c.bookingID
		LEFT JOIN venues   v ON v.id = b.venueID
		WHERE c.id IN ($idList)
		  AND c.start_date >= $start_i
		  AND c.start_date <  $end_i
		ORDER BY c.start_date ASC, c.start_time ASC
	";

	$result = mysql_query($sql);

	/*
	/* THIS ENDPOINT ASSERTS, IT DOES NOT DEGRADE. A failed query must never
	/* fall through to an empty roster: "nobody is confirmed on this call" and
	/* "the query broke" are indistinguishable to a boss standing in a loading
	/* dock at 6am. 500, unlike get-booking.php which displays.
	*/

	if ($result === false)
	{
		goat_json_error(500, 'call query failed: ' . mysql_error());
	}

	$callInfo = array();   /* callID -> assembled call row */
	$order    = array();   /* callIDs in query order, for stable output */

	while ($row = mysql_fetch_object($result))
	{
		$cid = (int) $row->call_id;

		/*
		/* goat_call_window() is the shared arithmetic over
		/* (start_date, start_time, est_length) — reached through
		/* supervision-graph.php, which include_once's
		/* resolve-call-contact.php. Reusing it keeps this endpoint honest if
		/* the est_length convention ever changes.
		*/

		$win = goat_call_window($row);

		$callInfo[$cid] = array(
			'call_id'      => $cid,
			'call_name'    => html_entity_decode((string) $row->call_name, ENT_QUOTES),
			'start'        => date('Y-m-d H:i', $win['start']),
			'end'          => date('Y-m-d H:i', $win['end']),
			'required'     => (int) $row->required,
			'booking_id'   => (int) $row->booking_id,
			'booking_name' => html_entity_decode((string) $row->booking_name, ENT_QUOTES),
			'venue'        => html_entity_decode((string) $row->venue_name, ENT_QUOTES),

			/*
			/* Is the VIEWER the flagged point of contact on this call? Q19 —
			/* it exists so Crew Hub can show a quiet line to a boss who is not
			/* the listed contact, otherwise they will never understand why the
			/* crew are ringing a colleague. Always emitted, so the client can
			/* tell "not nominated" from "old PHP that never sent the field".
			*/

			'is_nominated' => ((int) $row->viewer_is_boss === 1),
		);

		$order[] = $cid;
	}

	/*
	/* QUERY 2 of 3 — the rosters. ONE query over every SCOPE call that
	/* survived the window, grouped into callID -> [crew] in PHP. Boss calls
	/* are containers and get no roster of their own, so they are excluded
	/* unless they are also in scope.
	/*
	/* CONFIRMED AND STANDBY (status IN (5, 7)) — CHANGED 27 Aug 2026.
	/*
	/* Slice E scoped this to "who is coming, not who might", which was right
	/* for a read-only visibility page and is wrong now. A boss whose crew
	/* member has not turned up needs the standby list at exactly that moment,
	/* and it is the one moment they cannot go and ask Ops. my-call-crew.php
	/* has always included standby for that reason; this now matches it.
	/*
	/* Each row carries its own status so the two are never confused —
	/* ringing a standby thinking they are late is worse than not seeing them.
	/*
	/* `confirmed` STILL COUNTS status 5 ONLY. It is compared against
	/* `required` ("28 of 30"), so folding standby into it would say a call is
	/* staffed when it is not. `standby` is a separate count.
	/*
	/* MOBILES (Q13). Rich confirmed that bosses ring crew who are running
	/* late, and this is the whole reason the column is here. It is scoped as
	/* tightly as it can be: crew CONFIRMED on calls CURRENTLY IN THE VIEWER'S
	/* SCOPE, inside the requested window, and nowhere else. No search, no
	/* export, no history.
	*/

	/*
	/* EVERY windowed call, containers included — CHANGED 27 Aug 2026.
	/*
	/* This used to be scope-only, because a boss call was a pure grouping
	/* container with no roster of its own. It now carries one: a dedicated
	/* boss call has confirmed crew like any other call, and a boss looking at
	/* "Crew Boss 1 of 1" needs to see who that is.
	/*
	/* $order is already scope UNION boss calls, windowed by query 1, so this
	/* is the whole set without recomputing it.
	*/

	$rosterIDs = $order;

	$crewByCall = array();

	if (count($rosterIDs) > 0)
	{
		$rosterList = implode(',', array_map('intval', $rosterIDs));

		$crewSql = "
			SELECT
				ccm.callID   AS call_id,
				u.id         AS user_id,
				u.firstname  AS firstname,
				u.lastname   AS lastname,
				u.mobile     AS mobile,
				ccm.status   AS status,
				ccm.is_call_boss AS is_call_boss
			FROM call_crew_map ccm
			INNER JOIN users u ON u.id = ccm.userID
			WHERE ccm.callID IN ($rosterList)
			  AND ccm.status IN (5, 7)
			ORDER BY ccm.is_call_boss DESC, ccm.status ASC,
			         u.firstname ASC, u.lastname ASC
		";

		$crewRes = mysql_query($crewSql);

		if ($crewRes === false)
		{
			goat_json_error(500, 'crew query failed: ' . mysql_error());
		}

		while ($crow = mysql_fetch_object($crewRes))
		{
			$ccid = (int) $crow->call_id;

			if (!isset($crewByCall[$ccid]))
			{
				$crewByCall[$ccid] = array();
			}

			/*
			/* NAMES ARE DECODED, UNCONDITIONALLY. `users` stores some names
			/* pre-encoded and some not — 9734 carries a literal apostrophe
			/* while other rows carry &#39; — so both paths are live. This
			/* matches goat_contact_from_user(), which also decodes
			/* unconditionally. Skip it and O'Brien renders as O&#39;Brien.
			*/

			$first = html_entity_decode((string) $crow->firstname, ENT_QUOTES);
			$last  = html_entity_decode((string) $crow->lastname,  ENT_QUOTES);

			/*
			/* 5 -> confirmed, ANYTHING ELSE -> standby. Mapped exactly as
			/* my-call-crew.php does; there is no third value, and inventing
			/* one would give the client a state it has no rendering for.
			*/

			/*
			/* is_call_boss IS EMITTED — ADDED 27 Aug 2026. It has been in the
			/* SELECT since the ordering changed and was never output, so the
			/* boss badge on a crew row has never had anything to render from.
			/*
			/* binary(50): cast in PHP, never compared with "= 1" in SQL.
			/* makeboss writes the STRING '1', stored as byte 0x31 null-padded
			/* to 50 bytes.
			*/

			$crewByCall[$ccid][] = array(
				'user_id'      => (int) $crow->user_id,
				'name'         => trim(trim($first) . ' ' . trim($last)),
				'mobile'       => ($crow->mobile === null ? '' : $crow->mobile),
				'status'       => ((int) $crow->status === 5) ? 'confirmed' : 'standby',
				'is_call_boss' => ((int) $crow->is_call_boss === 1) ? 1 : 0,
			);
		}
	}

	/*
	/* OUTSTANDING TIMES, one set-based pass over every windowed scope call.
	/*
	/* goat_outstanding_by_call() is the SINGLE implementation of "who still
	/* owes times"; goat_calls_awaiting_times(), which slice 1 wrote and
	/* nothing has ever called, is now the boolean form of it and delegates
	/* there. That matters more than it looks: a badge reading "4 still to
	/* enter" beside a list showing none would read as a broken page rather
	/* than as two functions disagreeing.
	/*
	/* THAT HELPER IS TREATED AS UNPROVEN, NOT INHERITED. It was written before
	/* partial submissions existed and its only exercise was the slice 1
	/* harness against hand-inserted rows. The tests for this slice put real
	/* submissions on a call where SOME crew have times and some do not.
	/*
	/* Degrades to an empty map on failure: a missing badge is a visible
	/* absence, and this is a display, not an assertion. The crew query above
	/* is the one that fails hard.
	*/

	$outstandingByCall = goat_outstanding_by_call($rosterIDs);

	/*
	/* The confirmed count is DERIVED from the roster already fetched rather
	/* than bought with another round trip — so it cannot disagree with the
	/* crew array, which a separate COUNT(*) eventually would.
	/*
	/* IT IS NO LONGER count($crew). Since 27 Aug the roster also carries
	/* standby, and `confirmed` is compared against `required` to render
	/* "28 of 30". Counting the array would have reported a call as fully
	/* staffed the moment enough standby existed — a wrong answer that looks
	/* exactly like a right one.
	*/

	/*
	/* THE ROSTER ATTACH, AND IT IS NOW RUN OVER EVERY CALL THIS ENDPOINT
	/* EMITS — container, child and direct alike. A dedicated boss call
	/* returns required, confirmed, standby, crew and outstanding exactly as a
	/* leaf does; there is no second, thinner shape.
	/*
	/* IT UNSETS is_nominated, AND THAT IS CORRECT FOR A LEAF ONLY. A child or
	/* a direct call is never something the viewer is "the nominated contact
	/* for" in the sense a boss call means. A container IS, so the caller reads
	/* the field before this runs and puts it back afterwards — see the boss
	/* call loop below for why that is not a tidy-up waiting to happen.
	/*
	/* is_nominated is the ONLY key removed here. If a second one is ever
	/* added, the container branch has to be revisited with it.
	*/

	function goat_boss_leaf_call($info, $crewByCall, $outstandingByCall)
	{
		$cid  = $info['call_id'];
		$crew = isset($crewByCall[$cid]) ? $crewByCall[$cid] : array();

		$confirmed = 0;
		$standby   = 0;

		foreach ($crew as $member)
		{
			if ($member['status'] === 'confirmed')
			{
				$confirmed++;
			}
			else
			{
				$standby++;
			}
		}

		$info['confirmed'] = $confirmed;
		$info['standby']   = $standby;
		$info['crew']      = $crew;

		/*
		/* How many CONFIRMED crew still have no live submission. Drives the
		/* "4 still to enter" badge on the collapsed call header, which is the
		/* highest-value element on the Your Crew page — it turns the page into
		/* the outstanding list without expanding anything.
		/*
		/* Standby are excluded: a standby who did not work has no times to
		/* enter, and counting them would mean the badge never reached zero.
		*/

		$info['outstanding'] = isset($outstandingByCall[$cid]) ? $outstandingByCall[$cid] : 0;

		unset($info['is_nominated']);

		return $info;
	}

	/*
	/* ASSEMBLE — boss calls first, each with its windowed children.
	/*
	/* Children come from goat_supervision_children() and are intersected with
	/* $callInfo, which is already both windowed and scoped. A child of a boss
	/* call the viewer is confirmed on is in scope by construction (slice A,
	/* the SUPERVISORY branch), so the intersection drops exactly two things:
	/* calls outside the window, and dangling supervision edges — the helper's
	/* INNER JOINs having already made those invisible.
	*/

	$bossCalls = array();

	/*
	/* Every call_id emitted anywhere in boss_calls — containers AND children.
	/* This, not goat_supervision_boss_call(), is what decides direct_calls
	/* membership below. See the comment there for why that changed.
	*/

	$emitted = array();

	foreach ($bossCall as $bid)
	{
		$bid = (int) $bid;

		if (!isset($callInfo[$bid]))
		{
			continue;   /* outside the window */
		}

		$emitted[$bid] = true;

		/*
		/* A CONTAINER NOW CARRIES ITS OWN ROSTER — CHANGED 27 Aug 2026.
		/*
		/* It used to drop `required` and carry no crew at all, on the reading
		/* that a boss call is only a grouping device. It is also a real call
		/* that real people are confirmed on, and "Crew Boss 1 of 1" is a row a
		/* boss wants to open like any other, so it now runs through
		/* goat_boss_leaf_call() like everything else this endpoint emits.
		/*
		/* is_nominated IS READ FIRST AND PUT BACK AFTER, ON PURPOSE. The
		/* helper unsets it, which is right for a child or a direct call and
		/* wrong here.
		/*
		/* DO NOT "TIDY" THE RESTORE AWAY as a leftover of the old two-function
		/* shape. is_nominated: false is a REAL AND REACHABLE state on a
		/* container, not a theoretical one: goat_boss_calls_for_user()
		/* deliberately does not check is_call_boss, because Q4 grants scope to
		/* every confirmed resource on a dedicated boss call and not only to
		/* the flagged one. So a boss can hold a container they are NOT the
		/* listed contact for, and this field is the only thing that tells them
		/* so — Q19, the quiet line explaining why the crew are ringing a
		/* colleague. Drop it and two genuinely different situations collapse
		/* into one.
		/*
		/* The roster does not answer the same question. A crew member's
		/* is_call_boss says who the flagged boss IS; is_nominated says whether
		/* it is YOU, and the viewer is not always on their own container's
		/* roster to be found there.
		*/

		$nominated = $callInfo[$bid]['is_nominated'];

		$entry = goat_boss_leaf_call($callInfo[$bid], $crewByCall, $outstandingByCall);

		$entry['is_nominated'] = $nominated;

		$kids = goat_supervision_children($bid);
		$out  = array();

		foreach ($kids as $kid)
		{
			$kid = (int) $kid;

			if (!isset($callInfo[$kid]))
			{
				continue;
			}

			/*
			/* A CHILD THAT IS ITSELF ONE OF THE VIEWER'S BOSS CALLS IS SKIPPED
			/* HERE, because it is already emitted top-level as a container
			/* with its own children. call-supervision.php deliberately permits
			/* a boss call to supervise another boss call ("unusual, not
			/* incoherent"), so this is reachable, not theoretical. Without the
			/* guard the nested boss call appears twice — once correctly as a
			/* container, and once as a LEAF carrying a crew roster, which a
			/* container is never supposed to have. The membership rule is that
			/* no call appears twice; this is the third way it could.
			*/

			if (isset($bossSet[$kid]))
			{
				continue;
			}

			/*
			/* Children carry their OWN booking context, even though the parent
			/* boss call usually repeats it. The brief's example child object
			/* omits it; emitting it anyway is the safer of the two, because a
			/* boss call may legitimately supervise calls on another booking
			/* and 3 lists booking id/name/venue as something a boss needs.
			/* Dropping it only when it matched the parent was the third
			/* option and the worst: a shape the client cannot rely on.
			*/

			$emitted[$kid] = true;

			$out[] = goat_boss_leaf_call($callInfo[$kid], $crewByCall, $outstandingByCall);
		}

		$entry['children'] = $out;

		$bossCalls[] = $entry;
	}

	/*
	/* ASSEMBLE — direct calls.
	/*
	/* MEMBERSHIP RULE, REVISED 26 Aug 2026 AFTER TESTING ON TEST.
	/*
	/* The brief said a call belongs in direct_calls only when
	/* goat_supervision_boss_call($cid) === 0 — nothing supervises it. That
	/* rule LOSES CALLS, and test 14 proved it against live data: edge
	/* 37674 -> 37679, where 9734 is the flagged boss of 37679 but 5177 is the
	/* only person on the boss call above it. 37679 failed the direct_calls
	/* guard (something supervises it) and never reached boss_calls (that array
	/* is built from the viewer's OWN boss calls). It rendered NOWHERE, while
	/* still sitting in 9734's goat_boss_scope(). Authority without visibility.
	/*
	/* The brief's justification was that a supervised call has "richer
	/* supervisory context" so it belongs under its boss call. That only holds
	/* WHEN THE VIEWER IS ON THAT BOSS CALL. When they are not, there is no
	/* richer context available to them — there is nothing at all.
	/*
	/* And the flagged boss is precisely the person who must not lose it: the
	/* overarching call boss may not be on duty when this call actually runs,
	/* so the person flagged on the call is the one standing in front of the
	/* crew. They need the roster and the mobiles.
	/*
	/* THE RULE IS NOW "NOT ALREADY EMITTED". A call the viewer is flagged on
	/* appears in direct_calls unless it has already been rendered above, as a
	/* container or as a child. That keeps the no-call-appears-twice guarantee
	/* the brief actually wanted, without the hole:
	/*
	/*   supervised by MY boss call    -> emitted as a child, skipped here
	/*   one of MY OWN boss calls      -> emitted as a container, skipped here
	/*   supervised by SOMEONE ELSE'S  -> not emitted, lands here  <- THE FIX
	/*   supervised by nothing at all  -> not emitted, lands here
	/*
	/* goat_supervision_boss_call() is no longer called: $emitted answers the
	/* question directly, and drops a per-call query on the way.
	/*
	/* The is_nominated check stays. A scope call that was not emitted can only
	/* have arrived via the DIRECT branch, which already requires the flag, but
	/* the day someone adds a third source to goat_boss_scope() this is the
	/* line that should stop it leaking.
	*/

	$directCalls = array();

	foreach ($order as $cid)
	{
		if (!isset($scopeSet[$cid]))          continue;
		if (isset($emitted[$cid]))            continue;
		if (!$callInfo[$cid]['is_nominated']) continue;

		$directCalls[] = goat_boss_leaf_call($callInfo[$cid], $crewByCall, $outstandingByCall);
	}

	echo json_encode(array(
		'ok'           => true,
		'window'       => array('start' => $start_raw, 'end' => $end_raw),
		'boss_calls'   => $bossCalls,
		'direct_calls' => $directCalls,
	));

?>
