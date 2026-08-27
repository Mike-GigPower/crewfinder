<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');
	include_once('supervision-graph.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SELF endpoint — returns the crew roster for ONE call, but ONLY to the
	/* crew member who is the CALL BOSS on that call. Scoped to
	/* goat_acting_user_id(), same dual-trust pattern as my-shifts.php and
	/* my-shift-history.php (session OR X-Goat-Service-Key). No admin gate, and
	/* deliberately NOT goat_can_read_all() — a call boss is an ordinary crew
	/* member, so the read-all gate would refuse them.
	/*
	/* THIS IS THE MIRROR OF resolve-call-contact.php.
	/*
	/*   resolve-call-contact.php answers "who does this crew member ring?"
	/*                            (crew -> boss, emitted on my-shifts.php)
	/*   this file answers        "who is on my call, and what are their numbers?"
	/*                            (boss -> crew)
	/*
	/* WHY THE AUTHORISATION LIVES HERE AND NOT IN THE PORTAL
	/*
	/*  The portal could get the same data from get-booking.php with the service
	/*  key and filter it in TypeScript. It must not. That endpoint returns EVERY
	/*  call in the booking, every crew member's mobile, plus their EIN and email
	/*  — so a filtering mistake in the UI layer would leak a whole booking's
	/*  contact list. Authorising next to the data means the wrong person cannot
	/*  be handed a roster no matter what the caller asks for.
	/*
	/* THE GATE — TWO WAYS TO BE THE BOSS, AND EITHER IS ENOUGH
	/*
	/*  DIRECT: the acting user has a call_crew_map row for THIS call with
	/*  is_call_boss = 1 AND status = 5 (Confirmed). Per-call, never
	/*  per-booking: a linked package can make someone boss of the load-in and
	/*  an ordinary hand on the load-out, and they get the roster for the first
	/*  only.
	/*
	/*  SUPERVISORY: the call is in the user's goat_boss_scope() — they are
	/*  confirmed on a dedicated boss call that supervises it. ADDED 27 Aug
	/*  2026 (slice C3). This file predates call_supervision and asked only the
	/*  first question, so a supervising boss got a 403 on the Crew List button
	/*  while submit-call-times.php and my-call-times.php — which gate on
	/*  goat_boss_scope() — let them enter the same people's hours. Being able
	/*  to record someone's pay and not see who they are was the bug.
	/*
	/*  THE CONTAINER ITSELF: the call is one of the user's own dedicated boss
	/*  calls, from goat_boss_calls_for_user(). ADDED 27 Aug 2026 (slice C4),
	/*  and it is NOT covered by the branch above. goat_boss_scope() reaches a
	/*  container only through its DIRECT branch, which requires the flag; the
	/*  supervisory branch adds a boss call's CHILDREN and never the boss call
	/*  itself. So a confirmed-but-unflagged boss — Q4's normal case — could
	/*  read the roster of every call they supervised and was refused the
	/*  roster of the Crew Boss call they were standing on, which
	/*  my-boss-calls.php had already been handing them since 27 Aug. Two
	/*  endpoints disagreeing about one call is the same failure slice C3 fixed
	/*  one layer down.
	/*
	/*  This grants a confirmed resource on a boss call sight of that call's
	/*  own roster — their colleagues on the call they are working, and a
	/*  narrower set than the children they already see.
	/*
	/*  Anything else is a flat 403 with no body detail — a refusal must not tell
	/*  the caller whether the call exists or who is on it, and the two failure
	/*  modes are deliberately indistinguishable from each other.
	/*
	/* WHO IS RETURNED
	/*
	/*  status 5 (Confirmed) and status 7 (Backup / standby) only.
	/*
	/*  Standby is included on purpose: a boss chasing a gap on the night needs
	/*  the people most likely to fill it. Each row carries its status so the two
	/*  are distinguishable on screen — a boss who cannot tell them apart will
	/*  ring standby crew thinking they are late.
	/*
	/*  Declined (6) is excluded and must stay excluded: ringing someone who
	/*  pulled out days ago is worse than having no list. Unanswered (0/1) is
	/*  excluded too — they have not agreed to anything yet. No-show (8) is
	/*  excluded for now; revisit when crew-boss time entry lands, since a
	/*  no-show still belongs on a time sheet at zero hours.
	/*
	/* NO TIME WINDOW
	/*
	/*  Deliberate. The roster stays readable after the call ends because crew
	/*  bosses submit the crew's time afterwards, and will submit it through this
	/*  route once that is built. The gate is the call_crew_map row, which has no
	/*  date in it, so past and future calls behave identically.
	/*
	/* WHAT IS NOT RETURNED
	/*
	/*  EIN and email. get-booking.php emits both to ops; neither is any use for
	/*  running a shift, so neither crosses to a crew member. Names and phone
	/*  numbers only.
	/*
	/* TIMES
	/*
	/*  calls.start_date is a unix timestamp; calls.start_time is a separate
	/*  wall-clock string. Combined the same way my-shift-history.php combines
	/*  them, and emitted as a wall-clock ISO string with NO timezone, matching
	/*  every other crew endpoint — the portal must never build a Date() from it.
	*/


	$userID = (int) goat_acting_user_id();

	if ($userID <= 0)
	{
		http_response_code(403);
		die('{"error":"not authorised"}');
	}

	$callID = isset($_GET['callID']) ? (int) $_GET['callID'] : 0;

	if ($callID <= 0)
	{
		http_response_code(400);
		die('{"error":"callID required"}');
	}

	/*
	/* THE GATE. Read the acting user's own row on this call — it answers the
	/* DIRECT branch below. A supervising boss has no row here, which is not a
	/* failure. Nothing past the gate runs for anyone who satisfies neither
	/* branch.
	*/

	$me = $db->selectFirst(
		'status, is_call_boss',
		'call_crew_map',
		'userID=' . $db->sc($userID) . ' AND callID=' . $db->sc($callID)
	);

	/*
	/* Boss of this call EITHER by is_call_boss on the call itself, OR by
	/* supervising it from a dedicated boss call (call_supervision). The second
	/* branch did not exist when this file was written: goat_boss_scope() is the
	/* same gate submit-call-times.php and my-call-times.php use, so a boss who
	/* can enter a person's hours can now also see who they are. Without it the
	/* two disagree, which is the bug this fixes.
	/*
	/* $me MAY BE FALSE HERE, AND THAT IS THE WHOLE POINT. A supervising boss
	/* is not on the child call at all, so they have no call_crew_map row to
	/* read. The null check lives INSIDE $direct rather than short-circuiting
	/* the gate, or the supervisory branch would never be reached.
	/*
	/* goat_boss_scope() IS RECOMPUTED PER REQUEST. Never cached, never passed
	/* in, and there is no parameter that widens it.
	/*
	/* is_call_boss is binary(50) — the (int) cast stays, and it is never
	/* compared with "= 1" in SQL.
	/*
	/* BOTH FAILURE MODES RETURN THE SAME BODY, DELIBERATELY. "Not the boss"
	/* and "not in scope" are indistinguishable to a caller, because a refusal
	/* must not tell you why — see the header.
	*/

	$direct = ($me && (int) $me->is_call_boss === 1 && (int) $me->status === 5);

	if (!$direct
	    && !in_array($callID, goat_boss_scope($userID))
	    && !in_array($callID, goat_boss_calls_for_user($userID)))
	{
		http_response_code(403);
		die('{"error":"not the call boss for this call"}');
	}

	/*
	/* Call + booking + venue + booking contact + on-site contact.
	/*
	/* Schema notes mirror get-booking.php's join:
	/*   bookings.userID       -> the booking CONTACT (a users row)
	/*   bookings.onsiteUserID -> the ON-SITE contact (a users row)
	*/

	$sql = "SELECT c.id AS call_id, c.call_name, c.start_date, c.start_time,
	               c.est_length, c.required,
	               b.id AS booking_id, b.name AS booking_name,
	               v.venue, v.address, v.suburb, v.state, v.postcode,
	               ct.firstname AS ct_first, ct.lastname AS ct_last,
	               ct.phone AS ct_phone, ct.mobile AS ct_mobile,
	               os.firstname AS os_first, os.lastname AS os_last,
	               os.phone AS os_phone, os.mobile AS os_mobile
	        FROM calls c
	        LEFT JOIN bookings b ON b.id = c.bookingID
	        LEFT JOIN venues   v ON v.id = b.venueID
	        LEFT JOIN users   ct ON ct.id = b.userID
	        LEFT JOIN users   os ON os.id = b.onsiteUserID
	        WHERE c.id = " . $callID . "
	        LIMIT 1";

	$res = mysql_query($sql);

	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"call query failed: ' . addslashes(mysql_error()) . '"}');
	}

	$call = mysql_fetch_object($res);

	if (!$call)
	{
		/*
		/* Unreachable in practice — the gate already proved a call_crew_map row
		/* exists for this call — but a deleted call would land here.
		*/

		http_response_code(404);
		die('{"error":"call not found"}');
	}

	$start_ts = strtotime(date('Y-m-d', (int) $call->start_date) . ' ' . $call->start_time);

	/*
	/* The roster.
	/*
	/* Ordered boss first, then confirmed (5) before standby (7), then by first
	/* name — a boss on the floor is looking for the name they would shout, not
	/* a surname index. (get-booking.php orders by surname because ops read it
	/* as a list; this is read as a set of people.)
	*/

	$crres = mysql_query("SELECT u.id, u.firstname, u.lastname, u.mobile, u.phone,
	                             ccm.status, ccm.is_call_boss
	                      FROM call_crew_map ccm
	                      LEFT JOIN users u ON u.id = ccm.userID
	                      WHERE ccm.callID = " . $callID . "
	                        AND ccm.status IN (5, 7)
	                      ORDER BY ccm.is_call_boss DESC, ccm.status ASC,
	                               u.firstname ASC, u.lastname ASC");

	if ($crres === false)
	{
		http_response_code(500);
		die('{"error":"crew query failed: ' . addslashes(mysql_error()) . '"}');
	}

	/*
	/* html_entity_decode is a no-op on clean data, but names, booking names and
	/* venue names entered through SmartStaff's admin UI can carry encoded
	/* entities (an apostrophe saved as &#039;, an "&" as &amp;). React escapes
	/* on render, so an undecoded entity would display literally. Same treatment
	/* as my-shift-history.php.
	*/

	$crew      = array();
	$confirmed = 0;
	$standby   = 0;

	while ($cr = mysql_fetch_object($crres))
	{
		$st = (int) $cr->status;

		if ($st === 5)
			$confirmed++;
		else
			$standby++;

		$crew[] = array(
			'id'           => (int) $cr->id,
			'name'         => trim(html_entity_decode((string) $cr->firstname, ENT_QUOTES, 'UTF-8') . ' ' .
			                       html_entity_decode((string) $cr->lastname,  ENT_QUOTES, 'UTF-8')),
			'mobile'       => (string) $cr->mobile,
			'phone'        => (string) $cr->phone,
			'status'       => ($st === 5) ? 'confirmed' : 'standby',
			'is_call_boss' => ((int) $cr->is_call_boss === 1) ? 1 : 0,
			'is_you'       => ((int) $cr->id === $userID) ? 1 : 0,
		);
	}

	/*
	/* assemble response
	*/

	echo json_encode(array(
		'call' => array(
			'call_id'    => (int) $call->call_id,
			'call_name'  => html_entity_decode((string) $call->call_name, ENT_QUOTES, 'UTF-8'),
			'start'      => date('Y-m-d\TH:i:s', $start_ts),
			'est_length' => (double) $call->est_length,
			'required'   => (int) $call->required,
		),
		'booking' => array(
			'booking_id' => (int) $call->booking_id,
			'name'       => html_entity_decode((string) $call->booking_name, ENT_QUOTES, 'UTF-8'),
		),
		'venue' => array(
			'name'     => html_entity_decode((string) $call->venue,  ENT_QUOTES, 'UTF-8'),
			'address'  => html_entity_decode((string) $call->address, ENT_QUOTES, 'UTF-8'),
			'suburb'   => html_entity_decode((string) $call->suburb, ENT_QUOTES, 'UTF-8'),
			'state'    => (string) $call->state,
			'postcode' => (string) $call->postcode,
		),
		'contact' => array(
			'name'   => trim(html_entity_decode((string) $call->ct_first, ENT_QUOTES, 'UTF-8') . ' ' .
			                 html_entity_decode((string) $call->ct_last,  ENT_QUOTES, 'UTF-8')),
			'phone'  => (string) $call->ct_phone,
			'mobile' => (string) $call->ct_mobile,
		),
		'onsite' => array(
			'name'   => trim(html_entity_decode((string) $call->os_first, ENT_QUOTES, 'UTF-8') . ' ' .
			                 html_entity_decode((string) $call->os_last,  ENT_QUOTES, 'UTF-8')),
			'phone'  => (string) $call->os_phone,
			'mobile' => (string) $call->os_mobile,
		),
		'confirmed' => $confirmed,
		'standby'   => $standby,
		'crew'      => $crew,
	));

?>
