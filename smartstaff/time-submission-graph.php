<?php

	/*
	/* Time submission helpers — READ-ONLY traversal over
	/* `call_time_submissions` and `call_time_submission_breaks`.
	/*
	/* 1. WHAT A SUBMISSION IS. Times TYPED BY A CREW BOSS after a shift.
	/* Nothing here is observed, stamped or measured by a device. Every value
	/* is a human recollection entered on a phone, often hours later and often
	/* in a loading dock. Treat it accordingly: it is a claim, not a reading.
	/*
	/* 2. WHAT IT IS NOT. A submission is NOT PAYROLL. Nothing in these tables
	/* reaches `call_crew_map` until Ops accept it in THE GOAT through the
	/* existing admin path. `call_crew_map` remains the record; this is the
	/* boss's claim about what happened. Anything that treats a submission as
	/* authoritative has misunderstood the feature.
	/*
	/* 3. APPEND-ONLY. Corrections INSERT a new row pointing at the old one
	/* through `supersedes_id`. Nothing is ever edited in place and nothing is
	/* ever deleted. The reason is who is typing: the actor is a crew boss
	/* rather than an Ops person, and the data is payroll-adjacent — so the
	/* audit trail is the point of the design, not a by-product of it. There is
	/* no UPDATE anywhere in this file, and there should be none in slice 2
	/* either, beyond setting `voided` and the acceptance columns.
	/*
	/* 4. SUPERSEDING A SUBMISSION SUPERSEDES ITS BREAKS. A correction writes a
	/* FRESH set of break rows against the new submission id. Break rows are
	/* never moved, rewritten or re-pointed. Breaks belong to one submission
	/* for their whole life, which is why goat_time_submission_breaks() takes a
	/* submission id and not a (call, user) pair.
	/*
	/* 5. WHY THERE IS NO UNIQUE (callID, userID). Anyone reading this beside
	/* `call_supervision`'s `uniq_child` will read the absence as an omission.
	/* It is not. call_supervision permits exactly ONE boss per call, so a
	/* unique key expresses that rule in the schema. This table is append-only:
	/* a correction IS a second row for the same person on the same call, and a
	/* unique key would forbid precisely the behaviour the design depends on.
	/* The live row is resolved here, in ORDER BY id DESC, not by the schema.
	/*
	/* Every query INNER JOINs `calls`, so rows left behind by a deleted call
	/* are invisible. sss::deleteCall does not know these tables exist, exactly
	/* as it does not know about call_feeds or call_supervision.
	/*
	/* Every function returns array() or 0 on EVERY failure path — never false,
	/* never null. Callers count() and foreach() the result.
	/*
	/* WHO READS THIS FILE (corrected Nov 2026 — it used to say nothing here
	/* was reachable yet): my-call-times.php, submit-call-times.php,
	/* submit-my-times.php, my-boss-calls.php, my-shifts.php,
	/* calls-awaiting-times.php, ops-times-outstanding.php and
	/* self-times-approval.php. Since slice D of BRIEF-crew-self-times it also
	/* holds the ONE copy of submission validation and the append-only write,
	/* shared by the boss endpoint and the self endpoint — so the "no UPDATE"
	/* rule above now has exactly one exception in this file:
	/* goat_void_submission(), which sets voided and nothing else.
	/*
	/* PHP 5.x — mysql_*, no ??, no short array syntax.
	*/

	if (!function_exists('goat_time_submission_for'))
	{

		/*
		/* The LIVE submission for one person on one call, or array().
		/*
		/* $userID is a SmartStaff `userID`, NEVER an EIN. Passing an EIN
		/* silently returns another person's times — documented regression,
		/* v3.4.10 (EIN 5925 resolved against userID 9734).
		/*
		/* $userID <= 0 RETURNS array(), DELIBERATELY. Unbooked rows carry
		/* userID = 0 as a real value meaning "identity not established", and
		/* several different people on one call can all carry it. Treating 0 as
		/* a lookup key would return whichever unidentified person happened to
		/* be inserted last, presented as though it were a specific person's
		/* times. Read unbooked rows through goat_time_submissions_for_call().
		/*
		/* HIGHEST id WINS. A correction is a later INSERT, so id order is
		/* submission order. `supersedes_id` records the chain for the audit
		/* view and is NOT needed to find the live row — walking it would mean
		/* a recursive query for an answer ORDER BY already has. That is why
		/* the column looks unused here.
		*/

		function goat_time_submission_for($callID, $userID)
		{
			$callID = (int) $callID;
			$userID = (int) $userID;

			if ($callID <= 0 || $userID <= 0)
			{
				return array();
			}

			$res = mysql_query("SELECT s.*
			                    FROM call_time_submissions s
			                    INNER JOIN calls c ON c.id = s.callID
			                    WHERE s.callID = " . $callID . "
			                      AND s.userID = " . $userID . "
			                      AND s.voided = 0
			                    ORDER BY s.id DESC
			                    LIMIT 1");

			if ($res === false)
			{
				return array();
			}

			$row = mysql_fetch_assoc($res);

			if (!$row)
			{
				return array();
			}

			return $row;
		}

		/*
		/* Every LIVE submission on a call, booked and unbooked, ordered by
		/* userID then unbooked_name. Slice 4 and the Ops surface both need it.
		/*
		/* "LIVE" IS RESOLVED IN TWO PASSES, AND BOTH ARE NEEDED:
		/*
		/*   SQL   — drop rows that are voided, and rows superseded by a
		/*            non-voided row. This is the only test that works for
		/*            UNBOOKED rows, because they all share userID = 0 and
		/*            cannot be grouped by person.
		/*   PHP   — for userID > 0, keep only the highest id per userID.
		/*
		/* The second pass exists because the first trusts `supersedes_id` to
		/* have been set. If slice 2 ever inserts a correction without setting
		/* it, the SQL alone would return BOTH rows for one person and this
		/* function would disagree with goat_time_submission_for() — which
		/* keys on ORDER BY id DESC and would return one. Two functions
		/* disagreeing about who is live is a far worse failure than one extra
		/* pass over a handful of rows, and it would surface as a duplicated
		/* crew member on a timesheet rather than as an error.
		*/

		function goat_time_submissions_for_call($callID)
		{
			$callID = (int) $callID;
			$out    = array();

			if ($callID <= 0)
			{
				return $out;
			}

			$res = mysql_query("SELECT s.*
			                    FROM call_time_submissions s
			                    INNER JOIN calls c ON c.id = s.callID
			                    WHERE s.callID = " . $callID . "
			                      AND s.voided = 0
			                      AND NOT EXISTS (
			                        SELECT 1
			                        FROM call_time_submissions n
			                        WHERE n.supersedes_id = s.id
			                          AND n.voided = 0
			                      )
			                    ORDER BY s.userID ASC, s.unbooked_name ASC, s.id ASC");

			if ($res === false)
			{
				return $out;
			}

			/*
			/* keyed by userID for the dedupe; unbooked rows bypass it
			/* entirely and are appended as they come
			*/

			$byUser = array();

			while ($row = mysql_fetch_assoc($res))
			{
				$uid = (int) $row['userID'];

				if ($uid <= 0)
				{
					$out[] = $row;
					continue;
				}

				if (!isset($byUser[$uid])
				    || (int) $row['id'] > (int) $byUser[$uid]['id'])
				{
					$byUser[$uid] = $row;
				}
			}

			foreach ($byUser as $row)
			{
				$out[] = $row;
			}

			return $out;
		}

		/*
		/* Break rows for ONE submission, in seq order.
		/*
		/* INNER JOINs BOTH the submission and its call. There is no foreign
		/* key on submission_id — MyISAM would not enforce one — so a break row
		/* whose submission has gone, or whose submission points at a deleted
		/* call, resolves to nothing rather than to a half-answer. Same rule
		/* the migration's verification query checks for.
		*/

		function goat_time_submission_breaks($submissionID)
		{
			$submissionID = (int) $submissionID;
			$out          = array();

			if ($submissionID <= 0)
			{
				return $out;
			}

			$res = mysql_query("SELECT b.*
			                    FROM call_time_submission_breaks b
			                    INNER JOIN call_time_submissions s ON s.id = b.submission_id
			                    INNER JOIN calls c ON c.id = s.callID
			                    WHERE b.submission_id = " . $submissionID . "
			                    ORDER BY b.seq ASC, b.id ASC");

			if ($res === false)
			{
				return $out;
			}

			while ($row = mysql_fetch_assoc($res))
			{
				$out[] = $row;
			}

			return $out;
		}

		/*
		/* EVERY row for one person on one call — voided and superseded
		/* included — oldest first. The audit view. Not used in normal
		/* operation.
		/*
		/* $userID is a SmartStaff `userID`, NEVER an EIN — see
		/* goat_time_submission_for() for the v3.4.10 regression.
		/*
		/* userID = 0 IS PERMITTED HERE, and means something different from
		/* everywhere else: it returns EVERY UNIDENTIFIED PERSON'S rows on that
		/* call, not one person's, because 0 is not an identity. That is an
		/* exact match rather than a wildcard, and it is the only way to audit
		/* unbooked entries at all — but do not present the result as one
		/* person's history.
		/*
		/* Worth having from this slice onward even though nothing calls it:
		/* rows are being written from slice 2, and intent cannot be
		/* reconstructed later from a table that was never queryable.
		*/

		function goat_time_submission_history($callID, $userID)
		{
			$callID = (int) $callID;
			$userID = (int) $userID;
			$out    = array();

			if ($callID <= 0 || $userID < 0)
			{
				return $out;
			}

			$res = mysql_query("SELECT s.*
			                    FROM call_time_submissions s
			                    INNER JOIN calls c ON c.id = s.callID
			                    WHERE s.callID = " . $callID . "
			                      AND s.userID = " . $userID . "
			                    ORDER BY s.id ASC");

			if ($res === false)
			{
				return $out;
			}

			while ($row = mysql_fetch_assoc($res))
			{
				$out[] = $row;
			}

			return $out;
		}

		/*
		/* Does this call_crew_map row have TIMES KEYED against it?
		/*
		/* THE SECOND OF THE TWO ROUTES A CALL'S TIMES CAN ARRIVE BY, and the one
		/* this file did not know about until 10 Sep 2026. Times reach SmartStaff
		/* either as a boss SUBMISSION (call_time_submissions) or KEYED straight
		/* into call_crew_map — by Ops in THE GOAT's grid, by SmartStaff's own call
		/* form, or by the Google Form timesheet import, which is how they actually
		/* arrive today. A reader that knows only about submissions reports a
		/* fully-entered call as entirely outstanding: on 8 Sep that was nine calls,
		/* 111 of 112 crew keyed, a badge saying all of them, and five escalations
		/* to one crew boss in fifteen hours.
		/*
		/* THIS IS THE SINGLE IMPLEMENTATION OF THE KEYED TEST.
		/* ops-times-outstanding.php had it inline first and now calls this. Do not
		/* write a third copy — two copies is exactly what produced the defect this
		/* exists to fix.
		/*
		/* IT DIFFERS FROM THE OLD INLINE COPY IN ONE CASE, DELIBERATELY. That one
		/* read an empty column as keyed, because '' is not '00:00:00'. A row with
		/* nothing in it is the opposite of a row with times in it. Here a row
		/* counts only when at least one end holds a real, non-zero time.
		/*
		/* On live data the two agree: `on` and `off` are both `time NOT NULL`
		/* (verified on prod, 10 Sep 2026), so the empty case is unreachable and the
		/* guard is insurance rather than behaviour.
		*/

		function goat_time_is_set($v)
		{
			$v = trim((string) $v);

			if ($v === '')         return false;
			if ($v === '00:00:00') return false;
			if ($v === '00:00')    return false;

			return true;
		}

		function goat_times_keyed($on, $off)
		{
			if (goat_time_is_set($on))  return true;
			if (goat_time_is_set($off)) return true;

			return false;
		}

		/*
		/* callID -> how many CONFIRMED crew members have NO TIMES BY EITHER ROUTE
		/* — no live submission AND nothing keyed in call_crew_map.
		/*
		/* THIS IS THE SINGLE IMPLEMENTATION OF "who still owes times".
		/* goat_calls_awaiting_times() below is the boolean form of the same
		/* question and DELEGATES here rather than asking it again. Two
		/* implementations would eventually disagree, and the disagreement would
		/* surface as a badge saying "4 still to enter" beside a list showing none
		/* — which reads as a broken page rather than a bug.
		/*
		/* ITS TWO READERS ARE CREW-FACING: the "still to enter" badge on Your Crew
		/* (my-boss-calls.php) and the push cron (calls-awaiting-times.php). Both
		/* are corrected by editing this function; neither needs a deploy of its
		/* own. That is the whole reason the helper exists.
		/*
		/* A CALL Ops HAVE TICKED IS ABSENT FROM THE RESULT, not zero — both
		/* callers already read a missing entry as 0. times_filled is THE
		/* AUTHORITATIVE DONE-TICK (Mike, 10 Sep 2026) and is checked first.
		/*
		/* IT IS NOT THE ONLY TEST, AND THE REASON MATTERS. times_filled is an OPS
		/* REVIEW FLAG: update-call-times.php deliberately never sets it, so only an
		/* accept in THE GOAT or a hand-tick on SmartStaff's call form does. This
		/* function's readers chase the BOSS. Gating his prompt on a flag only Ops
		/* can set means nagging him for work Ops have not got to, which he cannot
		/* clear by doing anything. Call 38370 on 8 Sep is the case in point: 48 of
		/* 48 crew keyed, times_filled = 0, escalated at 03:00. So a call falls
		/* through to the per-person test when the tick is absent.
		/*
		/* Takes an ARRAY so a caller can pass a boss's whole scope in one pass
		/* rather than N. Returns array() for empty input — the guard is not
		/* politeness, an empty IN () list is a SQL syntax error.
		/*
		/* A call with NO confirmed crew counts 0, never "awaiting". There is
		/* nobody whose times are missing, so reporting it would put a permanent
		/* badge on every unstaffed call.
		/*
		/* ONLY CONFIRMED CREW (status 5) ARE COUNTED. A standby who did not work
		/* has no times to enter; counting them would mean the badge never reached
		/* zero. An unbooked person is not in call_crew_map at all, so they cannot
		/* be "missing" — they are known only because a boss typed them in.
		/*
		/* A VOIDED submission does not count as done: voiding is how a boss
		/* retracts an entry, and the call goes back to awaiting — unless the times
		/* are keyed, which is a statement by someone else and stands on its own. A
		/* SUPERSEDED one does count: the person was submitted for, then corrected.
		*/

		function goat_outstanding_by_call($callIDs)
		{
			$out = array();

			foreach (goat_outstanding_people_by_call($callIDs) as $cid => $people)
			{
				$out[$cid] = count($people);
			}

			return $out;
		}

		/*
		/* callID -> the userIDs (ascending) of the confirmed crew who owe times
		/* by NEITHER route. The per-person form of goat_outstanding_by_call()
		/* above — which now COUNTS THIS, so the two cannot disagree. Every rule
		/* in the comment above applies here unchanged: ticked calls absent,
		/* calls with no confirmed crew present with an empty list, unanswered
		/* promotions not owed, voided submissions not done, array() on failure.
		/*
		/* ADDED in slice B of BRIEF-crew-self-times for the push cron's tier
		/* `self` (each crew member is prompted for their own), which is why
		/* the per-person list lives here and not in supervision-graph.php.
		*/

		function goat_outstanding_people_by_call($callIDs)
		{
			$out = array();

			if (!is_array($callIDs) || count($callIDs) === 0)
			{
				return $out;
			}

			/* dedupe and sanitise; every id is cast, no string reaches SQL */

			$ids = array();

			foreach ($callIDs as $cid)
			{
				$cid = (int) $cid;

				if ($cid > 0)
				{
					$ids[$cid] = true;
				}
			}

			if (count($ids) === 0)
			{
				return $out;
			}

			$idList = implode(',', array_map('intval', array_keys($ids)));

			/* 0. calls Ops have already ticked — dropped before anything else runs
			      on them. Cast in PHP, never compared in SQL: these flags are
			      written by SmartStaff's own form and "= 1" is not reliable. */

			$filled = array();

			$fres = mysql_query("SELECT id, times_filled
			                     FROM calls
			                     WHERE id IN (" . $idList . ")");

			if ($fres === false)
			{
				return $out;
			}

			while ($row = mysql_fetch_object($fres))
			{
				if ((int) $row->times_filled === 1)
				{
					$filled[(int) $row->id] = true;
				}
			}

			/* 1. confirmed crew per call, and which of them already have times
			      KEYED against them — one query, not two */

			$crew  = array();
			$keyed = array();

			/* An UNANSWERED PROMOTION is not owed: a backup promoted to 5 who
			      has not accepted can have no times entered at all (15 Oct 2026,
			      submit-call-times.php and update-call-times.php both refuse), so
			      counting them would leave the badge and the push chasing the
			      boss for a row the form will not let them fill. */

			$cres = mysql_query("SELECT ccm.callID AS callID, ccm.userID AS userID,
			                            ccm.`on`   AS on_time, ccm.`off` AS off_time
			                     FROM call_crew_map ccm
			                     INNER JOIN calls c ON c.id = ccm.callID
			                     LEFT JOIN call_promo_ack cpa
			                            ON cpa.callID = ccm.callID
			                           AND cpa.userID = ccm.userID
			                           AND cpa.acked_at IS NULL
			                     WHERE ccm.callID IN (" . $idList . ")
			                       AND ccm.status = 5
			                       AND cpa.id IS NULL");

			if ($cres === false)
			{
				return $out;
			}

			while ($row = mysql_fetch_object($cres))
			{
				$cid = (int) $row->callID;
				$uid = (int) $row->userID;

				if ($cid <= 0 || $uid <= 0)
				{
					continue;
				}

				if (isset($filled[$cid]))
				{
					continue;
				}

				if (!isset($crew[$cid]))
				{
					$crew[$cid]  = array();
					$keyed[$cid] = array();
				}

				$crew[$cid][$uid] = true;

				if (goat_times_keyed($row->on_time, $row->off_time))
				{
					$keyed[$cid][$uid] = true;
				}
			}

			/* 2. who already has a live submission.

			      A NO-SHOW REPORT COUNTS AS ANSWERED HERE (BRIEF-boss-no-show,
			      slice 1), and that is deliberate, not an accident of the query.

			      WHY: this function answers "what is the BOSS still owing", and
			      for a person who did not turn up the boss owes nothing more —
			      there are no times to enter, and "they weren't here" is the
			      whole answer. Counting them as owed would leave the badge, the
			      push cron and Crew Hub's list chasing the boss for a row they
			      have already filled in and cannot fill any other way. Whether
			      the report is TRUE is an Ops question, and it is held open
			      somewhere else: accept-call-times.php refuses the tick and the
			      Times outstanding lane keeps the call until Ops resolve it.

			      HOW: it needs no clause of its own. A report is a live row like
			      any other, so it lands in $done below. If Ops REJECT it, the row
			      is voided and the person is owed again; if they CONFIRM it,
			      status 8 takes the person off the roster in step 1. Do NOT add
			      "AND no_show = 0" here. */

			$done = array();

			$sres = mysql_query("SELECT s.callID AS callID, s.userID AS userID
			                     FROM call_time_submissions s
			                     INNER JOIN calls c ON c.id = s.callID
			                     WHERE s.callID IN (" . $idList . ")
			                       AND s.voided = 0
			                       AND s.userID > 0");

			if ($sres === false)
			{
				return $out;
			}

			while ($row = mysql_fetch_object($sres))
			{
				$cid = (int) $row->callID;
				$uid = (int) $row->userID;

				if (!isset($done[$cid]))
				{
					$done[$cid] = array();
				}

				$done[$cid][$uid] = true;
			}

			/* 3. the confirmed crew with times by NEITHER route */

			foreach ($crew as $cid => $members)
			{
				$owed = array();

				foreach ($members as $uid => $ignored)
				{
					if (isset($done[$cid][$uid]))  continue;
					if (isset($keyed[$cid][$uid])) continue;

					$owed[] = (int) $uid;
				}

				sort($owed);

				$out[$cid] = $owed;
			}

			return $out;
		}

		/*
		/* Given call ids, which of them have NO live submission for at least
		/* one confirmed crew member. The BOOLEAN form of
		/* goat_outstanding_by_call(), and it delegates rather than asking the
		/* question a second time.
		/*
		/* Input order is preserved in the output so callers can zip the result
		/* against what they passed.
		*/

		function goat_calls_awaiting_times($callIDs)
		{
			$out = array();

			if (!is_array($callIDs) || count($callIDs) === 0)
			{
				return $out;
			}

			$counts = goat_outstanding_by_call($callIDs);
			$seen   = array();

			foreach ($callIDs as $cid)
			{
				$cid = (int) $cid;

				if ($cid <= 0 || isset($seen[$cid]))
				{
					continue;
				}

				if (isset($counts[$cid]) && $counts[$cid] > 0)
				{
					$seen[$cid] = true;
					$out[] = $cid;
				}
			}

			return $out;
		}

		/*
		/* Given call ids, WHO HAS A LIVE SUBMISSION on each.
		/*
		/* The complement of goat_outstanding_by_call(), and the Ops lane needs
		/* both: outstanding says how much work is left, this says whether any
		/* of it has ARRIVED. A call with nothing submitted and a call with
		/* half submitted are the same number on the badge and completely
		/* different actions — one is "chase the boss", the other is "review
		/* what came in".
		/*
		/* SAME PREDICATE AS goat_outstanding_by_call()'s `$done` SET, and it
		/* has to stay that way: voided = 0 (a retraction returns the call to
		/* awaiting) and userID > 0. If one of them ever gains a condition the
		/* other lacks, a call will show as submitted while still counting as
		/* outstanding for the same person.
		/*
		/* NO supersedes RESOLUTION IS NEEDED. This counts DISTINCT people with
		/* at least one live row; which of their rows is the live one is
		/* goat_time_submission_for()'s question, and asking it here would be a
		/* second opinion about the same thing.
		/*
		/* THE SET CAN REACH BEYOND THE CONFIRMED ROSTER. An unbooked row is a
		/* submission for someone who is not in call_crew_map for the call
		/* (design §7), and it belongs here — it is a real claim someone has to
		/* review. Callers rendering "N of M submitted" against the roster must
		/* intersect first; count() alone will overstate it.
		/*
		/* RETURN SHAPE matches goat_bosses_by_call() rather than this file's
		/* array()-on-failure rule, for the same reason: zero submissions and a
		/* broken query are opposite answers here, and a lane that quietly
		/* labelled every call "not submitted" because a query failed would
		/* send Ops chasing bosses who had already done the work.
		/*
		/*   array('ok' => bool, 'error' => string,
		/*         'by_call' => array(callID => array(userID => true)))
		/*
		/* A SET, not a count, because the one caller that matters has to
		/* intersect it with the roster and with who already has times keyed in
		/* call_crew_map. count() on an entry gives the number back.
		/*
		/* Calls with no submissions at all are ABSENT from by_call, exactly as
		/* goat_outstanding_by_call() omits calls with no confirmed crew.
		*/

		function goat_submitted_by_call($callIDs)
		{
			$none = array('ok' => true, 'error' => '', 'by_call' => array());

			if (!is_array($callIDs) || count($callIDs) === 0)
			{
				return $none;
			}

			/* dedupe and sanitise; every id is cast, no string reaches SQL */

			$ids = array();

			foreach ($callIDs as $cid)
			{
				$cid = (int) $cid;

				if ($cid > 0)
				{
					$ids[$cid] = true;
				}
			}

			if (count($ids) === 0)
			{
				return $none;
			}

			$idList = implode(',', array_map('intval', array_keys($ids)));

			$res = mysql_query("SELECT s.callID AS callID, s.userID AS userID
			                    FROM call_time_submissions s
			                    INNER JOIN calls c ON c.id = s.callID
			                    WHERE s.callID IN (" . $idList . ")
			                      AND s.voided = 0
			                      AND s.userID > 0");

			if ($res === false)
			{
				return array('ok'    => false,
				             'error' => 'submission lookup failed: ' . mysql_error(),
				             'by_call' => array());
			}

			$seen = array();   /* callID -> userID -> true, so a correction counts once */

			while ($row = mysql_fetch_object($res))
			{
				$cid = (int) $row->callID;
				$uid = (int) $row->userID;

				if ($cid <= 0 || $uid <= 0)
				{
					continue;
				}

				if (!isset($seen[$cid]))
				{
					$seen[$cid] = array();
				}

				$seen[$cid][$uid] = true;
			}

			return array('ok' => true, 'error' => '', 'by_call' => $seen);
		}

	}

	/*
	/* ================================================================
	/* SUBMISSION VALIDATION AND THE APPEND-ONLY WRITE — ONE COPY.
	/* Slice D of BRIEF-crew-self-times (§5.1), Nov 2026.
	/* ================================================================
	/*
	/* MOVED HERE FROM submit-call-times.php, UNCHANGED IN BEHAVIOUR, so the
	/* crew self-submit endpoint (submit-my-times.php) validates and writes
	/* through exactly the rules the boss endpoint does. Two copies of payroll
	/* validation is the failure goat_times_keyed() was written to end. The
	/* error strings, their order and the 409 warning contract are those of
	/* the boss endpoint as it stood at ac00bfd; change one here and both
	/* endpoints change.
	/*
	/* NONE OF THESE FUNCTIONS EMITS A RESPONSE. Each returns what it found
	/* and the endpoint decides the status code, so the boss endpoint keeps
	/* its own sct_fail() body shape and the self endpoint can differ.
	*/

	if (!function_exists('goat_validate_submission_row'))
	{

		/*
		/* Is call_time_submissions.source present? Added by slice A. Static per
		/* request. Absent, a 'boss' write omits the column (the DEFAULT is
		/* 'boss' once it exists, so nothing is lost) and a 'self' write is
		/* refused: a self submission without its provenance would be
		/* indistinguishable from a boss one, which is the one thing Ops must
		/* be able to tell at a glance.
		*/

		function goat_ts_have_source()
		{
			static $has = null;

			if ($has === null)
			{
				$r   = mysql_query("SHOW COLUMNS FROM call_time_submissions LIKE 'source'");
				$has = ($r !== false && mysql_num_rows($r) > 0);
			}

			return $has;
		}

		/*
		/* Is call_time_submissions.no_show present? Added by slice 1 of
		/* BRIEF-boss-no-show (Nov 2026). Same shape as goat_ts_have_source(), so
		/* this PHP deploys safely BEFORE the ALTER: until the column exists every
		/* read treats every row as no_show = 0 (which is what every row is), and
		/* a write that asks for a No-show is refused with a 500 rather than
		/* silently stored as a timeless boss row.
		*/

		function goat_ts_have_no_show()
		{
			static $has = null;

			if ($has === null)
			{
				$r   = mysql_query("SHOW COLUMNS FROM call_time_submissions LIKE 'no_show'");
				$has = ($r !== false && mysql_num_rows($r) > 0);
			}

			return $has;
		}

		/*
		/* HH:MM or HH:MM:SS -> HH:MM:SS. '' when the input is not a valid clock
		/* time. Deliberately rejects 24:00 and anything with a stray character:
		/* the column is TIME, which would happily accept '25:00' and even
		/* '838:59:59', and a value nothing else in the estate reads that way is
		/* worse than a rejection the boss can see and correct.
		/*
		/* Was sct_time() in submit-call-times.php.
		*/

		function goat_sub_time($v)
		{
			if (!is_string($v) && !is_numeric($v))
			{
				return '';
			}

			$v = trim((string) $v);

			if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])(:([0-5][0-9]))?$/', $v))
			{
				return '';
			}

			if (strlen($v) === 5)
			{
				$v .= ':00';
			}

			return $v;
		}

		/*
		/* 'HH:MM:SS' + a next-day flag -> minutes from midnight on the shift's
		/* FIRST day. This is what makes the break-window test work across
		/* midnight: a shift 17:00 -> 00:30 next day is 1020 -> 1470, and a break
		/* at 00:10 next day is 1450, which is inside it. Comparing raw clock
		/* readings would put that break four hours before the shift started.
		/*
		/* Was sct_minutes() in submit-call-times.php.
		*/

		function goat_sub_minutes($hms, $nextDay)
		{
			$parts = explode(':', $hms);

			if (count($parts) < 2)
			{
				return -1;
			}

			return ((int) $parts[0]) * 60 + ((int) $parts[1]) + ($nextDay ? 1440 : 0);
		}

		/*
		/* VALIDATE ONE SUBMISSION ROW.
		/*
		/*   $row           the decoded row object, in the boss endpoint's shape
		/*                  (userID, unbooked, ein, unbooked_name, covering_for,
		/*                  on_time, off_time, off_next_day, note, breaks[])
		/*   $i             its index, carried into the clean row
		/*   $label         the prefix every error starts with ('row 0', ...)
		/*   $confirmed     userID => true, CONFIRMED (status 5) on the call
		/*   $promoPending  userID => true, unanswered promotion on the call
		/*
		/* Returns array(
		/*   'errors' => list of strings, in the order the boss endpoint found them,
		/*   'clean'  => the normalised row, or null when the row is not an object,
		/*   'fatal'  => null, or array(code, status, message, extra) for a lookup
		/*               that FAILED — the caller must stop with it at once,
		/*               exactly as the boss endpoint used to mid-loop
		/* )
		/*
		/* The self endpoint builds $row itself from the actor's own times, with
		/* unbooked 0 and no covering_for, so the unbooked/EIN branch below is
		/* unreachable from it by construction.
		*/

		function goat_validate_submission_row($row, $i, $label, $confirmed, $promoPending)
		{
			$errors = array();

			if (!is_object($row))
			{
				$errors[] = $label . ': not an object';
				return array('errors' => $errors, 'clean' => null, 'fatal' => null);
			}

			$unbooked = (isset($row->unbooked) && $row->unbooked) ? 1 : 0;
			$userID   = isset($row->userID) ? (int) $row->userID : 0;
			$uname    = isset($row->unbooked_name) ? trim((string) $row->unbooked_name) : '';
			$note     = isset($row->note) ? trim((string) $row->note) : '';
			$cover    = isset($row->covering_for) ? (int) $row->covering_for : 0;

			/*
			/* THE LINE THAT STOPS A BOSS RECORDING HOURS FOR SOMEONE WHO WAS NEVER
			/* ON THE JOB. A booked row must name a userID confirmed (status 5) on
			/* this call. Unbooked rows are exempt BY DEFINITION — the whole point
			/* is that they are not in call_crew_map — which is why unbooked = 1 is
			/* the only way past this check, and why it is never inferred.
			*/

			if (!$unbooked)
			{
				if ($userID <= 0)
				{
					$errors[] = $label . ': userID required unless unbooked';
				}
				else if (!isset($confirmed[$userID]))
				{
					$errors[] = $label . ': userID ' . $userID . ' is not confirmed on this call';
				}
			}
			else
			{
				/*
				/* UNBOOKED: the EIN is the identity, and it is required. See
				/* submit-call-times.php's header for why an unresolved EIN is a
				/* control and not fussy validation.
				*/

				$ein = isset($row->ein) ? (int) $row->ein : 0;

				if ($ein <= 0)
				{
					$errors[] = $label . ': an unbooked row requires an ein';
				}
				else
				{
					$ures = mysql_query("SELECT id FROM users WHERE ein = " . $ein . " LIMIT 1");

					/*
					/* A FAILED LOOKUP IS A 500, NOT "no such EIN". Reporting a
					/* broken query as an unresolved number would tell the boss
					/* their crew member is not onboarded, which is both false and
					/* alarming — and is the same shape as the ccm.id / crewmapID
					/* failure in call-supervision.php on 18 Aug.
					*/

					if ($ures === false)
					{
						return array(
							'errors' => $errors,
							'clean'  => null,
							'fatal'  => array(500, 'Internal Server Error',
							                  'EIN lookup failed: ' . mysql_error(), array('row' => $i))
						);
					}

					$urow = mysql_fetch_object($ures);

					if (!$urow)
					{
						$errors[] = $label . ': no crew member found with EIN ' . $ein;
					}
					else
					{
						$userID = (int) $urow->id;
					}
				}

				/*
				/* unbooked_name is NO LONGER ACCEPTED. Rejected rather than
				/* ignored, so a client built against the superseded contract is
				/* told loudly rather than having the boss's typing silently
				/* dropped. (Q12c: decided as reject.)
				*/

				if ($uname !== '')
				{
					$errors[] = $label . ': unbooked_name is no longer accepted — supply ein instead';
				}
			}

			if ($userID > 0 && isset($confirmed[$userID]) && isset($promoPending[$userID]))
			{
				$errors[] = $label . ': has not accepted their promotion to this call yet, so times cannot be entered for them. If they worked, contact Gig Power Operations';
			}

			if (strlen($note) > 255)
			{
				$errors[] = $label . ': note exceeds 255 characters';
			}

			if ($cover > 0 && !isset($confirmed[$cover]))
			{
				$errors[] = $label . ': covering_for ' . $cover . ' is not confirmed on this call';
			}

			/*
			/* ---- NO-SHOW (BRIEF-boss-no-show, slice 1) ----
			/*
			/* The boss saying "this person did not turn up", in place of times.
			/* A REPORT, not a status change: the person stays status 5 until Ops
			/* confirm (status 8, update-crew-status.php) or reject (void the row).
			/*
			/*   - BOOKED ROWS ONLY. An unbooked row exists because the person
			/*     worked; an unbooked No-show is a contradiction.
			/*   - NO on/off, NO breaks. A No-show with times is two answers to one
			/*     question, and refusing it beats guessing which the boss meant.
			/*   - The note stays optional (N5).
			/*
			/* Returns here so none of the time and break validation below runs
			/* on a row that has no times by design. Every check ABOVE this point
			/* (confirmed, promotion pending, note length, covering_for) still
			/* applies. The self-reporting refusal lives in the endpoints: the
			/* boss endpoint refuses the boss's own row, the self endpoint refuses
			/* the field outright.
			*/

			$noShow = (isset($row->no_show) && $row->no_show) ? 1 : 0;

			if ($noShow)
			{
				if ($unbooked)
				{
					$errors[] = $label . ': a No-show must be a booked crew member';
				}

				$hasOn     = isset($row->on_time)  && trim((string) $row->on_time)  !== '';
				$hasOff    = isset($row->off_time) && trim((string) $row->off_time) !== '';
				$hasBreaks = isset($row->breaks) && is_array($row->breaks) && count($row->breaks) > 0;

				if ($hasOn || $hasOff || $hasBreaks)
				{
					$errors[] = $label . ': a No-show row must not carry on/off times or breaks';
				}

				$clean = array(
					'index'          => $i,
					'userID'         => $userID,
					'unbooked'       => $unbooked,
					'unbooked_name'  => $uname,
					'covering_for'   => $cover,
					'on_time'        => '',
					'off_time'       => '',
					'off_next_day'   => 0,
					'note'           => $note,
					'breaks'         => array(),
					'no_show'        => 1
				);

				return array('errors' => $errors, 'clean' => $clean, 'fatal' => null);
			}

			$on  = goat_sub_time(isset($row->on_time)  ? $row->on_time  : '');
			$off = goat_sub_time(isset($row->off_time) ? $row->off_time : '');

			if ($on === '')
			{
				$errors[] = $label . ': on_time must be HH:MM or HH:MM:SS';
			}

			if ($off === '')
			{
				$errors[] = $label . ': off_time must be HH:MM or HH:MM:SS';
			}

			/*
			/* off_next_day IS TAKEN FROM THE CLIENT AND NEVER INFERRED. The form
			/* defaults it when the typed finish is earlier than the start and lets
			/* the person correct it. Recomputing it here would silently override a
			/* deliberate correction — a 17:00 start finishing 23:00 THE NEXT DAY
			/* is unusual but legal.
			*/

			$offNext = (isset($row->off_next_day) && $row->off_next_day) ? 1 : 0;

			/*
			/* ---- the shift window, for the break test below (Q37) ----
			/*
			/* Only computable when both times parsed. A window that does not move
			/* forward is reported as such rather than left to reject every break
			/* with a misleading "outside the shift" — off_next_day is the field
			/* actually got wrong in that case, and the message says so.
			*/

			$onMin  = ($on  === '') ? -1 : goat_sub_minutes($on, 0);
			$offMin = ($off === '') ? -1 : goat_sub_minutes($off, $offNext);

			if ($onMin >= 0 && $offMin >= 0 && $offMin <= $onMin)
			{
				$errors[] = $label . ': off_time is not after on_time — set off_next_day if the shift runs past midnight';
			}

			/* ---- breaks ---- */

			$breaks    = array();
			$rawBreaks = (isset($row->breaks) && is_array($row->breaks)) ? $row->breaks : array();

			foreach ($rawBreaks as $bi => $b)
			{
				if (!is_object($b))
				{
					$errors[] = $label . ' break ' . $bi . ': not an object';
					continue;
				}

				$bs = goat_sub_time(isset($b->start_time) ? $b->start_time : '');
				$dm = isset($b->duration_mins) ? (int) $b->duration_mins : 0;

				/* start_time is required on a break row: slice 3 has to place the
				/* break inside the shift to split day from night, and a break with
				/* no position cannot be split. */

				if ($bs === '')
				{
					$errors[] = $label . ' break ' . $bi . ': start_time must be HH:MM or HH:MM:SS';
				}

				/*
				/* MULTIPLES OF 15, AND ABOVE ZERO. This is the fix for '00:75'.
				/* call_crew_map.break is varchar(255) holding values typed past an
				/* input with no validation; '00:75' is live and means FORTY-FIVE
				/* minutes, not 75 and not 1h15. Constraining the input is what
				/* stops the next decade of that.
				*/

				if ($dm <= 0)
				{
					$errors[] = $label . ' break ' . $bi . ': duration_mins must be greater than 0';
				}
				else if (($dm % 15) !== 0)
				{
					$errors[] = $label . ' break ' . $bi . ': duration_mins must be a multiple of 15';
				}

				$bNext = (isset($b->start_next_day) && $b->start_next_day) ? 1 : 0;

				/*
				/* Q37, CLOSED 26 Aug 2026: A BREAK OUTSIDE THE SHIFT IS REJECTED,
				/* NOT SILENTLY DROPPED. A break is time not worked WITHIN a shift;
				/* one outside it is a typo worth surfacing while the form is still
				/* open. Both ends are next-day aware, or every overnight shift
				/* would reject its own perfectly valid breaks.
				*/

				if ($bs !== '' && $onMin >= 0 && $offMin > $onMin && $dm > 0)
				{
					$bStart = goat_sub_minutes($bs, $bNext);
					$bEnd   = $bStart + $dm;

					if ($bStart < $onMin || $bEnd > $offMin)
					{
						$errors[] = $label . ' break ' . $bi . ': falls outside the shift ('
						          . substr($on, 0, 5) . '-' . substr($off, 0, 5)
						          . ($offNext ? ' next day' : '') . ')';
					}
				}

				$breaks[] = array(
					'start_time'     => $bs,
					'start_next_day' => $bNext,
					'duration_mins'  => $dm
				);
			}

			$clean = array(
				'index'          => $i,
				'userID'         => $userID,
				'unbooked'       => $unbooked,
				'unbooked_name'  => $uname,
				'covering_for'   => $cover,
				'on_time'        => $on,
				'off_time'       => $off,
				'off_next_day'   => $offNext,
				'note'           => $note,
				'breaks'         => $breaks,
				'no_show'        => 0
			);

			return array('errors' => $errors, 'clean' => $clean, 'fatal' => null);
		}

		/*
		/* THE 60-MINUTE WARNING — overridable with confirm:true; the caller
		/* turns a non-empty list into a 409 {needs_confirm, warnings}.
		/*
		/* PER BREAK, NOT PER DAY'S TOTAL. Two 45-minute breaks is a legitimate
		/* 90 minutes and neither trips this; one 90-minute break does.
		/*
		/* THE ENDPOINT RETURNS FACTS, NOT JOKES. Any light line is the client's,
		/* amused at the DURATION, never at the PERSON — a long break sometimes
		/* means someone was unwell or there was an incident.
		*/

		function goat_submission_long_break_warnings($cleanRows)
		{
			$warnings = array();

			foreach ($cleanRows as $c)
			{
				/* a No-show row has no breaks by validation; skipped by name too,
				/* so the warning can never be about someone who was not there */

				if (isset($c['no_show']) && $c['no_show'])
				{
					continue;
				}

				$seq = 1;

				foreach ($c['breaks'] as $b)
				{
					if ($b['duration_mins'] > 60)
					{
						$warnings[] = array(
							'type'          => 'long_break',
							'row'           => $c['index'],
							'seq'           => $seq,
							'duration_mins' => $b['duration_mins']
						);
					}

					$seq++;
				}
			}

			return $warnings;
		}

		/*
		/* Section 5.2's failure path. MyISAM has no transactions, so a submission
		/* whose break rows failed to land is marked voided rather than deleted —
		/* append-only means the evidence of the failure survives, and every helper
		/* in this file already excludes voided rows. THE ONLY UPDATE IN THIS
		/* FILE, and it changes no typed value.
		/*
		/* Was sct_void() in submit-call-times.php.
		*/

		function goat_void_submission($submissionID)
		{
			mysql_query("UPDATE call_time_submissions
			             SET voided = 1
			             WHERE id = " . (int) $submissionID);
		}

		/*
		/* WRITE ONE VALIDATED ROW, APPEND-ONLY, THEN COUNT ITS BREAKS BACK.
		/*
		/*   $callID   the call
		/*   $c        a 'clean' row from goat_validate_submission_row()
		/*   $actor    submitted_by — the person typing
		/*   $source   'boss' | 'self' — call_time_submissions.source (slice A)
		/*
		/* Returns array('ok' => true, 'submission_id', 'supersedes_id', 'breaks')
		/* or array('ok' => false, 'code', 'status', 'error', 'extra') — the
		/* caller emits it. Nothing is ever rolled back: a row already written
		/* stays, and a submission whose breaks did not all land is VOIDED, not
		/* deleted.
		*/

		function goat_write_submission($callID, $c, $actor, $source)
		{
			global $db;

			$callID = (int) $callID;

			$source = ($source === 'self') ? 'self' : 'boss';

			$fail = array('ok' => false, 'code' => 500, 'status' => 'Internal Server Error',
			              'error' => '', 'extra' => array('row' => $c['index']));

			$haveSource = goat_ts_have_source();

			if (!$haveSource && $source !== 'boss')
			{
				$fail['error'] = 'call_time_submissions.source is missing — run slice A before self submissions';
				return $fail;
			}

			/*
			/* NO-SHOW (BRIEF-boss-no-show). Without the column a No-show would be
			/* stored as a boss row with no times — indistinguishable from a broken
			/* client — so it is refused, as a self row is without `source`.
			/* Absent the column, an ordinary row omits it; nothing is lost because
			/* the DEFAULT is 0 once it exists.
			*/

			$noShow     = (isset($c['no_show']) && $c['no_show']) ? 1 : 0;
			$haveNoShow = goat_ts_have_no_show();

			if (!$haveNoShow && $noShow)
			{
				$fail['error'] = 'call_time_submissions.no_show is missing — run the boss-no-show migration before No-show reports';
				return $fail;
			}

			/*
			/* supersedes_id IS NOT OPTIONAL. goat_time_submissions_for_call()
			/* carries a second dedupe pass in PHP specifically in case this line
			/* is ever missed: without it the SQL NOT EXISTS returns both rows for
			/* one person while goat_time_submission_for() returns one, and two
			/* helpers disagreeing about who is live surfaces as a DUPLICATED CREW
			/* MEMBER ON A TIMESHEET rather than as an error.
			/*
			/* A self submission supersedes WHATEVER is live for the person —
			/* including a boss row. Highest id wins, as everywhere in this file.
			/*
			/* userID = 0 resolves to 0 — see Q34 in submit-call-times.php.
			*/

			$supersedes = 0;

			if ((int) $c['userID'] > 0)
			{
				$prev = goat_time_submission_for($callID, (int) $c['userID']);

				if (isset($prev['id']))
				{
					$supersedes = (int) $prev['id'];
				}
			}

			/* unbooked_name is never written — decision 21 replaced it with the
			/* EIN-resolved userID. The column remains, nullable and unused. */

			$nameSql = 'NULL';
			$noteSql = ($c['note'] === '') ? 'NULL' : $db->sc($c['note']);

			$srcCol = $haveSource ? ', source' : '';
			$srcVal = $haveSource ? (', ' . $db->sc($source)) : '';

			/* a No-show has no times: on_time / off_time are NULL (both columns
			/* are TIME NULL), never '' or '00:00:00', which a TIME column would
			/* store as midnight and a reader would take for a real time */

			$onSql  = $noShow ? 'NULL' : $db->sc($c['on_time']);
			$offSql = $noShow ? 'NULL' : $db->sc($c['off_time']);

			$nsCol = $haveNoShow ? ', no_show' : '';
			$nsVal = $haveNoShow ? (', ' . (int) $noShow) : '';

			$sql = "INSERT INTO call_time_submissions
			        (callID, userID, unbooked, unbooked_name, covering_for,
			         on_time, off_time, off_next_day, note,
			         submitted_by, submitted_at, supersedes_id, voided" . $srcCol . $nsCol . ")
			        VALUES (
			          " . $callID . ",
			          " . (int) $c['userID'] . ",
			          " . (int) $c['unbooked'] . ",
			          " . $nameSql . ",
			          " . (int) $c['covering_for'] . ",
			          " . $onSql . ",
			          " . $offSql . ",
			          " . (int) $c['off_next_day'] . ",
			          " . $noteSql . ",
			          " . (int) $actor . ",
			          NOW(),
			          " . (int) $supersedes . ",
			          0" . $srcVal . $nsVal . "
			        )";

			if (mysql_query($sql) === false)
			{
				$fail['error'] = 'submission insert failed: ' . mysql_error();
				return $fail;
			}

			$subID = (int) mysql_insert_id();

			if ($subID <= 0)
			{
				$fail['error'] = 'submission insert returned no id';
				return $fail;
			}

			/*
			/* ---- BREAKS, THEN COUNT THEM BACK ----
			/*
			/* MyISAM gives no transaction. The submission is inserted first
			/* because the break rows need its id, so a failed break insert leaves
			/* a submission WITH NO BREAKS — and zero break rows is a VALID,
			/* COMMON state meaning "no break taken". The failure is therefore
			/* invisible by absence: a silent wrong number in a payroll input. So:
			/* count them back, and void on mismatch.
			*/

			/* a No-show writes no breaks — the validator already gave it none;
			/* this makes the write not depend on that */

			$toWrite  = $noShow ? array() : $c['breaks'];
			$seq      = 1;
			$expected = count($toWrite);

			foreach ($toWrite as $b)
			{
				$bsql = "INSERT INTO call_time_submission_breaks
				         (submission_id, start_time, start_next_day, duration_mins, seq)
				         VALUES (
				           " . $subID . ",
				           " . $db->sc($b['start_time']) . ",
				           " . (int) $b['start_next_day'] . ",
				           " . (int) $b['duration_mins'] . ",
				           " . $seq . "
				         )";

				if (mysql_query($bsql) === false)
				{
					/*
					/* mysql_error() is read AFTER the void, as it always was in
					/* submit-call-times.php — so it reports the void UPDATE's
					/* error (normally empty), not the failed break INSERT's.
					/* Preserved deliberately: slice D moves this code without
					/* changing the boss endpoint's responses. Capturing the
					/* insert's error first is a one-line fix worth its own
					/* change.
					*/

					goat_void_submission($subID);
					$fail['error'] = 'break insert failed, submission voided: ' . mysql_error();
					$fail['extra'] = array('row' => $c['index'], 'submission_id' => $subID);
					return $fail;
				}

				$seq++;
			}

			$bres = mysql_query("SELECT COUNT(*) AS n FROM call_time_submission_breaks
			                     WHERE submission_id = " . $subID);

			if ($bres === false)
			{
				goat_void_submission($subID);
				$fail['error'] = 'break count-back failed, submission voided';
				$fail['extra'] = array('row' => $c['index'], 'submission_id' => $subID);
				return $fail;
			}

			$brow   = mysql_fetch_object($bres);
			$actual = $brow ? (int) $brow->n : -1;

			if ($actual !== $expected)
			{
				goat_void_submission($subID);
				$fail['error'] = 'break count mismatch (' . $actual . ' of ' . $expected . '), submission voided';
				$fail['extra'] = array('row' => $c['index'], 'submission_id' => $subID);
				return $fail;
			}

			return array(
				'ok'            => true,
				'submission_id' => $subID,
				'supersedes_id' => $supersedes,
				'breaks'        => $expected
			);
		}

		/*
		/* One person's LIVE submission on each of several calls, in one query:
		/* callID => row, highest id per call, voided excluded — the same
		/* answer goat_time_submission_for() gives one call at a time. For
		/* my-shifts.php, which would otherwise ask once per past shift.
		/*
		/* $userID <= 0 returns array(), for the reason
		/* goat_time_submission_for() gives.
		*/

		function goat_live_submissions_for_user($userID, $callIDs)
		{
			$userID = (int) $userID;
			$out    = array();

			if ($userID <= 0 || !is_array($callIDs) || count($callIDs) === 0)
			{
				return $out;
			}

			$ids = array();

			foreach ($callIDs as $cid)
			{
				$cid = (int) $cid;

				if ($cid > 0)
				{
					$ids[$cid] = true;
				}
			}

			if (count($ids) === 0)
			{
				return $out;
			}

			$res = mysql_query("SELECT s.*
			                    FROM call_time_submissions s
			                    INNER JOIN calls c ON c.id = s.callID
			                    WHERE s.callID IN (" . implode(',', array_keys($ids)) . ")
			                      AND s.userID = " . $userID . "
			                      AND s.voided = 0
			                    ORDER BY s.id ASC");

			if ($res === false)
			{
				return $out;
			}

			while ($row = mysql_fetch_assoc($res))
			{
				$out[(int) $row['callID']] = $row;   /* ascending: the last one wins */
			}

			return $out;
		}

		/*
		/* callID -> the UNRESOLVED No-show reports on it (BRIEF-boss-no-show,
		/* slice 1). A report is unresolved when the person's LATEST LIVE row
		/* (highest non-voided id, as goat_time_submission_for() resolves it) has
		/* no_show = 1 AND they are still status 5 on the call:
		/*
		/*   confirmed by Ops -> status 8 -> drops out (not status 5)
		/*   rejected by Ops  -> row voided -> drops out (not live)
		/*   boss resubmits with times -> a later row wins -> drops out
		/*
		/* THIS FEEDS A REFUSAL (accept-call-times.php will not tick times_filled
		/* while any exist — N6), so array()-on-failure, this file's usual rule,
		/* is WRONG here: an empty list from a broken query would wave the accept
		/* through. Same shape as goat_submitted_by_call():
		/*
		/*   array('ok' => bool, 'error' => string,
		/*         'by_call' => array(callID => list of array(
		/*             'userID', 'submission_id', 'reported_by', 'reported_by_name',
		/*             'reported_at', 'note')))
		/*
		/* Calls with none are ABSENT from by_call. Each list is in userID order.
		/*
		/* BEFORE THE MIGRATION the column does not exist, so no report can
		/* exist either: ok with an empty by_call is the true answer, not a
		/* degraded one.
		*/

		function goat_unresolved_no_shows_by_call($callIDs)
		{
			$none = array('ok' => true, 'error' => '', 'by_call' => array());

			if (!is_array($callIDs) || count($callIDs) === 0)
			{
				return $none;
			}

			$ids = array();

			foreach ($callIDs as $cid)
			{
				$cid = (int) $cid;

				if ($cid > 0)
				{
					$ids[$cid] = true;
				}
			}

			if (count($ids) === 0 || !goat_ts_have_no_show())
			{
				return $none;
			}

			$idList = implode(',', array_map('intval', array_keys($ids)));

			/*
			/* Every live row for those calls, ascending, so the last one seen per
			/* (call, person) is the live one — the same resolution as
			/* goat_live_submissions_for_user(). Filtering no_show = 1 in SQL
			/* instead would find a report the boss has since corrected with times.
			/* The status-5 test is a JOIN, not a second query, so the two cannot
			/* be read at different moments.
			*/

			$res = mysql_query("SELECT s.id AS id, s.callID AS callID, s.userID AS userID,
			                           s.no_show AS no_show, s.submitted_by AS submitted_by,
			                           s.submitted_at AS submitted_at, s.note AS note,
			                           ccm.status AS ccm_status
			                    FROM call_time_submissions s
			                    INNER JOIN calls c ON c.id = s.callID
			                    LEFT JOIN call_crew_map ccm
			                           ON ccm.callID = s.callID
			                          AND ccm.userID = s.userID
			                    WHERE s.callID IN (" . $idList . ")
			                      AND s.voided = 0
			                      AND s.userID > 0
			                    ORDER BY s.id ASC");

			if ($res === false)
			{
				return array('ok'      => false,
				             'error'   => 'no-show lookup failed: ' . mysql_error(),
				             'by_call' => array());
			}

			$live = array();   /* callID -> userID -> row */

			while ($row = mysql_fetch_assoc($res))
			{
				$cid = (int) $row['callID'];
				$uid = (int) $row['userID'];

				if (!isset($live[$cid]))
				{
					$live[$cid] = array();
				}

				$live[$cid][$uid] = $row;
			}

			$byCall   = array();
			$reporter = array();

			foreach ($live as $cid => $people)
			{
				ksort($people);

				foreach ($people as $uid => $row)
				{
					if ((int) $row['no_show'] !== 1)       continue;
					if ((int) $row['ccm_status'] !== 5)    continue;

					if (!isset($byCall[$cid]))
					{
						$byCall[$cid] = array();
					}

					$byCall[$cid][] = array(
						'userID'        => (int) $uid,
						'submission_id' => (int) $row['id'],
						'reported_by'   => (int) $row['submitted_by'],
						'reported_at'   => ($row['submitted_at'] === null ? '' : $row['submitted_at']),
						'note'          => ($row['note'] === null ? '' : $row['note'])
					);

					$reporter[(int) $row['submitted_by']] = true;
				}
			}

			/*
			/* Reporter names, for "reported by" on the review row. DEGRADES to ''
			/* on failure: a name is display, and the refusal above is already
			/* decided by the list itself.
			*/

			$names = array();
			unset($reporter[0]);

			if (count($reporter) > 0)
			{
				$nres = mysql_query("SELECT id, firstname, lastname FROM users
				                     WHERE id IN (" . implode(',', array_map('intval', array_keys($reporter))) . ")");

				if ($nres !== false)
				{
					while ($n = mysql_fetch_object($nres))
					{
						$first = html_entity_decode((string) $n->firstname, ENT_QUOTES);
						$last  = html_entity_decode((string) $n->lastname,  ENT_QUOTES);

						$names[(int) $n->id] = trim(trim($first) . ' ' . trim($last));
					}
				}
			}

			foreach ($byCall as $cid => $list)
			{
				foreach ($list as $k => $r)
				{
					$byCall[$cid][$k]['reported_by_name'] =
						isset($names[$r['reported_by']]) ? $names[$r['reported_by']] : '';
				}
			}

			return array('ok' => true, 'error' => '', 'by_call' => $byCall);
		}

	}

?>
