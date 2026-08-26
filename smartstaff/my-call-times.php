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
	/* THE READ THE TIME-ENTRY FORM NEEDS.
	/*
	/* my-boss-calls.php answers "which calls am I responsible for?". This
	/* answers "for THIS one call, who is on it and what have I already
	/* submitted?" — one call at a time, which is how design §8 says the form
	/* works.
	/*
	/* AUTH — goat_acting_user_id(), then a scope gate, same as
	/* my-boss-calls.php and submit-call-times.php: a crew-facing self-scoped
	/* read called by Crew Hub through the Edge Function, where the service key
	/* is the trust anchor.
	/*
	/* 403, NOT AN EMPTY RESPONSE — AND THIS DIFFERS FROM my-boss-calls.php ON
	/* PURPOSE. There, an empty scope is the NORMAL case for most of the roster
	/* and returns 200 with empty arrays, because an ordinary crew member
	/* opening Crew Hub is doing nothing wrong. Here the caller has named a
	/* specific call they cannot see. There is no benign reading of that.
	/*
	/* NOTHING IN THIS RESPONSE IS DERIVED. No rounded times, no billable
	/* hours, no late flag. Derivation is Python in THE GOAT (derivation.py,
	/* b3b6724) and is not reachable from SmartStaff at all. The form shows
	/* what the boss TYPED; Ops see the computed figures where they belong.
	/* That is design §B.0 and Q29, and it is why this file does no arithmetic
	/* on a submitted time beyond formatting it.
	/*
	/* NO MOBILE NUMBERS. my-boss-calls.php already returns them for exactly
	/* this scope, so duplicating them here would widen the PII surface for no
	/* gain. If the form wants tap-to-call it already has them.
	/*
	/* PHP 5.x — mysql_*, no ??, no short array syntax.
	*/

	$actor = goat_acting_user_id();   /* owns its own 400 / 401 */

	$callID = isset($_GET['callID']) ? (int) $_GET['callID'] : 0;

	if ($callID <= 0)
	{
		goat_json_error(400, 'callID required');
	}

	/* ---- SCOPE GATE ---- */

	$scope = goat_boss_scope($actor);

	if (!in_array($callID, $scope))
	{
		goat_json_error(403, 'You are not the crew boss for this call');
	}

	/*
	/* ---- QUERY 1 of 4 — the call ----
	/*
	/* PRIVACY: no callrate*, no callchargeout*, no paygrade. If you are adding
	/* a column here it needs to survive the question "does the form render
	/* it?" — and if the answer is no, it does not belong in the payload.
	*/

	$cres = mysql_query("SELECT
	                       c.id          AS call_id,
	                       c.call_name   AS call_name,
	                       c.start_date  AS start_date,
	                       c.start_time  AS start_time,
	                       c.est_length  AS est_length,
	                       c.required    AS required,
	                       c.bookingID   AS booking_id,
	                       b.name        AS booking_name,
	                       v.venue       AS venue_name
	                     FROM calls c
	                     LEFT JOIN bookings b ON b.id = c.bookingID
	                     LEFT JOIN venues   v ON v.id = b.venueID
	                     WHERE c.id = " . $callID . "
	                     LIMIT 1");

	if ($cres === false)
	{
		goat_json_error(500, 'call lookup failed: ' . mysql_error());
	}

	$callRow = mysql_fetch_object($cres);

	if (!$callRow)
	{
		/* in scope but not in `calls` means a dangling supervision edge, which
		/* every helper already treats as invisible — so 404 is the honest
		/* answer rather than an empty roster for a call that is not there. */

		goat_json_error(404, 'call not found');
	}

	$win = goat_call_window($callRow);

	/*
	/* ---- QUERY 2 of 4 — confirmed crew ----
	/*
	/* A FAILED CREW QUERY IS A 500, NOT AN EMPTY ROSTER, and this is the one
	/* place in this file that does not degrade. The house rule is that a
	/* lookup feeding a DISPLAY degrades to empty while one feeding an
	/* ASSERTION fails hard — and an empty roster here is indistinguishable
	/* from "nobody is confirmed on this call". A boss would open the form,
	/* see nobody, and submit times for no one. The submissions lookup below
	/* DOES degrade, because a missing submission renders as an empty row,
	/* which is a visible absence that states nothing untrue.
	*/

	$crewRes = mysql_query("SELECT
	                          ccm.userID  AS user_id,
	                          u.firstname AS firstname,
	                          u.lastname  AS lastname
	                        FROM call_crew_map ccm
	                        INNER JOIN users u ON u.id = ccm.userID
	                        WHERE ccm.callID = " . $callID . "
	                          AND ccm.status = 5
	                        ORDER BY u.lastname ASC, u.firstname ASC");

	if ($crewRes === false)
	{
		goat_json_error(500, 'crew lookup failed: ' . mysql_error());
	}

	$crewOrder = array();
	$crewName  = array();

	while ($row = mysql_fetch_object($crewRes))
	{
		$uid = (int) $row->user_id;

		if ($uid <= 0)
		{
			continue;
		}

		/*
		/* NAMES ARE DECODED UNCONDITIONALLY. `users` stores some names
		/* pre-encoded and some not — 9734 carries a literal apostrophe while
		/* other rows carry &#39; — so both paths are live. Matches
		/* goat_contact_from_user(). Skip it and O'Brien renders as O&#39;Brien.
		*/

		$first = html_entity_decode((string) $row->firstname, ENT_QUOTES);
		$last  = html_entity_decode((string) $row->lastname,  ENT_QUOTES);

		$crewOrder[] = $uid;
		$crewName[$uid] = trim(trim($first) . ' ' . trim($last));
	}

	/*
	/* ---- QUERY 3 of 4 — live submissions, THROUGH THE SLICE 1 HELPER ----
	/*
	/* goat_time_submissions_for_call() already resolves "live" via NOT EXISTS
	/* and then dedupes by highest id per userID in PHP. That second pass is a
	/* deliberate guard against a writer forgetting supersedes_id, and slice
	/* 2's test 9 proves it has never had to fire.
	/*
	/* DO NOT WRITE A SECOND RESOLUTION QUERY HERE. Two opinions about who is
	/* live is how one surface shows a crew member twice while another shows
	/* them once, and the disagreement surfaces on a timesheet rather than as
	/* an error.
	*/

	$subs = goat_time_submissions_for_call($callID);

	$subByUser = array();

	foreach ($subs as $s)
	{
		$uid = (int) $s['userID'];

		if ($uid > 0)
		{
			$subByUser[$uid] = $s;
		}
	}

	/*
	/* Breaks. A loop, bounded by the number of SUBMISSIONS on one call rather
	/* than by the roster, so it does not grow with crew who have not submitted.
	/* The brief accepts this; if a 22-crew call ever makes it matter, one
	/* IN (...) over the submission ids replaces the loop without changing the
	/* shape of anything above.
	*/

	function mct_breaks($submissionID)
	{
		$out = array();

		foreach (goat_time_submission_breaks($submissionID) as $b)
		{
			$out[] = array(
				'seq'            => (int) $b['seq'],
				'start_time'     => substr((string) $b['start_time'], 0, 5),
				'start_next_day' => (int) $b['start_next_day'],
				'duration_mins'  => (int) $b['duration_mins']
			);
		}

		return $out;
	}

	/*
	/* A submission -> the payload shape the form renders. Times are trimmed to
	/* HH:MM because that is what the boss typed; the seconds are always zero
	/* and showing them invites someone to think they mean something.
	*/

	function mct_submission($s)
	{
		return array(
			'id'           => (int) $s['id'],
			'on_time'      => substr((string) $s['on_time'], 0, 5),
			'off_time'     => substr((string) $s['off_time'], 0, 5),
			'off_next_day' => (int) $s['off_next_day'],
			'note'         => ($s['note'] === null ? '' : $s['note']),
			'submitted_at' => ($s['submitted_at'] === null ? '' : $s['submitted_at']),
			'breaks'       => mct_breaks((int) $s['id'])
		);
	}

	/* ---- assemble: booked crew ---- */

	$crew        = array();
	$outstanding = 0;
	$matched     = array();

	foreach ($crewOrder as $uid)
	{
		$entry = array(
			'user_id'      => $uid,
			'name'         => $crewName[$uid],

			/*
			/* covering_for is carried at ROW level for BOOKED crew too, not
			/* only for unbooked rows as the brief's example shows. It is a
			/* property of the row the form renders, submit-call-times.php
			/* accepts it on any row, and a client that had to read it from two
			/* different places depending on which array the row came from
			/* would eventually read it from one.
			*/

			'covering_for' => 0,
			'submission'   => null
		);

		if (isset($subByUser[$uid]))
		{
			$s = $subByUser[$uid];

			$entry['covering_for'] = (int) $s['covering_for'];
			$entry['submission']   = mct_submission($s);

			$matched[$uid] = true;
		}
		else
		{
			/* submission: null is the signal the form renders as an empty row */

			$outstanding++;
		}

		$crew[] = $entry;
	}

	/*
	/* ---- QUERY 4 of 4 — the unbooked people ----
	/*
	/* A live submission whose userID is NOT in the confirmed roster is an
	/* UNBOOKED row. That is exactly what unbooked = 1 means: not booked on
	/* this call (design §7). It does NOT mean unidentified — slice 3A made the
	/* EIN mandatory, so every such row carries a real userID.
	/*
	/* THE MATCH IS ON THE ROSTER, NOT ON THE unbooked FLAG. A row flagged
	/* unbooked whose person IS confirmed on the call — which slice 3A
	/* deliberately permits — belongs with the crew, because that is where the
	/* boss will look for them.
	/*
	/* This is a FOURTH query where the brief said three. It is set-based and
	/* bounded by the number of unbooked rows, not per-crew-member, so it is
	/* not the N+1 the brief was ruling out; and the names and EINs cannot come
	/* from query 2, which only sees people who ARE on the call.
	*/

	$unbookedIDs = array();

	foreach ($subByUser as $uid => $s)
	{
		if (!isset($matched[$uid]))
		{
			$unbookedIDs[] = (int) $uid;
		}
	}

	$unbooked = array();

	if (count($unbookedIDs) > 0)
	{
		$ures = mysql_query("SELECT id, ein, firstname, lastname
		                     FROM users
		                     WHERE id IN (" . implode(',', array_map('intval', $unbookedIDs)) . ")
		                     ORDER BY lastname ASC, firstname ASC");

		/*
		/* Degrades: a failed lookup here costs the unbooked people's names,
		/* which is a visible absence. It is not the crew query.
		*/

		if ($ures !== false)
		{
			while ($row = mysql_fetch_object($ures))
			{
				$uid = (int) $row->id;

				if (!isset($subByUser[$uid]))
				{
					continue;
				}

				$s = $subByUser[$uid];

				$first = html_entity_decode((string) $row->firstname, ENT_QUOTES);
				$last  = html_entity_decode((string) $row->lastname,  ENT_QUOTES);

				/*
				/* ein is returned HERE AND ONLY HERE. The boss typed it to add
				/* the person, so it is not new information to them, and
				/* showing "Sam Whitlock (EIN 5925)" confirms they identified
				/* the right person. Only one boss has scope on a call, so it
				/* cannot be harvested across a roster. Booked crew rows do not
				/* carry it because nothing renders it there.
				*/

				$unbooked[] = array(
					'user_id'      => $uid,
					'name'         => trim(trim($first) . ' ' . trim($last)),
					'ein'          => (int) $row->ein,
					'covering_for' => (int) $s['covering_for'],
					'submission'   => mct_submission($s)
				);
			}
		}
	}

	echo json_encode(array(
		'ok'   => true,
		'call' => array(
			'call_id'      => (int) $callRow->call_id,
			'call_name'    => html_entity_decode((string) $callRow->call_name, ENT_QUOTES),
			'start'        => date('Y-m-d H:i', $win['start']),
			'end'          => date('Y-m-d H:i', $win['end']),
			'booking_id'   => (int) $callRow->booking_id,
			'booking_name' => html_entity_decode((string) $callRow->booking_name, ENT_QUOTES),
			'venue'        => html_entity_decode((string) $callRow->venue_name, ENT_QUOTES),
			'required'     => (int) $callRow->required,
			'confirmed'    => count($crewOrder)
		),
		'crew'        => $crew,
		'unbooked'    => $unbooked,
		'outstanding' => $outstanding
	));

?>
