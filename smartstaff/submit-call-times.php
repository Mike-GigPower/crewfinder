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
	/* THE FIRST ENDPOINT THAT LETS A NON-ADMIN WRITE DATA ABOUT OTHER PEOPLE.
	/*
	/* Phase 1's my-boss-calls.php let a crew boss READ their crew. This one
	/* lets them record the hours those people will be paid for. Nothing here
	/* reaches call_crew_map — Ops accept in slice 7 — but a submission is the
	/* INPUT TO PAYROLL and is built as such.
	/*
	/* AUTH — goat_acting_user_id(), then a scope gate.
	/*
	/* This is the opposite of call-supervision.php and the same as
	/* my-boss-calls.php. call-supervision.php is admin maintenance whose
	/* created_by is an audit column, so the actor must be the real signed-in
	/* operator and the session is the only trust path. This is a crew-facing
	/* self-scoped WRITE called by Crew Hub through the Edge Function, where
	/* the service key IS the trust anchor: the backend has already
	/* authenticated the crew member and asserts their userID.
	/*
	/* goat_boss_scope() IS RECOMPUTED PER REQUEST. No scope is cached, passed
	/* in, or trusted from the client, and there is no parameter that widens
	/* it. The only question this endpoint asks is "is this call in the scope
	/* the database says you have, right now".
	/*
	/* APPEND-ONLY. There is no UPDATE of a submission's contents and no DELETE
	/* of a break row anywhere in this file. A correction INSERTs a new
	/* submission with supersedes_id pointing at the row it replaces, plus a
	/* fresh set of break rows. The single UPDATE below sets voided = 1 and is
	/* the failure path in section 5.2 — it changes no typed value.
	/*
	/* ---- UNBOOKED CREW ARE IDENTIFIED BY EIN (decision 21) ----
	/*
	/* An unbooked row supplies the person's EIN. It is resolved to a userID
	/* here and the userID is stored. There is NO fallback to a typed name, and
	/* unbooked_name is no longer accepted at all — the column stays in the
	/* schema, nullable and unused.
	/*
	/* EIN IS NOT THE userID AND NEVER HAS BEEN. EIN 5925 is userID 9734 — the
	/* v3.4.10 regression. Resolve, then store what resolved.
	/*
	/* WHY AN UNRESOLVED EIN IS REJECTED, AND WHY THAT IS A CONTROL RATHER THAN
	/* FUSSY VALIDATION. From Mike, 26 Aug 2026: "All workers will have an EIN,
	/* we cannot legally engage workers who are not onboarded, it voids our
	/* workcover." So every person legitimately on site HAS an EIN, by
	/* definition. A rejection therefore means one of exactly two things: the
	/* number was mistyped, or somebody worked who should not have been there.
	/* Both want stopping at the point of entry, and neither is helped by
	/* accepting a typed name instead.
	/*
	/* DO NOT ADD A NAME FALLBACK BACK IN "to be helpful". It would convert the
	/* second case — an un-onboarded worker on site — from a visible rejection
	/* into a silently accepted payroll row.
	/*
	/* THIS CLOSES Q34. An unbooked row now carries a real userID, so
	/* goat_time_submission_for($callID, $userID) finds its predecessor and sets
	/* supersedes_id exactly as for booked crew. The three live unbooked rows
	/* seen in slice 2 testing were a consequence of userID = 0 and cannot
	/* recur. Slice 1's userID <= 0 guard stays correct; it simply stops being
	/* reachable through this path.
	/*
	/* An EIN that resolves to someone already CONFIRMED on the call is allowed.
	/* It is arguably a booked row submitted the wrong way, but it is harmless:
	/* callID + userID is the supersede key, so it chains rather than
	/* duplicating, and refusing it would mean guessing at the boss's intent.
	/*
	/* PHP 5.x — mysql_*, no ??, no short array syntax.
	*/

	function send_status($code, $msg)
	{
		$proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($proto . ' ' . $code . ' ' . $msg);
	}

	/*
	/* Every failure body is built with json_encode(), NEVER by concatenating
	/* into a string literal. unbooked_name and note are the first
	/* user-supplied strings this workstream stores, and the house idiom
	/* die('{"error":"' . addslashes(...) . '"}') emits \' for an apostrophe —
	/* which is not a JSON escape sequence. "O'Brien" in an error message would
	/* produce a body the client cannot parse, and the client would report a
	/* parser complaint instead of the actual validation failure.
	*/

	function sct_fail($code, $status, $message, $extra)
	{
		send_status($code, $status);

		$body = array('ok' => false, 'error' => $message);

		if (is_array($extra))
		{
			foreach ($extra as $k => $v)
			{
				$body[$k] = $v;
			}
		}

		$out = json_encode($body);

		if ($out === false)
		{
			$out = '{"ok":false,"error":"request failed"}';
		}

		die($out);
	}

	/*
	/* HH:MM or HH:MM:SS -> HH:MM:SS. '' when the input is not a valid clock
	/* time. Deliberately rejects 24:00 and anything with a stray character:
	/* the column is TIME, which would happily accept '25:00' and even
	/* '838:59:59', and a value nothing else in the estate reads that way is
	/* worse than a rejection the boss can see and correct.
	*/

	function sct_time($v)
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
	*/

	function sct_minutes($hms, $nextDay)
	{
		$parts = explode(':', $hms);

		if (count($parts) < 2)
		{
			return -1;
		}

		return ((int) $parts[0]) * 60 + ((int) $parts[1]) + ($nextDay ? 1440 : 0);
	}

	/*
	/* Section 5.2's failure path. MyISAM has no transactions, so a submission
	/* whose break rows failed to land is marked voided rather than deleted —
	/* append-only means the evidence of the failure survives, and every helper
	/* in time-submission-graph.php already excludes voided rows.
	*/

	function sct_void($submissionID)
	{
		mysql_query("UPDATE call_time_submissions
		             SET voided = 1
		             WHERE id = " . (int) $submissionID);
	}

	/* ---- identity ---- */

	$actor = goat_acting_user_id();   /* owns its own 400 / 401 */

	/* ---- body ---- */

	$raw     = file_get_contents('php://input');
	$payload = json_decode($raw);

	if (!$payload || !isset($payload->callID))
	{
		sct_fail(400, 'Bad Request', 'Invalid or missing JSON body', array());
	}

	$callID  = (int) $payload->callID;
	$confirm = (isset($payload->confirm) && $payload->confirm) ? true : false;

	if ($callID <= 0)
	{
		sct_fail(422, 'Unprocessable Entity', 'callID required', array());
	}

	/* ---- SCOPE GATE ---- */

	$scope = goat_boss_scope($actor);

	if (!in_array($callID, $scope))
	{
		sct_fail(403, 'Forbidden', 'You are not the crew boss for this call', array());
	}

	if (!isset($payload->rows) || !is_array($payload->rows) || count($payload->rows) === 0)
	{
		sct_fail(422, 'Unprocessable Entity', 'rows must contain at least one entry', array());
	}

	/*
	/* Confirmed crew on this call. A FAILED LOOKUP IS A 500, NOT AN EMPTY SET.
	/* Degrading to empty would make every row fail validation with "not
	/* confirmed on this call", which reads as the boss having picked the wrong
	/* people rather than as a broken query — the same failure that hid the
	/* ccm.id / crewmapID bug in call-supervision.php on 18 Aug.
	*/

	$confirmed = array();

	$cres = mysql_query("SELECT userID FROM call_crew_map
	                     WHERE callID = " . $callID . "
	                       AND status = 5");

	if ($cres === false)
	{
		sct_fail(500, 'Internal Server Error', 'confirmed-crew lookup failed: ' . mysql_error(), array());
	}

	while ($crow = mysql_fetch_object($cres))
	{
		$confirmed[(int) $crow->userID] = true;
	}

	/* ---- validate every row before writing any of them ---- */

	$errors = array();
	$clean  = array();

	foreach ($payload->rows as $i => $row)
	{
		$label = 'row ' . $i;

		if (!is_object($row))
		{
			$errors[] = $label . ': not an object';
			continue;
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
			/* UNBOOKED: the EIN is the identity, and it is required. See the
			/* header for why an unresolved EIN is a control and not fussy
			/* validation.
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
					sct_fail(500, 'Internal Server Error',
					         'EIN lookup failed: ' . mysql_error(), array('row' => $i));
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
			/* ignored: nothing consumes this endpoint yet, so a client sending
			/* it is built against the superseded contract and should be told
			/* now, loudly, rather than having the boss's typing silently
			/* dropped. (Q12c: decided as reject.)
			*/

			if ($uname !== '')
			{
				$errors[] = $label . ': unbooked_name is no longer accepted — supply ein instead';
			}
		}

		if (strlen($note) > 255)
		{
			$errors[] = $label . ': note exceeds 255 characters';
		}

		if ($cover > 0 && !isset($confirmed[$cover]))
		{
			$errors[] = $label . ': covering_for ' . $cover . ' is not confirmed on this call';
		}

		$on  = sct_time(isset($row->on_time)  ? $row->on_time  : '');
		$off = sct_time(isset($row->off_time) ? $row->off_time : '');

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
		/* the boss correct it. Recomputing it here would silently override a
		/* deliberate correction — a 17:00 start finishing 23:00 THE NEXT DAY
		/* is unusual but legal, and the boss said so.
		*/

		$offNext = (isset($row->off_next_day) && $row->off_next_day) ? 1 : 0;

		/*
		/* ---- the shift window, for the break test below (Q37) ----
		/*
		/* Only computable when both times parsed. A window that does not move
		/* forward is reported as such rather than left to reject every break
		/* with a misleading "outside the shift" — off_next_day is the field
		/* the boss actually got wrong in that case, and the message says so.
		*/

		$onMin  = ($on  === '') ? -1 : sct_minutes($on, 0);
		$offMin = ($off === '') ? -1 : sct_minutes($off, $offNext);

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

			$bs = sct_time(isset($b->start_time) ? $b->start_time : '');
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
			/* NOT SILENTLY DROPPED.
			/*
			/* A break is time not worked WITHIN a shift. One outside the shift
			/* is time nobody was being paid for anyway, so deducting it would
			/* take the hours away twice — but discarding it quietly hides a
			/* typo. A break at 08:00 on a 17:00 call is a mistake worth
			/* surfacing while the boss is still looking at the form.
			/*
			/* Both ends are next-day aware, or every overnight shift would
			/* reject its own perfectly valid breaks.
			*/

			if ($bs !== '' && $onMin >= 0 && $offMin > $onMin && $dm > 0)
			{
				$bStart = sct_minutes($bs, $bNext);
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

		$clean[] = array(
			'index'          => $i,
			'userID'         => $userID,
			'unbooked'       => $unbooked,
			'unbooked_name'  => $uname,
			'covering_for'   => $cover,
			'on_time'        => $on,
			'off_time'       => $off,
			'off_next_day'   => $offNext,
			'note'           => $note,
			'breaks'         => $breaks
		);
	}

	if (count($errors))
	{
		sct_fail(422, 'Unprocessable Entity', $errors[0], array('errors' => $errors));
	}

	/*
	/* ---- WARNINGS — overridable with confirm:true ----
	/*
	/* 409 IS A RESULT, NOT AN ERROR. Same contract as call-feeds.php and
	/* call-supervision.php.
	/*
	/* PER BREAK, NOT PER DAY'S TOTAL. Two 45-minute breaks is a legitimate 90
	/* minutes and neither trips this; one 90-minute break does.
	/*
	/* THE ENDPOINT RETURNS FACTS, NOT JOKES. The light line Rich asked for is
	/* picked from a pool by the client in slice 5, so the pool can be edited
	/* without a SmartStaff deploy and this payload stays stable enough to test
	/* against. When that pool is written: amused at the DURATION, never at the
	/* PERSON, never implying slacking — a long break sometimes means someone
	/* was unwell or there was an incident, and every line has to still read as
	/* fine if the reason turns out to be an injury.
	*/

	$warnings = array();

	foreach ($clean as $c)
	{
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

	if (count($warnings) && !$confirm)
	{
		send_status(409, 'Conflict');
		echo json_encode(array('ok' => false, 'needs_confirm' => true, 'warnings' => $warnings));
		die();
	}

	/* ---- write ---- */

	$submitted = array();

	foreach ($clean as $c)
	{
		/*
		/* supersedes_id IS NOT OPTIONAL. slice 1's
		/* goat_time_submissions_for_call() carries a second dedupe pass in PHP
		/* specifically in case this line is ever missed: without it the SQL
		/* NOT EXISTS returns both rows for one person while
		/* goat_time_submission_for() returns one, and two helpers disagreeing
		/* about who is live surfaces as a DUPLICATED CREW MEMBER ON A
		/* TIMESHEET rather than as an error. That guard should never fire.
		/*
		/* userID = 0 resolves to 0 — see Q34 in the header.
		*/

		$supersedes = 0;

		if ($c['userID'] > 0)
		{
			$prev = goat_time_submission_for($callID, $c['userID']);

			if (isset($prev['id']))
			{
				$supersedes = (int) $prev['id'];
			}
		}

		/* unbooked_name is never written — decision 21 replaced it with the
		/* EIN-resolved userID. The column remains in the schema, nullable and
		/* unused, so slice 1's fixtures and any pre-amendment row still read. */

		$nameSql = 'NULL';
		$noteSql = ($c['note'] === '') ? 'NULL' : $db->sc($c['note']);

		$sql = "INSERT INTO call_time_submissions
		        (callID, userID, unbooked, unbooked_name, covering_for,
		         on_time, off_time, off_next_day, note,
		         submitted_by, submitted_at, supersedes_id, voided)
		        VALUES (
		          " . $callID . ",
		          " . (int) $c['userID'] . ",
		          " . (int) $c['unbooked'] . ",
		          " . $nameSql . ",
		          " . (int) $c['covering_for'] . ",
		          " . $db->sc($c['on_time']) . ",
		          " . $db->sc($c['off_time']) . ",
		          " . (int) $c['off_next_day'] . ",
		          " . $noteSql . ",
		          " . (int) $actor . ",
		          NOW(),
		          " . (int) $supersedes . ",
		          0
		        )";

		if (mysql_query($sql) === false)
		{
			sct_fail(500, 'Internal Server Error',
			         'submission insert failed: ' . mysql_error(),
			         array('row' => $c['index']));
		}

		$subID = (int) mysql_insert_id();

		if ($subID <= 0)
		{
			sct_fail(500, 'Internal Server Error',
			         'submission insert returned no id',
			         array('row' => $c['index']));
		}

		/*
		/* ---- BREAKS, THEN COUNT THEM BACK ----
		/*
		/* MyISAM gives no transaction. The submission is inserted first
		/* because the break rows need its id, so a failed break insert leaves
		/* a submission WITH NO BREAKS — and zero break rows is a VALID,
		/* COMMON state meaning "no break taken" (short calls typically have
		/* none). The failure is therefore invisible by absence: it looks
		/* exactly like a legitimate submission, and it is a silent wrong
		/* number in a payroll input.
		/*
		/* So: count them back, and void on mismatch. Append-only makes that
		/* safe — the voided row stays as the record that something went wrong,
		/* and every helper already excludes it.
		*/

		$seq      = 1;
		$expected = count($c['breaks']);

		foreach ($c['breaks'] as $b)
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
				sct_void($subID);
				sct_fail(500, 'Internal Server Error',
				         'break insert failed, submission voided: ' . mysql_error(),
				         array('row' => $c['index'], 'submission_id' => $subID));
			}

			$seq++;
		}

		$bres = mysql_query("SELECT COUNT(*) AS n FROM call_time_submission_breaks
		                     WHERE submission_id = " . $subID);

		if ($bres === false)
		{
			sct_void($subID);
			sct_fail(500, 'Internal Server Error',
			         'break count-back failed, submission voided',
			         array('row' => $c['index'], 'submission_id' => $subID));
		}

		$brow   = mysql_fetch_object($bres);
		$actual = $brow ? (int) $brow->n : -1;

		if ($actual !== $expected)
		{
			sct_void($subID);
			sct_fail(500, 'Internal Server Error',
			         'break count mismatch (' . $actual . ' of ' . $expected . '), submission voided',
			         array('row' => $c['index'], 'submission_id' => $subID));
		}

		$submitted[] = array(
			'row'           => $c['index'],
			'submission_id' => $subID,
			'userID'        => (int) $c['userID'],
			'unbooked'      => (int) $c['unbooked'],
			'supersedes_id' => $supersedes,
			'breaks'        => $expected
		);
	}

	/*
	/* A request that dies partway leaves the rows already written in place.
	/* That is deliberate and safe: partial submission is a valid state by
	/* design, and a retry supersedes those rows correctly rather than
	/* duplicating them, because supersedes_id is resolved per row at write
	/* time.
	*/

	echo json_encode(array(
		'ok'        => true,
		'callID'    => $callID,
		'count'     => count($submitted),
		'submitted' => $submitted
	));

?>
