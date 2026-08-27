<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* SLICE C5 — needed for goat_boss_scope() and goat_boss_calls_for_user(),
	/* which build can_see_crew below. include_once, not include: this file is
	/* function_exists-guarded but resolve-call-contact.php beneath it is not.
	*/
	include_once('supervision-graph.php');

	/*
	/* How far back the SUPERVISION branches of can_see_crew reach. One line to
	/* change, with a comment, rather than a bare 90 buried in a boolean —
	/* the number is a policy decision and will be revisited.
	*/
	define('HISTORY_SCOPE_DAYS', 90);

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SELF endpoint — returns the logged-in user's OWN past confirmed shifts.
	/* Scoped to goat_acting_user_id(), so a crew member can only ever read their
	/* own history. No admin gate. Same dual-trust pattern as my-shifts.php
	/* (session OR X-Goat-Service-Key), so no cohort.php change is needed.
	/*
	/* WHY THIS DOES NOT REUSE my-shifts.php
	/*
	/*  1. my-shifts.php caps its window at 120 days. A full history is 16+ years,
	/*     which would be ~50 sequential requests (SmartStaff holds a PHP file
	/*     lock, so they cannot be parallelised).
	/*  2. my-shifts.php reads the `calendars` table and treats a type-2 row as
	/*     the shift. A calendar row is a derived artefact that can be missing or
	/*     purged for old calls. call_crew_map is the actual record of "this
	/*     person was on this call", so history reads that directly.
	/*
	/* WHAT COUNTS AS HISTORY
	/*
	/*  status = 5 (Confirmed) only. Verified against 16 years of data: worked
	/*  shifts are never moved off status 5 once done, so 5 is a safe test for
	/*  the past as well as the future. Declined (6), no-show (8) and unanswered
	/*  (0/1) rows are deliberately excluded — this page answers "what have I
	/*  worked", not "what was I offered".
	/*
	/*  Only calls that STARTED BEFORE TODAY are returned. Using the start date
	/*  (not the end) means an overnight load-out that began 23:00 yesterday and
	/*  finished 06:00 today counts as yesterday's shift, which is how crew think
	/*  about it. Today's shifts stay on /shifts until tomorrow.
	/*
	/* PERFORMANCE
	/*
	/*  The WHERE leads on ccm.userID, which is the first column of the existing
	/*  (userID, callID) index — so this is an index read of roughly 70 rows for a
	/*  typical crew member and ~4,600 for the heaviest. The joins out to calls,
	/*  bookings and venues are all primary-key lookups. No new index required,
	/*  which matters: call_crew_map is MyISAM with ~246k rows and an ALTER would
	/*  lock it.
	/*
	/* TIMES
	/*
	/*  calls.start_date is a real unix timestamp; calls.start_time is a separate
	/*  wall-clock string. They are combined the same way my-shifts.php combines
	/*  the prev_start_* columns. `start` is emitted as a wall-clock ISO string
	/*  with NO timezone, matching every other crew endpoint — the portal must
	/*  never build a Date() from it.
	*/


	$userID = (int) goat_acting_user_id();

	/*
	/* Optional safety cap. The portal does not pass this; it exists so a future
	/* caller cannot accidentally pull an unbounded result set. We select one row
	/* MORE than the cap so we can honestly report whether anything was cut off.
	*/

	$limit = 6000;

	if (isset($_GET['limit']))
	{
		$requested = (int) $_GET['limit'];

		if ($requested > 0 && $requested <= 10000)
			$limit = $requested;
	}

	$fetch_limit = $limit + 1;

	/* midnight this morning, server time — the boundary between "history" and
	   "upcoming" */

	$today_ts = (int) strtotime('today 00:00:00');

	/*
	/* HISTORIC WINDOW for the supervision branches of can_see_crew.
	/*
	/* The justification for post-shift roster access is that times are
	/* submitted after the shift. That covers last week. It does not cover a
	/* call from 2012, and what the link leads to is crew names and mobile
	/* numbers.
	/*
	/* APPLIED TO THE SUPERVISION BRANCHES ONLY. is_call_boss keeps its
	/* unbounded historic grant: it has had it since this endpoint existed, and
	/* narrowing it is a separate policy change rather than a rider on this
	/* one. Test 10 is the assertion that this was applied to the right
	/* branches — a flagged boss on a call from years ago must still read 1.
	/*
	/* On test this took one boss from 359 newly-linked past calls down to the
	/* handful inside the window.
	/*
	/* Derived from $today_ts, which the start_date filter below already needs,
	/* so the whole window costs one strtotime() outside the row loop.
	*/

	$history_window_ts = (int) strtotime('-' . HISTORY_SCOPE_DAYS . ' days', $today_ts);

	$sql = "
		SELECT
			ccm.is_call_boss AS is_call_boss,
			c.id             AS call_id,
			c.bookingID      AS booking_id,
			c.call_name      AS call_name,
			c.start_date     AS start_date,
			c.start_time     AS start_time,
			c.est_length     AS est_length,
			b.name           AS booking_name,
			v.venue          AS venue_name,
			v.suburb         AS venue_suburb
		FROM call_crew_map ccm
		JOIN calls c ON c.id = ccm.callID
		LEFT JOIN bookings b ON b.id = c.bookingID
		LEFT JOIN venues   v ON v.id = b.venueID
		WHERE ccm.userID = $userID
		  AND ccm.status = 5
		  AND c.start_date < $today_ts
		ORDER BY c.start_date DESC, c.start_time DESC
		LIMIT $fetch_limit
	";

	$result = mysql_query($sql);

	if ($result === false)
	{
		http_response_code(500);
		die('{"error":"query failed: ' . addslashes(mysql_error()) . '"}');
	}

	/*
	/* SUPERVISORY SETS, RESOLVED ONCE — ADDED 27 Aug 2026 (slice C5).
	/*
	/* Both are WHOLE-SCOPE queries, not per-call tests. Inside the row loop
	/* they would be TWO QUERIES PER ROW over a history that runs to 16 years
	/* and thousands of rows. Resolve once, test with in_array() below.
	/*
	/*   $bossScope — calls this user may ACT ON: flagged on the call itself,
	/*                or a child of a dedicated boss call they are confirmed on.
	/*   $bossCalls — the user's OWN dedicated boss calls. A SEPARATE SET:
	/*                goat_boss_scope() reaches a container only through its
	/*                direct branch, which requires the flag, and its
	/*                supervisory branch adds a boss call's CHILDREN and never
	/*                the boss call itself. A confirmed-but-unflagged boss —
	/*                Q4's normal case, 18 of 22 containers on test — is absent
	/*                from their own Crew Boss call's scope without this.
	/*
	/* ---- CURRENT SCOPE AGAINST HISTORIC CALLS, AND IT IS NOT CHECKED ----
	/*
	/* READ THIS BEFORE TRUSTING can_see_crew ON AN OLD ROW. Both helpers
	/* resolve against TODAY'S call_crew_map and call_supervision rows. This
	/* endpoint returns shifts going back years. Nothing here asks who held the
	/* call AT THE TIME, because nothing in the schema records it.
	/*
	/* So: a supervision edge deleted since the shift means a boss who genuinely
	/* ran that call in March reads 0 today — their access lapses silently. An
	/* edge added later grants access to a call they did not run (narrower than
	/* it sounds: the roster is colleagues on a booking they were on, since the
	/* row only exists because they were confirmed on the call).
	/*
	/* NEITHER IS NEW AND THE UNION DOES NOT MAKE IT WORSE. is_call_boss has
	/* had exactly this property since day one — unflagging someone removes
	/* their historic access already. Fixing it properly means recording who
	/* held a call at the time, which is a schema change and not this slice.
	/* Documented rather than solved, so the next reader does not assume the
	/* historic call was checked against historic scope. It was not.
	*/

	$bossScope = goat_boss_scope($userID);
	$bossCalls = goat_boss_calls_for_user($userID);

	$shifts    = array();
	$seen      = 0;
	$truncated = false;

	while ($row = mysql_fetch_object($result))
	{
		$seen++;

		/* the extra row we deliberately over-selected — stop, and say so */

		if ($seen > $limit)
		{
			$truncated = true;
			break;
		}

		$start_ts = strtotime(date('Y-m-d', (int) $row->start_date) . ' ' . $row->start_time);

		/*
		/* Inside the supervision window? Compared on start_date, the INT unix
		/* timestamp at LOCAL MIDNIGHT, against a bound derived the same way —
		/* not against $start_ts, which carries start_time and would make the
		/* boundary depend on what time of day the call began.
		*/

		$in_window = ((int) $row->start_date >= $history_window_ts);

		/*
		/* html_entity_decode is a no-op on clean data, but booking and venue
		/* names entered through SmartStaff's admin UI can carry encoded
		/* entities (a "&" saved as "&amp;"). React escapes on render, so an
		/* undecoded entity would display literally as "&amp;".
		*/

		$shifts[] = array(
			'call_id'      => (int) $row->call_id,
			'booking_id'   => (int) $row->booking_id,
			'call_name'    => html_entity_decode((string) $row->call_name,    ENT_QUOTES, 'UTF-8'),
			'booking_name' => html_entity_decode((string) $row->booking_name, ENT_QUOTES, 'UTF-8'),
			'venue'        => html_entity_decode((string) $row->venue_name,   ENT_QUOTES, 'UTF-8'),
			'suburb'       => html_entity_decode((string) $row->venue_suburb, ENT_QUOTES, 'UTF-8'),
			'start'        => date('Y-m-d\TH:i:s', $start_ts),
			'est_length'   => (double) $row->est_length,
			'is_call_boss' => ((int) $row->is_call_boss === 1) ? 1 : 0,

			/*
			/* MAY this crew member open the crew list for this past call? A
			/* DIFFERENT QUESTION FROM is_call_boss, which stays exactly as it
			/* is — a fact about the call_crew_map row, read as such by the
			/* history page's "Call Boss" badge and its search token.
			/*
			/* The same three-way union my-call-crew.php gates on since slices
			/* C3 and C4, so the link and the endpoint agree. Before this, a
			/* supervising boss was refused the roster AFTER the shift — which
			/* is precisely when the no-time-window decision exists to serve
			/* them, because that is when crew time is submitted.
			/*
			/* THE TWO SUPERVISION BRANCHES ARE WINDOWED and the flag branch is
			/* not — see $history_window_ts above. That asymmetry is deliberate,
			/* not an oversight to tidy up.
			/*
			/* NOTE THE ENDPOINT DISAGREES WITH THE LINK BEYOND THE WINDOW, and
			/* in the safe direction: my-call-crew.php has no window, so a
			/* supervising boss who types the URL for a call from 2012 still
			/* gets the roster. This hides the link, it does not close the door.
			/* Closing it means windowing that gate too, which is a policy
			/* decision about the endpoint rather than about this list.
			*/

			'can_see_crew' => (((int) $row->is_call_boss === 1)
			                   || ($in_window && in_array((int) $row->call_id, $bossScope))
			                   || ($in_window && in_array((int) $row->call_id, $bossCalls))) ? 1 : 0,
		);
	}

	echo json_encode(array(
		'generated_at' => date('Y-m-d\TH:i:s'),
		'count'        => count($shifts),
		'truncated'    => $truncated,
		'shifts'       => $shifts,
	));

?>
