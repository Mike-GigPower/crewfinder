<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include_once(dirname(__FILE__) . '/goat-db.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* READ-ONLY — the response history for ONE call (slice H1 of
	/* BRIEF-response-history-panel.md).
	/*
	/* Mike's second request from the original cancellation ask: somewhere Ops can
	/* see when each crew member accepted, so they can decide who gets cut when a
	/* call is reduced.
	/*
	/* IT INFORMS A JUDGEMENT; IT DOES NOT RANK. The cut is a judgement call, so
	/* this endpoint returns facts side by side and takes no view on their weight.
	/* Nothing here sorts, scores or recommends.
	/*
	/* WHAT IT RETURNS, per crew row on the call:
	/*   timing      : offered_at, responded_at, responded_src, respond_seconds
	/*   standing    : status, prev_status, is_call_boss
	/*   commitment  : other_calls_on_booking — live rows on the SAME booking
	/*   history     : worked_90d — confirmed on calls that have already started
	/*
	/* WHAT IT DELIBERATELY DOES NOT RETURN: accepted / declined / no-show /
	/* response-rate. get-crew-offer-stats.php already defines those, with a
	/* cutover clamp, two reconciliation identities and (since 2 Sep) correct
	/* handling of status 9. A second definition would drift, and the drift would
	/* surface as a number beside somebody's name in a conversation about cutting
	/* them. app.py reads that endpoint and merges. One definition, one place.
	/*
	/* RESPOND_SECONDS IS ONLY SET FOR THE 'TIMED' POPULATION — offered_at and
	/* responded_at both present AND responded_src IS NULL — which is exactly how
	/* get-crew-offer-stats.php defines it. An 'ops' row is a resolution typed in
	/* by an operator, so its duration would measure ops data-entry lag, not the
	/* crew member. A 'phone' row has no timestamps at all: arm 1 of
	/* ccm_responded_at_upd clears them deliberately. Both are real states, and
	/* the panel must render them as such rather than as blanks.
	/*
	/* TRACKING BEGAN 2026-07-27 23:44:52 — when the two call_crew_map triggers
	/* went live. Nothing before it has timing of any kind. Returned as
	/* tracking_since so the panel can say so; thin history must never read as a
	/* thin crew member.
	/*
	/* CREW REMOVED FROM THE CALL CANNOT APPEAR. Removal DELETEs the row, so they
	/* are unrecoverable (design Q9). The panel says so rather than implying the
	/* list is complete. An append-only event log is what would fix this, and it
	/* is its own piece of work.
	/*
	/* HARD REQUIREMENTS, not capability-guarded. This endpoint is new, so there
	/* is no environment where it must degrade: offered_at / responded_at /
	/* responded_src arrived 27 Jul, prev_status and cancelled_at on 1-2 Sep, and
	/* all are on test and production. A failed lookup here is a 500, never an
	/* empty set — an empty response history is indistinguishable from a crew
	/* member who never answered, and this screen decides who loses work.
	/*
	/* TIME-CHANGE ANSWERS (section 4, added 5.89.0) ARE THE EXCEPTION. They are
	/* a display add-on read from call_change_log / call_change_ack through
	/* goat_pdo(), and they DEGRADE rather than 500: if the handle is missing or
	/* either read throws, change_history_available is false and no crew row
	/* carries change_* fields at all -- a visible absence, never an empty list
	/* that would read as "never answered a change". Same pattern as app.py's
	/* stats_available.
	/*
	/* Request:  GET ?id=<callID>
	/*
	/* PHP 5.x -- mysql_*, array(), no null-coalescing (??), no short arrays, tabs.
	*/

	/* ---- helpers ---- */

	function send_status($code, $msg)
	{
		$proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($proto . ' ' . $code . ' ' . $msg);
	}

	/*
	/* When timing collection began. Same constant as get-crew-offer-stats.php's
	/* cutover clamp; if that one ever moves, this must move with it.
	*/

	$TRACKING_SINCE = '2026-07-27 23:44:52';

	/* How far back "recent work" looks. 90 days, matching the crew-stats window,
	/* so the two figures on the panel are measured over the same period. */

	$WORK_WINDOW_DAYS = 90;

	/* ---- gate ----
	/*
	/* Read-only, and Ops are the intended readers — so the wider read gate, not
	/* the admin-only write gate that cancel-call.php uses. Same function
	/* get-crew-offer-stats.php checks.
	*/

	if (!goat_can_read_all())
	{
		send_status(403, 'Forbidden');
		die('{"error":"Admin, Leadership or Operations session required"}');
	}

	/* ---- target call ---- */

	$callID = isset($_GET['id']) ? intval($_GET['id']) : 0;

	if ($callID <= 0)
	{
		send_status(400, 'Bad Request');
		die('{"error":"Missing or invalid ?id"}');
	}

	$call = $db->selectFirst(
		'id, bookingID, call_name, start_date, start_time, required, cancelled_at',
		'calls',
		'id=' . $callID
	);

	if (!$call)
	{
		send_status(404, 'Not Found');
		die('{"error":"Call not found"}');
	}

	$bookingID = (int) $call->bookingID;

	/* ---- 1. the roster, with its timing ----
	/*
	/* Every row on the call, whatever its status. A declined or cancelled row is
	/* part of the history of how this call was filled and belongs on the screen.
	/*
	/* respond_seconds is computed here rather than in the client so the 'timed'
	/* definition lives in one place and matches get-crew-offer-stats.php exactly.
	*/

	$sql = "SELECT ccm.userID          AS user_id,
	               ccm.status          AS status,
	               ccm.prev_status     AS prev_status,
	               ccm.is_call_boss    AS is_call_boss,
	               ccm.offered_at      AS offered_at,
	               ccm.responded_at    AS responded_at,
	               ccm.responded_src   AS responded_src,
	               ccm.created_at      AS created_at,
	               IF(ccm.offered_at IS NOT NULL
	                  AND ccm.responded_at IS NOT NULL
	                  AND ccm.responded_src IS NULL,
	                  TIMESTAMPDIFF(SECOND, ccm.offered_at, ccm.responded_at),
	                  NULL)            AS respond_seconds,
	               users.firstname     AS firstname,
	               users.lastname      AS lastname,
	               users.ein           AS ein,
	               users.mobile        AS mobile
	          FROM call_crew_map ccm
	          LEFT JOIN users ON ccm.userID = users.id
	         WHERE ccm.callID = " . $callID;

	$res = mysql_query($sql);

	if ($res === false)
	{
		send_status(500, 'Internal Server Error');
		die('{"error":"roster read failed: ' . addslashes(mysql_error()) . '"}');
	}

	$crew = array();
	$ids  = array();

	while ($row = mysql_fetch_object($res))
	{
		$uid = (int) $row->user_id;
		$ids[] = $uid;

		$crew[$uid] = array(
			'user_id'                => $uid,
			'ein'                    => $row->ein,
			'firstname'              => $row->firstname,
			'lastname'               => $row->lastname,
			'mobile'                 => $row->mobile,
			'status'                 => (int) $row->status,
			'prev_status'            => ($row->prev_status === null ? null : (int) $row->prev_status),
			'is_call_boss'           => (int) $row->is_call_boss,
			'offered_at'             => $row->offered_at,
			'responded_at'           => $row->responded_at,
			'responded_src'          => $row->responded_src,
			'respond_seconds'        => ($row->respond_seconds === null ? null : (int) $row->respond_seconds),
			'other_calls_on_booking' => 0,
			'worked_90d'             => 0
		);
	}

	/* ---- 2. what else they are holding on THIS booking ----
	/*
	/* Cutting someone from one call of a five-call job is a different act from
	/* cutting their only shift that week, and Mike named this as the factor most
	/* likely to change a decision.
	/*
	/* LIVE rows only (0/1/5/7): a declined or cancelled row elsewhere on the
	/* booking is not a commitment they are still holding. Cancelled CALLS are
	/* excluded for the same reason.
	*/

	if (count($ids))
	{
		$idList = implode(',', $ids);

		$sql = "SELECT m.userID AS user_id, COUNT(DISTINCT m.callID) AS n
		          FROM call_crew_map m
		         INNER JOIN calls c ON c.id = m.callID
		         WHERE c.bookingID = " . $bookingID . "
		           AND m.callID <> " . $callID . "
		           AND m.userID IN (" . $idList . ")
		           AND m.status IN (0,1,5,7)
		           AND c.cancelled_at IS NULL
		         GROUP BY m.userID";

		$res = mysql_query($sql);

		if ($res === false)
		{
			send_status(500, 'Internal Server Error');
			die('{"error":"booking commitment read failed: ' . addslashes(mysql_error()) . '"}');
		}

		while ($row = mysql_fetch_object($res))
		{
			$uid = (int) $row->user_id;
			if (isset($crew[$uid]))
				$crew[$uid]['other_calls_on_booking'] = (int) $row->n;
		}

		/* ---- 3. how much work they have had lately ----
		/*
		/* Confirmed (5) on a call that has ALREADY STARTED, within the window.
		/* "Already started" is the point: a future booking is work promised, not
		/* work had, and counting it would make someone look busier than they are
		/* on the very screen deciding whether to take work off them.
		/*
		/* Deliberately NOT "calls with times entered" — truer, but it excludes
		/* everything not yet processed, which is most of the recent past.
		/*
		/* Restricted to this call's roster: unrestricted it would scan the whole
		/* table, which is how get-calls-bulk.php earned its 20-second timeout.
		*/

		$since = time() - ($WORK_WINDOW_DAYS * 86400);

		$sql = "SELECT m.userID AS user_id, COUNT(*) AS n
		          FROM call_crew_map m
		         INNER JOIN calls c ON c.id = m.callID
		         WHERE m.userID IN (" . $idList . ")
		           AND m.status = 5
		           AND c.start_date <  " . time() . "
		           AND c.start_date >= " . $since . "
		           AND c.cancelled_at IS NULL
		         GROUP BY m.userID";

		$res = mysql_query($sql);

		if ($res === false)
		{
			send_status(500, 'Internal Server Error');
			die('{"error":"work history read failed: ' . addslashes(mysql_error()) . '"}');
		}

		while ($row = mysql_fetch_object($res))
		{
			$uid = (int) $row->user_id;
			if (isset($crew[$uid]))
				$crew[$uid]['worked_90d'] = (int) $row->n;
		}
	}

	/* ---- 4. answers to time changes (display add-on; degrades) ----
	/*
	/* responded_at above is the FIRST answer to the original offer. A later
	/* "the time moved, can you still do it?" answer never moves it, so a
	/* change-decline used to show as a bare DECLINED beside the original accept
	/* time. call_change_log (27 Oct 2026) keeps every such answer; a surviving
	/* call_change_ack row is a change still waiting for one.
	/*
	/* Per crew row, when available:
	/*   change_answers : oldest -> newest, {answer, answer_src, prior_status,
	/*                    result_status, answered_at, changed_at, prev_start,
	/*                    prev_est_length}
	/*   change_pending : {changed_at, prev_start, prev_est_length} or null
	/*
	/* TIMESTAMPS ARE "Y-m-d H:i:s" STRINGS IN Australia/Melbourne, set with an
	/* explicit DateTimeZone, so they read on the same clock as responded_at and
	/* go through goatFmtStamp() unchanged. NEVER emit the raw unix int: JS reads
	/* a bare number as milliseconds (the v5.50.0 1970 bug).
	/*
	/* prev_start mirrors how $startIso below is built (date() on start_date plus
	/* the start_time string), so a "was" time reads like the call's own start.
	*/

	function goat_resp_mel_stamp($ts)
	{
		$ts = (int) $ts;
		if ($ts <= 0) return null;
		$dt = new DateTime('@' . $ts);
		$dt->setTimezone(new DateTimeZone('Australia/Melbourne'));
		return $dt->format('Y-m-d H:i:s');
	}

	function goat_resp_prev_start($date, $time)
	{
		$date = (int) $date;
		if ($date <= 0) return null;
		return date('Y-m-d', $date) . ' ' . $time;
	}

	$changeAvailable = false;
	$changeAnswers   = array();
	$changePending   = array();

	$pdo = goat_pdo();

	if ($pdo)
	{
		try
		{
			$st = $pdo->prepare(
				'SELECT userID, answer, answer_src, prior_status, result_status, answered_at, ' .
				'changed_at, prev_start_date, prev_start_time, prev_est_length ' .
				'FROM call_change_log WHERE callID = ? ORDER BY answered_at, id'
			);
			$st->execute(array($callID));

			foreach ($st->fetchAll() as $r)
			{
				$uid = (int) $r['userID'];
				if (!isset($changeAnswers[$uid])) $changeAnswers[$uid] = array();
				$changeAnswers[$uid][] = array(
					'answer'          => $r['answer'],
					'answer_src'      => $r['answer_src'],
					'prior_status'    => (int) $r['prior_status'],
					'result_status'   => (int) $r['result_status'],
					'answered_at'     => goat_resp_mel_stamp($r['answered_at']),
					'changed_at'      => goat_resp_mel_stamp($r['changed_at']),
					'prev_start'      => goat_resp_prev_start($r['prev_start_date'], $r['prev_start_time']),
					'prev_est_length' => (float) $r['prev_est_length']
				);
			}

			$st = $pdo->prepare(
				'SELECT userID, changed_at, prev_start_date, prev_start_time, prev_est_length ' .
				'FROM call_change_ack WHERE callID = ?'
			);
			$st->execute(array($callID));

			foreach ($st->fetchAll() as $r)
			{
				$changePending[(int) $r['userID']] = array(
					'changed_at'      => goat_resp_mel_stamp($r['changed_at']),
					'prev_start'      => goat_resp_prev_start($r['prev_start_date'], $r['prev_start_time']),
					'prev_est_length' => (float) $r['prev_est_length']
				);
			}

			$changeAvailable = true;
		}
		catch (Exception $e)
		{
			error_log('get-call-responses: change history read failed (call ' . $callID . '): ' . $e->getMessage());
			$changeAvailable = false;
		}
	}
	else
	{
		error_log('get-call-responses: no PDO handle, change history omitted (call ' . $callID . ')');
	}

	if ($changeAvailable)
	{
		foreach ($crew as $uid => $row)
		{
			$crew[$uid]['change_answers'] = isset($changeAnswers[$uid]) ? $changeAnswers[$uid] : array();
			$crew[$uid]['change_pending'] = isset($changePending[$uid]) ? $changePending[$uid] : null;
		}
	}

	/* ---- respond ----
	/*
	/* crew is re-indexed to a list: a JSON object keyed by userID would be
	/* rendered in an arbitrary order, and this screen is read as a table.
	/*
	/* Names come back RAW. They are stored HTML-encoded in `users`, and the
	/* client already decodes them with decodeEntities() — the same contract
	/* get-booking.php has.
	*/

	$startIso = ((int) $call->start_date > 0)
		? date('Y-m-d', (int) $call->start_date) . 'T' . $call->start_time
		: '';

	echo json_encode(array(
		'ok'               => true,
		'call_id'          => $callID,
		'booking_id'       => $bookingID,
		'call_name'        => $call->call_name,
		'start'            => $startIso,
		'required'         => (int) $call->required,
		'cancelled'        => ($call->cancelled_at !== null && (int) $call->cancelled_at > 0) ? 1 : 0,
		'tracking_since'   => str_replace(' ', 'T', $TRACKING_SINCE),
		'work_window_days' => $WORK_WINDOW_DAYS,
		'change_history_available' => $changeAvailable,
		'change_history_since'     => '2026-10-27',
		'crew'             => array_values($crew)
	));

?>
