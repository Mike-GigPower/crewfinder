<?php

	/*
	/* FUNCTIONS ONLY, for test-my-call-times-unbooked.php. The mct_* functions
	/* below are declared at the top level, so PHP binds them when the file is
	/* compiled; returning here leaves them defined and runs none of the
	/* endpoint (no global.php, no database, no output). */

	if (defined('MCT_FUNCTIONS_ONLY'))
	{
		return;
	}

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
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
	/* authenticates the EDGE FUNCTION AND NOTHING MORE. It carries no cohort:
	/* Crew Hub presents it on every request, for every signed-in crew member.
	/* The scope gate below is the only thing between a Crew Hub caller and
	/* somebody else's call.
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

	/*
	/* ---- SCOPE GATE ----
	/*
	/* goat_time_entry_scope_for(), NOT goat_boss_scope() — CHANGED in slice B
	/* of BRIEF-crew-self-times. This file feeds the time-entry FORM, so it
	/* opens for exactly the people submit-call-times.php will accept a
	/* submission from. A Team Lead on a Crew Boss call still sees the crew
	/* through my-call-crew.php and Your Crew; they no longer reach this form.
	/*
	/* Resolved BELOW the Ops test, so an Ops reader costs no tier lookup, and
	/* only for THIS call (review R2). A failed lookup is a 500, never the 403
	/* (review R1).
	*/

	/*
	/* BOSS OF THIS CALL, OR OPS AT A KEYBOARD. The boss reads this to see what
	/* they submitted; Ops read the same thing to review it before accepting,
	/* which is what the Times outstanding lane opens (addendum 1, Q42). Same
	/* data, two legitimate readers.
	/*
	/* NOT goat_can_read_all(), WHICH THIS FILE USED TO CALL AND WHICH LEAKED.
	/* That helper's second branch returns true on a valid service key ALONE,
	/* resolving a cohort for nobody. Crew Hub sends the key on every request,
	/* so through Crew Hub the branch was always true and the scope test below
	/* was never reached: any signed-in crew member could open
	/* /your-crew/<any call id>/times and read that call's roster and every
	/* submission on it. Confirmed against live before this was changed.
	/*
	/* goat_user_cohort() is the SESSION-ONLY half of that helper — null unless
	/* $user->checkSession(). A service-key caller cannot satisfy it and falls
	/* through to the time-entry scope test, which is the entire fix. Ops lose nothing:
	/* they open this from THE GOAT on their own SmartStaff session, and THE
	/* GOAT holds no service key at all, by design (app.py,
	/* ss_get_call_submissions -> get_ss_session).
	/*
	/* This is the invariant get-induction-content.php already spells out: the
	/* service key's read-all branch is sound only where the portal has checked
	/* the cohort via requireCohort BEFORE calling, and a crew-facing page
	/* breaks it. Read any endpoint that pairs goat_acting_user_id() with
	/* goat_can_read_all() with that in mind.
	/*
	/* THE REFUSAL IS IDENTICAL FOR BOTH FAILURE MODES, deliberately: someone
	/* who is neither must not be able to tell "no such scope" from "not Ops"
	/* by reading the message.
	*/

	$cohort = goat_user_cohort();

	$is_ops = ($cohort === 'admin' || $cohort === 'leadership' || $cohort === 'operations');

	if (!$is_ops)
	{
		$es = goat_time_entry_scope_for($actor, array($callID));

		if (!$es['ok'])
		{
			goat_json_error(500, 'time-entry scope lookup failed: ' . $es['error']);
		}

		if (!in_array($callID, $es['call_ids']))
		{
			goat_json_error(403, 'You are not the person entering times for this call');
		}
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

	/*
	/* cpa.id is NOT NULL for an UNANSWERED PROMOTION: a backup promoted to 5
	/* who has not accepted. They stay on the roster so the boss can see them,
	/* but the form locks the row and submit-call-times.php refuses it -- nobody
	/* knows they are working until they accept or Ops confirm they did.
	*/

	$crewRes = mysql_query("SELECT
	                          ccm.userID  AS user_id,
	                          u.firstname AS firstname,
	                          u.lastname  AS lastname,
	                          cpa.id      AS promo_ack_id
	                        FROM call_crew_map ccm
	                        INNER JOIN users u ON u.id = ccm.userID
	                        LEFT JOIN call_promo_ack cpa
	                               ON cpa.callID = ccm.callID
	                              AND cpa.userID = ccm.userID
	                              AND cpa.acked_at IS NULL
	                        WHERE ccm.callID = " . $callID . "
	                          AND ccm.status = 5
	                        ORDER BY u.lastname ASC, u.firstname ASC");

	if ($crewRes === false)
	{
		goat_json_error(500, 'crew lookup failed: ' . mysql_error());
	}

	$crewOrder = array();
	$crewName  = array();
	$crewPromo = array();

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
		$crewPromo[$uid] = ($row->promo_ack_id !== null) ? true : false;
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

	function mct_hhmm($v)
	{
		$v = (string) $v;

		if ($v === '')
		{
			return '';
		}

		$out = substr($v, 0, 5);

		return ($out === false) ? '' : $out;
	}

	function mct_submission($s)
	{
		return array(
			'id'           => (int) $s['id'],
			/* (string) + '' guard: a No-show row stores NULL times, and on
			/* PHP 5.6 substr('', 0, 5) is FALSE, not '' — which reached the
			/* wire as "on_time": false on test, 6 Nov 2026. Always a string. */
			'on_time'      => mct_hhmm($s['on_time']),
			'off_time'     => mct_hhmm($s['off_time']),
			'off_next_day' => (int) $s['off_next_day'],
			'note'         => ($s['note'] === null ? '' : $s['note']),
			'submitted_at' => ($s['submitted_at'] === null ? '' : $s['submitted_at']),

			/* slice G, additive: who typed it — 'boss' or 'self' (crew member,
			/* own times). Before slice A the column does not exist and every
			/* row is a boss row. */
			'source'       => (isset($s['source']) && $s['source'] === 'self') ? 'self' : 'boss',

			/* BRIEF-boss-no-show, additive: 1 when the boss reported this person
			/* as a No-show, so the form re-opens as it was left. Before the
			/* migration the column does not exist and every row is 0. On a
			/* No-show row on_time / off_time are '' (stored NULL). */
			'no_show'      => (isset($s['no_show']) && (int) $s['no_show'] === 1) ? 1 : 0,
			'breaks'       => mct_breaks((int) $s['id'])
		);
	}

	/*
	/* Who goes in `unbooked`: every live submission whose person is NOT in the
	/* confirmed roster ($matched), EXCEPT a No-show report. A no_show = 1 row
	/* is never a claim that somebody worked, so it is never "submitted for
	/* crew who are not booked". Whatever the person's status is now: 8 once
	/* Ops confirm the report (the 7 Nov 2026 UI pass, where 298 reappeared
	/* in THE GOAT's grid as "not booked" straight after Confirm), or any
	/* other status. Before the migration the column is absent and every row
	/* counts as 0, so nothing changes there. Pure: unit-tested by
	/* test-my-call-times-unbooked.php. */

	function mct_unbooked_ids($subByUser, $matched)
	{
		$ids = array();

		foreach ($subByUser as $uid => $s)
		{
			if (isset($matched[$uid]))
			{
				continue;
			}

			if (isset($s['no_show']) && (int) $s['no_show'] === 1)
			{
				continue;
			}

			$ids[] = (int) $uid;
		}

		return $ids;
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
			'submission'   => null,

			/* Locked on the form; never counted as outstanding below. */
			'promo_pending' => $crewPromo[$uid]
		);

		if (isset($subByUser[$uid]))
		{
			$s = $subByUser[$uid];

			$entry['covering_for'] = (int) $s['covering_for'];
			$entry['submission']   = mct_submission($s);

			$matched[$uid] = true;
		}
		else if (!$crewPromo[$uid])
		{
			/* submission: null is the signal the form renders as an empty row.
			/* An unanswered promotion is not outstanding: it cannot be entered. */

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
	/* EIN mandatory, so every such row carries a real userID. A No-show
	/* report is never one: see mct_unbooked_ids().
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

	$unbookedIDs = mct_unbooked_ids($subByUser, $matched);

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

	/*
	/* ---- unresolved No-show reports (BRIEF-boss-no-show) ----
	/*
	/* For THE GOAT's review grid, which reads this endpoint: the reports Ops
	/* still have to confirm or reject, with who reported them. DISPLAY, so a
	/* failed lookup DEGRADES — to null, meaning "unknown", not array(), which
	/* would claim there are none. The refusal that must not degrade lives in
	/* accept-call-times.php, which asks the same helper itself.
	*/

	$ns      = goat_unresolved_no_shows_by_call(array($callID));
	$noShows = null;

	if ($ns['ok'])
	{
		$noShows = isset($ns['by_call'][$callID]) ? $ns['by_call'][$callID] : array();
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
		'outstanding' => $outstanding,
		'no_shows'    => $noShows
	));

?>
