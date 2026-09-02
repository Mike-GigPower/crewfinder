<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* ADMIN-ONLY WRITE — cancel a call (slice B of
	/* BRIEF-call-cancellation-phase1.md; design DESIGN-call-cancellation-v0_1.md).
	/*
	/* SmartStaff has no delete-call operation and this does not add one. A
	/* cancelled call is a STATE, not an absence: the call stays, every crew row
	/* stays, and the roster at the moment of cancellation is the billing record.
	/* A customer who cancels inside a blackout period may still be invoiced, and
	/* the people who were committed are the evidence for that (design section 1).
	/*
	/* WHAT THIS WRITES
	/*   calls           : cancelled_at, cancelled_by, cancel_reason, cancel_charge
	/*                     and a "CANCELLED - " prefix on call_name
	/*   call_crew_map   : prev_status (what they WERE), status = 9 (Cancelled),
	/*                     cancel_reason, cancel_charge -- per row
	/*   calendars       : the row is removed for EVERY crew member stood down
	/*
	/* WHAT THIS DELIBERATELY DOES NOT DO
	/*   - It does NOT notify anybody. The Crew Hub push lives in THE GOAT's
	/*     Python (gp_notify_offer and friends, v3.16.0); PHP has never held that
	/*     trigger. This endpoint RETURNS the list of crew who need telling, in
	/*     "notify", and app.py fires the pushes and reports delivery per crew.
	/*     That matters: design section 6.1 requires ops to see WHO could not be
	/*     reached, because a missed cancellation puts someone in a car to a venue.
	/*   - It does NOT write times_filled or call_locked. v3.11.0 settled that
	/*     those stay SmartStaff actions so generateCallData can never be tripped
	/*     from here.
	/*   - It does NOT cascade to fed calls (decision D11). It warns, naming them
	/*     and their confirmed counts, and stops until confirm is passed.
	/*   - It does NOT implement scope "reduce" (a later phase). That returns 422
	/*     rather than silently doing nothing.
	/*
	/* IDEMPOTENT BY CONSTRUCTION. MyISAM has no transactions, so a mid-flight
	/* failure must leave a re-runnable state: the per-row write is guarded on
	/* status <> 9, the name prefix is applied only if absent, and cancelling an
	/* already-cancelled call returns 200 rather than an error. Re-running finishes
	/* a partial cancel; it never doubles one.
	/*
	/* Request:  POST ?id=<callID>
	/*   {
	/*     "scope"      : "call",              required
	/*     "chargeable" : true | false,        REQUIRED -- see below
	/*     "reason"     : "free text",         optional
	/*     "confirm"    : true                 required only to override the
	/*                                         fed-calls warning
	/*   }
	/*
	/* chargeable has NO DEFAULT and its absence is a 422. Blackout terms vary by
	/* customer and ops decide every time (decision D6); a defaulted toggle is a
	/* decision nobody made, and on this field that means silently not billing for
	/* work the customer owes for.
	/*
	/* PHP 5.x -- mysql_*, array(), no null-coalescing (??), no short arrays, tabs.
	*/

	/* ---- helpers ---- */

	function P($obj, $key, $default = '')
	{
		return (isset($obj->$key) && $obj->$key !== null) ? $obj->$key : $default;
	}

	function send_status($code, $msg)
	{
		$proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($proto . ' ' . $code . ' ' . $msg);
	}

	/*
	/* Quote a string for SQL EXPLICITLY rather than relying on $db->sc(), whose
	/* callers in this codebase disagree about whether it adds the quotes itself
	/* (sms-cron.php wraps it in "'...'", send-sms.php does not). Being explicit
	/* here removes the ambiguity for the two free-text columns.
	*/
	function q($str)
	{
		return "'" . mysql_real_escape_string((string) $str) . "'";
	}

	/* ---- admin gate ----
	/*
	/* Same gate as update-call-times.php / update-crew-status.php.
	/* goat_user_cohort() returns 'admin' only for usergroupID == 1, so this is
	/* the strict check cohort.php asks write endpoints to keep.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		send_status(403, 'Forbidden');
		die('{"error":"Admin only"}');
	}

	/*
	/* The OPERATOR, for the cancelled_by audit column. Read from the session,
	/* never from request input. goat_acting_user_id() is deliberately NOT used:
	/* its service-key branch takes userID from the request, which would hollow
	/* out an audit column (the same reasoning as boss-scope slice B).
	*/

	$actorID = (int) $_SESSION[SITE_KEY]['userID'];

	/* ---- target call ---- */

	$callID = isset($_GET['id']) ? intval($_GET['id']) : 0;

	if ($callID <= 0)
	{
		send_status(400, 'Bad Request');
		die('{"error":"Missing or invalid ?id"}');
	}

	$call = $db->selectFirst(
		'id, bookingID, call_name, required, call_locked, invoiceID,
		 invoice_generated, payslips_generated, cancelled_at,
		 start_date, start_time',
		'calls',
		'id=' . $callID
	);

	if (!$call)
	{
		send_status(404, 'Not Found');
		die('{"error":"Call not found"}');
	}

	/* ---- parse body ---- */

	$raw     = file_get_contents('php://input');
	$payload = json_decode($raw);

	if (!$payload)
	{
		send_status(400, 'Bad Request');
		die('{"error":"Invalid or missing JSON body"}');
	}

	$scope   = strtolower(trim(P($payload, 'scope', 'call')));
	$reason  = trim(P($payload, 'reason', ''));
	$confirm = P($payload, 'confirm', false) ? true : false;

	if ($scope === 'reduce')
	{
		send_status(422, 'Unprocessable Entity');
		die('{"error":"scope \"reduce\" is not implemented yet. Reducing a call\'s crew ships in a later phase; cancel the whole call or remove crew individually for now."}');
	}

	if ($scope !== 'call')
	{
		send_status(422, 'Unprocessable Entity');
		die('{"error":"scope must be \"call\""}');
	}

	/*
	/* chargeable: REQUIRED, no default (decision D6). isset() on the raw payload,
	/* not P(), because false is a legitimate value and must be distinguishable
	/* from absent.
	*/

	if (!isset($payload->chargeable) || $payload->chargeable === null)
	{
		send_status(422, 'Unprocessable Entity');
		die('{"error":"chargeable is required (true or false). Blackout terms vary by customer, so this is always an explicit decision -- there is no default."}');
	}

	$chargeable = $payload->chargeable ? 1 : 0;

	/* ---- already cancelled: succeed, do not error ---- */

	if ($call->cancelled_at !== null && (int) $call->cancelled_at > 0)
	{
		echo json_encode(array(
			'ok'            => true,
			'already'       => true,
			'call_id'       => $callID,
			'cancelled_at'  => (int) $call->cancelled_at,
			'crew'          => array(),
			'notify'        => array()
		));
		exit;
	}

	/* ---- accounting guards ----
	/*
	/* calls carries FOUR accounting-state flags, not one. An earlier draft of the
	/* design guarded only call_locked, copied from how editing is gated; the prod
	/* column read on 1 Sep showed the other three. payslips_generated is the
	/* sharpest of them: cancelled crew are not paid (decision D8), and standing
	/* someone down AFTER their payslip exists is a different operation with a
	/* different fix.
	/*
	/* Name which flag tripped -- a locking problem, a billing problem and a
	/* payroll problem need different follow-ups, and "cannot cancel" alone sends
	/* ops looking in the wrong place.
	*/

	$blocked = '';

	if ((int) $call->call_locked === 1)
		$blocked = 'the call is LOCKED for accounting (call_locked)';
	else if ((int) $call->invoice_generated === 1)
		$blocked = 'an INVOICE has been generated for this call (invoice_generated)';
	else if ((int) $call->invoiceID > 0)
		$blocked = 'this call is attached to INVOICE #' . (int) $call->invoiceID;
	else if ((int) $call->payslips_generated === 1)
		$blocked = 'PAYSLIPS have been generated for this call (payslips_generated)';

	if ($blocked !== '')
	{
		send_status(409, 'Conflict');
		echo json_encode(array(
			'error'   => 'Cannot cancel: ' . $blocked . '. A call that has been through accounting is not cancelled from THE GOAT -- speak to accounts.',
			'blocked' => true
		));
		exit;
	}

	/* ---- fed calls: warn, never cascade (decision D11) ----
	/*
	/* A cancelled Load In that feeds LX leaves LX crewed by people who were only
	/* ever there because of Load In. Cascading is a large, hard-to-reverse action
	/* fired from one click, and whether LX still needs those people is a judgement
	/* ops make, not one to infer.
	/*
	/* The counts are the point. "This call feeds others" is ignorable; "LX has 4
	/* confirmed crew booked off the back of this" is a prompt to go and look.
	/*
	/* call_feeds is capability-checked -- call-graph.php does the same, which
	/* implies the table is not guaranteed on every environment.
	*/

	function goat_cancel_fed_calls($callID)
	{
		$out = array();

		$chk = mysql_query("SHOW TABLES LIKE 'call_feeds'");

		if ($chk === false || mysql_num_rows($chk) == 0)
			return $out;

		$res = mysql_query(
			"SELECT c.id AS id, c.call_name AS call_name,
			        (SELECT COUNT(*) FROM call_crew_map m
			          WHERE m.callID = c.id AND m.status = 5) AS confirmed
			   FROM call_feeds f
			   INNER JOIN calls c ON c.id = f.target_call
			  WHERE f.source_call = " . ((int) $callID) . "
			    AND (c.cancelled_at IS NULL OR c.cancelled_at = 0)"
		);

		if ($res === false)
			return $out;

		while ($row = mysql_fetch_object($res))
		{
			$out[] = array(
				'call_id'   => (int) $row->id,
				'call_name' => $row->call_name,
				'confirmed' => (int) $row->confirmed
			);
		}

		return $out;
	}

	$fed = goat_cancel_fed_calls($callID);

	if (count($fed) && !$confirm)
	{
		echo json_encode(array(
			'ok'            => false,
			'needs_confirm' => true,
			'reason'        => 'feeds',
			'feeds'         => $fed,
			'message'       => 'This call feeds other calls. They are NOT affected by this cancellation. Re-send with confirm:true to proceed.'
		));
		exit;
	}

	/* ---- the roster, read BEFORE anything is written ---- */

	$crew = array();
	$res  = mysql_query(
		'SELECT crewmapID, userID, status FROM call_crew_map WHERE callID=' . $callID
	);

	if ($res === false)
	{
		send_status(500, 'Internal Server Error');
		die('{"error":"roster read failed: ' . addslashes(mysql_error()) . '"}');
	}

	while ($row = mysql_fetch_object($res))
	{
		$crew[] = $row;
	}

	/* ---- stand each crew member down ----
	/*
	/* prev_status is what makes the row a billing record: "was this person
	/* CONFIRMED, or merely offered, when we cancelled them?" is the first
	/* question anyone raising the invoice asks.
	/*
	/* Guarded on status <> 9 so a re-run never overwrites a prev_status that was
	/* already captured -- that would replace the real prior status with 9 and
	/* destroy exactly the fact the column exists to hold.
	*/

	$results = array();
	$notify  = array();

	foreach ($crew as $row)
	{
		$userID = (int) $row->userID;
		$prev   = (int) $row->status;

		$cleared  = false;
		$changed  = false;

		if ($prev !== 9)
		{
			$db->update(
				'call_crew_map',
				array(
					'prev_status'   => $prev,
					'status'        => 9,
					'cancel_reason' => q($reason),
					'cancel_charge' => $chargeable
				),
				'status <> 9 AND callID=' . $callID . ' AND userID=' . $userID
			);

			if (mysql_error())
			{
				send_status(500, 'Internal Server Error');
				die('{"error":"crew status write failed: ' . addslashes(mysql_error()) . '"}');
			}

			$changed = true;

			/*
			/* Clear the calendar entry for EVERY row we stand down -- no status
			/* guard.
			/*
			/* An earlier version of this file guarded on $prev === 5, copied from
			/* respond-to-change.php's decline path, on the stated rule that "a
			/* calendar row is only ever created on confirm, so backups never have
			/* one". That rule does not hold. CHANGELOG-3_18_0 recorded status-7
			/* rows on PROD as "a mix with and without a calendars row", and the
			/* first bench run of this endpoint (call 37866, test) found a backup
			/* holding one.
			/*
			/* Guarding on 5 would have left that crew member stood down but still
			/* carrying a calendar entry -- blocking them from being booked
			/* elsewhere, which is precisely the thing cancelling is supposed to
			/* free up. A silent one, too: nothing errors, the person simply stays
			/* unavailable.
			/*
			/* Unconditional is also cheap and safe: removeFromCalendar deletes by
			/* call + user, so where there is no row it is a no-op. On a cancelled
			/* call NOBODY should retain an entry, whatever status they held.
			*/

			/*
			/* Look BEFORE removing, so calendar_cleared reports what was actually
			/* true rather than what was attempted. The first version set this flag
			/* simply because removeFromCalendar had been called -- which would
			/* report "cleared" for a crew member who never had an entry. Ops read
			/* this response to know what happened to each person (section 6.1); a
			/* field that overstates is the same failure as one that lies.
			*/

			$hadCal = 0;
			$calRes = mysql_query(
				'SELECT COUNT(*) AS n FROM calendars WHERE `call` = ' . $callID .
				' AND `user` = ' . $userID
			);

			if ($calRes !== false)
			{
				$calRow = mysql_fetch_object($calRes);
				$hadCal = $calRow ? (int) $calRow->n : 0;
			}

			$sss->removeFromCalendar($callID, $userID);

			$cleared = ($hadCal > 0);
		}

		/*
		/* Who app.py must tell. Offered crew (0/1) are included deliberately:
		/* an outstanding offer on a dead call must be withdrawn or ops chase a
		/* ghost. Declined (6) and no-show (8) are not contacted -- they already
		/* answered, or already didn't turn up.
		*/

		if ($prev === 0 || $prev === 1 || $prev === 5 || $prev === 7)
			$notify[] = $userID;

		$results[] = array(
			'user_id'          => $userID,
			'prev_status'      => $prev,
			'changed'          => $changed,
			'calendar_cleared' => $cleared
		);
	}

	/* ---- mark the call ---- */

	$now = time();

	/*
	/* The name prefix is presentation for humans in SmartStaff's own legacy
	/* screens (decision D3), which know nothing about cancelled_at and will not
	/* until a later phase. It also formalises what ops already do by hand --
	/* "Crew Boss Cancelled" is in live production data -- which is why
	/* resolve-call-contact.php's %cancel% guard works at all today.
	/*
	/* cancelled_at is the truth for machines. NOTHING new may branch on this
	/* string. Applied only when absent, so re-running never doubles it.
	*/

	$name    = $call->call_name;
	$prefix  = 'CANCELLED - ';
	$newName = (strpos($name, $prefix) === 0) ? $name : ($prefix . $name);

	$db->update(
		'calls',
		array(
			'cancelled_at'  => $now,
			'cancelled_by'  => $actorID,
			'cancel_reason' => q($reason),
			'cancel_charge' => $chargeable,
			'call_name'     => q($newName)
		),
		'id=' . $callID
	);

	if (mysql_error())
	{
		send_status(500, 'Internal Server Error');
		die('{"error":"call cancel write failed: ' . addslashes(mysql_error()) . '"}');
	}

	/* ---- respond ----
	/*
	/* "notify" is the contract with app.py (slice E): it fires one Crew Hub push
	/* per userID and reports per-crew delivery back to the UI. Delivery is NOT
	/* assumed here -- gp_notify_offer no-ops silently for anyone who has never
	/* logged into Crew Hub, and ops need to know who to ring.
	*/

	/*
	/* Wall-clock ISO for the Crew Hub push -- "2026-09-03T08:00:00", no zone
	/* suffix, matching what gp_notify_change already sends as `start`. The
	/* portal's whenLabel() splits on "T" and reads the parts as literal numbers,
	/* so an offset here would be ignored or would shift the displayed time.
	/*
	/* Empty when start_date is missing or zero, NEVER a fabricated date: the
	/* portal drops the segment on an empty label, so the notification loses the
	/* date rather than gaining a wrong one.
	*/

	$startIso = ((int) $call->start_date > 0)
		? date('Y-m-d', (int) $call->start_date) . 'T' . $call->start_time
		: '';

	echo json_encode(array(
		'ok'           => true,
		'call_id'      => $callID,
		'booking_id'   => (int) $call->bookingID,
		'call_name'    => $newName,
		'start'        => $startIso,
		'cancelled_at' => $now,
		'cancelled_by' => $actorID,
		'chargeable'   => $chargeable,
		'reason'       => $reason,
		'crew'         => $results,
		'notify'       => $notify,
		'feeds'        => $fed
	));

?>
