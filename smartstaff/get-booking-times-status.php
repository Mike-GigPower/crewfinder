<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');

	/*
	/* include_once, AND THE DIFFERENCE MATTERS. resolve-call-contact.php is an
	/* UNGUARDED file -- its functions are declared at top level with no
	/* function_exists() wrapper (see the note in supervision-graph.php, which
	/* includes it the same way). A plain include() of it twice is a fatal
	/* redeclare, not a no-op. time-submission-graph.php IS guarded, but takes
	/* include_once too so the rule reads the same for both.
	*/

	include_once(dirname(__FILE__) . '/resolve-call-contact.php');   /* goat_call_window() */
	include_once(dirname(__FILE__) . '/time-submission-graph.php');  /* the "has times" helpers */

	header('Content-Type: application/json');

	/*
	/* READ-ONLY endpoint — the TIME STATE of every call of one booking, so THE
	/* GOAT can shade the call rows the way SmartStaff's own booking page does
	/* (BRIEF-times-navigation D4/D5, 24 Nov 2026).
	/*
	/* WHY A NEW FILE RATHER THAN A FIELD ON get-booking.php. get-booking.php is
	/* read on every call dialog, every booking dialog and the Prev/Next walk;
	/* the per-person submission and keyed reads below are the expensive half of
	/* the Times outstanding lane. Shading is decoration, so it loads on its own
	/* and late, and a booking still opens when this file is missing entirely.
	/* D5: get-booking.php is not edited.
	/*
	/* IT ASKS THE LANE'S QUESTION THROUGH THE LANE'S HELPERS.
	/* goat_outstanding_people_by_call() is THE single implementation of "who
	/* still owes times" (time-submission-graph.php says so at length, and the
	/* reason is that two opinions about who has submitted is how a call shows
	/* chased on one surface and outstanding on another). This file adds NO
	/* query about submissions, keyed times or no-shows -- only the roster
	/* count the helper does not return, and the call's own flags.
	/*
	/* ---- THE ONE KNOWN DIFFERENCE FROM THE LANE: PROMO-PENDING ----
	/*
	/* ops-times-outstanding.php's roster read is `ccm.status = 5` and nothing
	/* else. goat_outstanding_people_by_call() excludes a status-5 person with
	/* an unanswered call_promo_ack row (added 15 Oct 2026; the lane was never
	/* brought along). A promo-pending person can hold no times at all --
	/* submit-call-times.php and update-call-times.php both refuse -- so the
	/* lane always counts them as needing and the helper never does.
	/*
	/* Left alone, that is a FALSE ALL-CLEAR: a call whose only missing times
	/* are promo-pending would report with_times == confirmed, and THE GOAT
	/* would label it ALL IN -- ACCEPT while the lane still lists it. So:
	/*
	/*   confirmed      counts the HELPER'S crew set (status 5, promo-pending
	/*                  EXCLUDED), which is what makes confirmed - with_times
	/*                  arithmetic that closes.
	/*   promo_pending  counts them separately, and THE GOAT refuses ALL IN
	/*                  while it is non-zero (§1.3, the row above "All in").
	/*
	/* So the lane's figure is reconstructed as
	/*   confirmed - with_times + promo_pending == the lane's `needing`
	/* and smoke A7 checks exactly that. ops-times-outstanding.php is NOT
	/* edited here (Mike, 24 Nov): the drift is real but it is the lane's, and
	/* fixing it changes which calls Rich is shown.
	/*
	/* ---- with_times IS NULL ON A TICKED CALL, NOT A NUMBER ----
	/*
	/* goat_outstanding_people_by_call() drops times_filled = 1 calls before
	/* anything else runs on them, and both its existing callers read the
	/* absence as zero. Zero outstanding would make with_times == confirmed
	/* here -- a guess dressed as a measurement. So a ticked call reports
	/* with_times: null, and THE GOAT never reads it: ✓ TIMES FILLED wins at
	/* step 4 of the order, above every count. The alternative was an
	/* $ignoreTick argument on the shared helper; Mike chose null (24 Nov), so
	/* time-submission-graph.php stays untouched.
	/*
	/* PRIVACY — COUNTS AND FLAGS ONLY. No names, no EINs, no notes. The
	/* no-show helper returns the reporter's name and the report's free-text
	/* note; only count() of its list leaves this file. A caller that wants the
	/* people has my-call-times.php, which is gated per call.
	/*
	/* A FAILED LOOKUP IS A 500, NEVER AN EMPTY `calls`. Empty means "nothing to
	/* shade", and THE GOAT renders that as a plain, all-clear call list --
	/* indistinguishable from a booking where every call really is unremarkable.
	/* Same rule as ops-times-outstanding.php, for the same reason.
	/*
	/* Gate: goat_can_read_all() — Admin, Leadership or Operations, matching
	/* READ_ALL_COHORTS on the Flask route. Same gate as get-crewlist-status.php.
	/*
	/* PHP 5.x — array(), no ??, no short array syntax, tabs. This file's OWN
	/* queries go through goat_pdo() with bound parameters and add no mysql_*
	/* call; the shared helpers it includes keep their legacy connection, which
	/* is how get-crewlist-status.php already works.
	*/

	if (!goat_can_read_all())
	{
		goat_json_error(403, 'Admin, Leadership or Operations required');
		exit;
	}

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		goat_json_error(503, 'Database handle unavailable');
		exit;
	}

	/*
	/* ── INPUT ────────────────────────────────────────────────────────────────
	/*
	/* booking=<int>, and nothing else. A bulk scope is deliberately absent:
	/* All Bookings expands one booking at a time and keeps the answers per
	/* booking, so there is no caller for it yet and the per-person reads below
	/* are not something to hand a 200-booking list.
	*/

	$raw = isset($_GET['booking']) ? trim((string) $_GET['booking']) : '';

	if (!preg_match('/^[0-9]+$/', $raw) || (int) $raw <= 0)
	{
		goat_json_error(400, 'booking must be a positive integer');
		exit;
	}

	$bookingID = (int) $raw;
	$now       = time();

	/*
	/* Is calls.cancelled_at present on this environment? PDO-side, because this
	/* decides one of this file's own SELECT lists. Same shape as
	/* crewlist-sent-lib.php's guard.
	*/

	function goat_bts_have_cancelled($pdo)
	{
		static $has = null;

		if ($has === null)
		{
			$r   = $pdo->query("SHOW COLUMNS FROM calls LIKE 'cancelled_at'");
			$has = ($r !== false && $r->fetch() !== false);

			if ($r !== false)
				$r->closeCursor();
		}

		return $has;
	}

	/*
	/* ── THE CALLS, AND THEIR OWN FLAGS ───────────────────────────────────────
	/*
	/* times_filled and call_locked are SELECTED and cast with (int) in PHP,
	/* NEVER compared with "= 1" in SQL: SmartStaff's own forms write them as
	/* STRINGS and the comparison is not reliable across MySQL versions. Same
	/* discipline as ops-times-outstanding.php and is_call_boss.
	/*
	/* FETCH_OBJ, not the handle's default FETCH_ASSOC: goat_call_window() reads
	/* $call->start_date, ->start_time and ->est_length off an object.
	*/

	$haveCancelled = goat_bts_have_cancelled($pdo);

	try
	{
		$sel = "id, start_date, start_time, est_length, times_filled, call_locked"
		     . ($haveCancelled ? ", cancelled_at" : "");

		$stmt = $pdo->prepare("SELECT " . $sel . " FROM calls WHERE bookingID = :b");
		$stmt->bindValue(':b', $bookingID, PDO::PARAM_INT);
		$stmt->execute();

		$callRows = array();

		while ($row = $stmt->fetch(PDO::FETCH_OBJ))
		{
			if ((int) $row->id > 0)
				$callRows[(int) $row->id] = $row;
		}

		$stmt->closeCursor();
	}
	catch (PDOException $e)
	{
		error_log('get-booking-times-status: call read: ' . $e->getMessage());
		goat_json_error(500, 'call query failed');
		exit;
	}

	if (count($callRows) === 0)
	{
		/* A booking with no calls is a real, empty answer -- not a failure. */
		echo json_encode(array('ok' => true, 'booking' => $bookingID,
		                       'now' => $now, 'calls' => new stdClass()));
		exit;
	}

	$callIDs = array_keys($callRows);

	/*
	/* ── THE ROSTER COUNTS ────────────────────────────────────────────────────
	/*
	/* The one thing the helpers do not return: how many people the outstanding
	/* count is OUT OF. The crew set is the helper's, clause for clause --
	/* status 5, LEFT JOIN call_promo_ack on an unanswered row, cpa.id IS NULL
	/* -- so that confirmed - outstanding is a count of the same population.
	/*
	/* COUNT(DISTINCT userID) rather than COUNT(*): a person with two unanswered
	/* ack rows would otherwise be two promo-pending people. The helper is
	/* immune because it folds rows into a set; this has to say so in SQL.
	*/

	$confirmedOf = array();
	$promoOf     = array();

	try
	{
		$marks = implode(',', array_fill(0, count($callIDs), '?'));

		$stmt = $pdo->prepare(
			"SELECT ccm.callID AS callID,
			        COUNT(DISTINCT CASE WHEN cpa.id IS NULL THEN ccm.userID ELSE NULL END) AS confirmed,
			        COUNT(DISTINCT CASE WHEN cpa.id IS NULL THEN NULL ELSE ccm.userID END) AS promo_pending
			 FROM call_crew_map ccm
			 LEFT JOIN call_promo_ack cpa
			        ON cpa.callID = ccm.callID
			       AND cpa.userID = ccm.userID
			       AND cpa.acked_at IS NULL
			 WHERE ccm.callID IN (" . $marks . ")
			   AND ccm.status = 5
			 GROUP BY ccm.callID");

		$pos = 1;

		foreach ($callIDs as $cid)
		{
			$stmt->bindValue($pos, (int) $cid, PDO::PARAM_INT);
			$pos++;
		}

		$stmt->execute();

		while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$cid = (int) $row['callID'];

			$confirmedOf[$cid] = (int) $row['confirmed'];
			$promoOf[$cid]     = (int) $row['promo_pending'];
		}

		$stmt->closeCursor();
	}
	catch (PDOException $e)
	{
		error_log('get-booking-times-status: roster read: ' . $e->getMessage());
		goat_json_error(500, 'roster query failed');
		exit;
	}

	/*
	/* ── WHO STILL OWES TIMES, AND WHAT IS STILL TO CONFIRM ───────────────────
	/*
	/* Both through the shared helpers, both fail-hard.
	/*
	/* goat_outstanding_people_by_call() returns array() on failure, which is
	/* indistinguishable from "every call is done" -- the one thing this file
	/* must never report by accident. So it is called with a non-empty list and
	/* a NON-TICKED call missing from the answer is read as "nobody confirmed",
	/* which the roster count above independently confirms: if confirmed > 0 and
	/* the call is absent, the lookup failed and this is a 500.
	*/

	$outstanding = goat_outstanding_people_by_call($callIDs);

	$ns = goat_unresolved_no_shows_by_call($callIDs);

	if (!$ns['ok'])
	{
		error_log('get-booking-times-status: ' . $ns['error']);
		goat_json_error(500, $ns['error']);
		exit;
	}

	$nsOf = $ns['by_call'];

	/*
	/* ── ASSEMBLE ─────────────────────────────────────────────────────────────
	/*
	/* `ended` comes from goat_call_window(), which sums start_date and
	/* start_time through strtotime(). TIME_TO_SEC() in SQL is an hour wrong on
	/* the two Melbourne DST transitions -- the comment ops-times-outstanding.php
	/* sets out at length.
	/*
	/* A CANCELLED CALL needs no special case. Nobody on it is status 5, so the
	/* roster count is 0, the helper reports nothing and the no-show helper
	/* cannot see it. Its counts come out zero on their own, and THE GOAT takes
	/* `cancelled` first anyway (§1.3, order 1) and keeps today's look.
	*/

	$out = array();

	foreach ($callRows as $cid => $row)
	{
		$win    = goat_call_window($row);
		$filled = ((int) $row->times_filled === 1);

		$confirmed = isset($confirmedOf[$cid]) ? $confirmedOf[$cid] : 0;
		$promo     = isset($promoOf[$cid])     ? $promoOf[$cid]     : 0;

		if ($filled)
		{
			/* See the header: the helper drops ticked calls, so there is no
			/* honest number to give. null, and THE GOAT does not read it. */
			$withTimes = null;
		}
		else if (isset($outstanding[$cid]))
		{
			$withTimes = $confirmed - count($outstanding[$cid]);

			if ($withTimes < 0)
				$withTimes = 0;
		}
		else if ($confirmed > 0)
		{
			/* Confirmed crew, but the helper has no entry for the call: the only
			/* way that happens is a failed lookup (it returns array()). Fail
			/* hard rather than shade the call all-clear. */
			error_log('get-booking-times-status: outstanding lookup returned nothing for call '
			          . $cid . ' with ' . $confirmed . ' confirmed');
			goat_json_error(500, 'outstanding lookup failed');
			exit;
		}
		else
		{
			$withTimes = 0;   /* nobody confirmed — nothing is owed and nothing is in */
		}

		$out[(string) $cid] = array(
			'ended'         => ($win['end'] <= $now),
			'cancelled'     => ($haveCancelled && $row->cancelled_at !== null
			                                   && (int) $row->cancelled_at > 0),
			'confirmed'     => $confirmed,
			'promo_pending' => $promo,
			'with_times'    => $withTimes,
			'times_filled'  => ($filled ? 1 : 0),
			'call_locked'   => (((int) $row->call_locked === 1) ? 1 : 0),
			'no_show_open'  => (isset($nsOf[$cid]) ? count($nsOf[$cid]) : 0)
		);
	}

	/* (object) so an empty map encodes as {} not [] — the client indexes by id */
	$body = json_encode(array(
		'ok'      => true,
		'booking' => $bookingID,
		'now'     => $now,
		'calls'   => count($out) ? $out : new stdClass()
	));

	if ($body === false)
	{
		goat_json_error(500, 'could not encode response');
		exit;
	}

	echo $body;

?>
