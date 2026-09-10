<?php

	/*
	/* CREW EMERGENCY CARD — THE ACCESS DECISION.
	/*
	/* ONE function answers "may this viewer see this crew member's emergency
	/* card". Every surface asks it and nothing re-derives it. See
	/* DESIGN-crew-emergency-card-v0_1.md D11/D12.
	/*
	/* WHY THIS IS IN PHP AND NOT IN app.py. Until web-GOAT, THE GOAT runs on
	/* Rich's and Monty's own Macs, so a rule enforced in app.py is a rule
	/* enforced on the machine of the person it constrains. Crew Hub is a real
	/* server but calls with a service key this box cannot verify. This file is
	/* the only boundary both surfaces cross that neither viewer controls.
	/*
	/* IT DECIDES NOTHING ABOUT IDENTITY. The caller establishes who the viewer
	/* is -- from $_SESSION on the GOAT path, or from the service-key branch on
	/* the Crew Hub path -- and passes the id in. That split is deliberate:
	/* identity is the endpoint's problem, authorisation is this file's, and
	/* the function stays testable because it takes ids rather than reading a
	/* session.
	/*
	/* PHP 5.6. mysql_* for reads that join the legacy tables, because the
	/* helpers this file composes are mysql_*; PDO is used only by the endpoints
	/* for the crew_emergency_* tables. Two connections per request is fine and
	/* nothing here shares a transaction with call_crew_map.
	*/

	include_once(__DIR__ . '/cohort.php');
	include_once(__DIR__ . '/supervision-graph.php');

	/*
	/* PHASE GATE. Rules 1-3 (self, admin, operations) are live. Rule 4 (crew
	/* boss) is WRITTEN AND TESTED BUT OFF, because the data it depends on is
	/* not populated: over the twelve complete months to Aug 2026 only 8.2% of
	/* calls had an in-call boss, and combined with call_supervision the last
	/* 90 days reach 18.3% -- an upper bound, not a floor. A boss-facing medical
	/* icon absent on four calls in five is the false-clear failure D3 exists to
	/* prevent.
	/*
	/* Flip this to true ONLY with a coverage decision behind it. The code path
	/* is already exercised by emergency-scope-test.php with the flag forced on,
	/* so turning it on is a decision, not a development project.
	*/

	if (!defined('GOAT_EMERGENCY_BOSS_SCOPE'))
	{
		define('GOAT_EMERGENCY_BOSS_SCOPE', false);
	}

	/*
	/* THE WINDOW, in whole days either side of a call's date.
	/*
	/* calls.start_date is a unix timestamp at LOCAL MIDNIGHT -- a date, not a
	/* datetime -- with start_time and est_length carried separately. The window
	/* is therefore computed on start_date alone and NEVER touches est_length,
	/* which carries a live truncation defect
	/* (FINDINGS-addtocalendar-est-length-truncation-2026-09-10.md). At this
	/* resolution hour precision buys nothing and would inherit that bug.
	/*
	/* BOTH NUMBERS ARE PLACEHOLDERS AND MUST BE MEASURED BEFORE RULE 4 SHIPS.
	/* The 90-day window in CHANGELOG-phase2a-slices-5-5b-C3-C5.md earned its
	/* value by counting; these have not. The question to answer is how far
	/* ahead boss flags and supervision edges are actually set -- if they land
	/* the day before, LEAD_DAYS 7 is theatre.
	/*
	/* Deliberately far tighter than goat_boss_scope()'s 90-day reach. That
	/* window exists so a boss can reconcile times after a shift. This one
	/* exists so somebody can act during one.
	*/

	if (!defined('GOAT_EMERGENCY_LEAD_DAYS')) { define('GOAT_EMERGENCY_LEAD_DAYS', 7); }
	if (!defined('GOAT_EMERGENCY_TAIL_DAYS')) { define('GOAT_EMERGENCY_TAIL_DAYS', 2); }

	/*
	/* Uniform shape on every path. call_id is the call that justified a boss
	/* grant and is null for every other reason -- cohort access is not about a
	/* call and must not pretend to be.
	*/

	function goat_emergency_decision($allowed, $reason, $callID)
	{
		return array(
			'allowed' => (bool) $allowed,
			'reason'  => $reason,
			'call_id' => ($callID > 0) ? (int) $callID : null
		);
	}

	/*
	/* MAY $viewerUserID SEE $subjectUserID's CARD?
	/*
	/* Both are SmartStaff users.id. NEVER an EIN -- admin accounts carry
	/* ein "0", a shared placeholder, so EIN is not an identity anywhere in
	/* this feature.
	/*
	/* Rules in order, first match wins:
	/*
	/*   1  self                     the crew member's own card
	/*   2  cohort admin
	/*   3  cohort operations
	/*   4  crew boss in scope       PHASE 2, gated by GOAT_EMERGENCY_BOSS_SCOPE
	/*   -  otherwise                denied
	/*
	/* `leadership` IS NOT GRANTED. Do not gate this on goat_can_read_all():
	/* that helper admits admin, leadership AND operations plus a service-key
	/* path, and reaching for it here would widen the audience silently.
	*/

	function goat_emergency_scope($viewerUserID, $subjectUserID)
	{
		$viewerUserID  = (int) $viewerUserID;
		$subjectUserID = (int) $subjectUserID;

		if ($viewerUserID <= 0 || $subjectUserID <= 0)
		{
			return goat_emergency_decision(false, 'denied', 0);
		}

		/* ---- RULE 1 — self ---- */

		if ($viewerUserID === $subjectUserID)
		{
			return goat_emergency_decision(true, 'self', 0);
		}

		/* ---- RULES 2 and 3 — cohort ----
		/*
		/* goat_cohort_for_user() rather than goat_user_cohort(): the latter
		/* reads $_SESSION, which is right for the GOAT path and wrong for the
		/* Crew Hub service path. Taking the id as a parameter makes one rule
		/* serve both, and makes the function testable without a session. */

		$cohort = goat_cohort_for_user($viewerUserID);

		if ($cohort === 'admin')
		{
			return goat_emergency_decision(true, 'cohort_admin', 0);
		}

		if ($cohort === 'operations')
		{
			return goat_emergency_decision(true, 'cohort_operations', 0);
		}

		/* ---- RULE 4 — crew boss ---- */

		if (!GOAT_EMERGENCY_BOSS_SCOPE)
		{
			return goat_emergency_decision(false, 'denied', 0);
		}

		/*
		/* goat_boss_scope() IS the predicate. It already unions DIRECT
		/* (confirmed and flagged is_call_boss on the call) with SUPERVISORY
		/* (every child of a dedicated boss call they are confirmed on), it is
		/* already correct about the binary(50) trap, and it is already the
		/* single definition. An earlier draft of the design specified two
		/* fresh queries for this; that was reinvention.
		*/

		$scope = goat_boss_scope($viewerUserID);

		if (count($scope) === 0)
		{
			return goat_emergency_decision(false, 'denied', 0);
		}

		$inScope = array();

		foreach ($scope as $cid)
		{
			$inScope[(int) $cid] = true;
		}

		/*
		/* The subject's CONFIRMED calls inside the window. status = 5 is
		/* confirmed -- the same constant goat_boss_scope() uses, so both sides
		/* of the intersection agree on what "on this call" means.
		/*
		/* strtotime('today') is local midnight in the server's timezone, which
		/* is the same frame start_date was written in, so the comparison is
		/* self-consistent without offset arithmetic.
		*/

		$windowOpen  = strtotime('today') - (GOAT_EMERGENCY_TAIL_DAYS * 86400);
		$windowClose = strtotime('today') + (GOAT_EMERGENCY_LEAD_DAYS * 86400);

		$res = mysql_query("SELECT ccm.callID AS n
		                    FROM call_crew_map ccm
		                    INNER JOIN calls c ON c.id = ccm.callID
		                    WHERE ccm.userID   = " . $subjectUserID . "
		                      AND ccm.status   = 5
		                      AND c.start_date >= " . (int) $windowOpen . "
		                      AND c.start_date <= " . (int) $windowClose . "
		                    ORDER BY c.start_date ASC, c.start_time ASC");

		if ($res === false)
		{
			/* Fail CLOSED. A broken query must never read as a grant. */
			return goat_emergency_decision(false, 'denied', 0);
		}

		while ($row = mysql_fetch_object($res))
		{
			$callID = (int) $row->n;

			if ($callID <= 0 || !isset($inScope[$callID]))
			{
				continue;
			}

			/*
			/* Scope decided; the inversion supplies the REASON, in the
			/* estate's own vocabulary (direct > container > supervisory, its
			/* precedence already applied by goat_boss_claim()). The log then
			/* speaks the same language as the resolver that granted access.
			/*
			/* boss_unattributed should be unreachable: it means the scope
			/* function granted a call the inversion cannot attribute to this
			/* viewer. The two are built on the same relations, so a row of it
			/* is a real disagreement worth finding -- which is exactly why it
			/* is a distinct queryable value rather than a guessed reason in an
			/* audit column.
			*/

			$reason = 'boss_unattributed';
			$byCall = goat_bosses_by_call(array($callID));

			if ($byCall['ok']
			    && isset($byCall['by_call'][$callID][$viewerUserID]))
			{
				$reason = 'boss_' . $byCall['by_call'][$callID][$viewerUserID];
			}

			return goat_emergency_decision(true, $reason, $callID);
		}

		return goat_emergency_decision(false, 'denied', 0);
	}
