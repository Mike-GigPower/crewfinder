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
	/* NOTHING IN THIS FILE IS REACHABLE YET. Slice 2 writes the rows, slice 3
	/* derives hours from them. It is deliberately inert.
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
		/* Given call ids, which of them have NO live submission for at least
		/* one CONFIRMED crew member. Drives the notification (slice 6) and the
		/* outstanding indicator (slice 4).
		/*
		/* Takes an ARRAY so a caller can pass a boss's whole scope in one
		/* query rather than N. Returns array() for empty input — the guard is
		/* not politeness, an empty IN () list is a SQL syntax error.
		/*
		/* A call with NO confirmed crew is NOT awaiting. There is nobody whose
		/* times are missing, so reporting it would put a permanent badge on
		/* every unstaffed call.
		/*
		/* Only booked crew are considered. An unbooked person is by definition
		/* not in call_crew_map, so they cannot be "missing" — they are known
		/* only because a boss typed them in.
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

			/* 1. confirmed crew per call */

			$crew = array();

			$cres = mysql_query("SELECT ccm.callID AS callID, ccm.userID AS userID
			                     FROM call_crew_map ccm
			                     INNER JOIN calls c ON c.id = ccm.callID
			                     WHERE ccm.callID IN (" . $idList . ")
			                       AND ccm.status = 5");

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

				if (!isset($crew[$cid]))
				{
					$crew[$cid] = array();
				}

				$crew[$cid][$uid] = true;
			}

			/*
			/* 2. who already has a live submission. Superseded rows are
			/* excluded the same way goat_time_submissions_for_call() excludes
			/* them; a superseded row still proves the person was submitted
			/* for, but a VOIDED one does not — a void is how a boss retracts
			/* an entry, and the call goes back to awaiting.
			*/

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

			/* 3. a call is awaiting if any confirmed member is not done */

			foreach ($callIDs as $cid)
			{
				$cid = (int) $cid;

				if ($cid <= 0 || !isset($crew[$cid]) || in_array($cid, $out))
				{
					continue;
				}

				foreach ($crew[$cid] as $uid => $ignored)
				{
					if (!isset($done[$cid][$uid]))
					{
						$out[] = $cid;
						break;
					}
				}
			}

			return $out;
		}

	}

?>
