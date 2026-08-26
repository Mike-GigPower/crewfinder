<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');
	include_once('resolve-call-contact.php');
	/*
	/* SLICE C2 — THE OPT-IN. Switches rung 2 of the contact hierarchy to prefer
	/* an explicit call_supervision edge over time overlap.
	/*
	/* THIS LINE LOOKS UNUSED AND IS NOT. Nothing in this file calls into
	/* supervision-graph.php directly; resolve-call-contact.php reaches it via
	/* function_exists('goat_supervision_boss_call'), guarded at the call site to
	/* avoid a circular include (see the comment there). Remove this include and
	/* the resolver silently falls back to overlap-only — no error, no failed
	/* test, because overlap is still a valid code path. It just stops finding
	/* the boss of any call that does not happen to overlap its boss call.
	*/
	include_once('supervision-graph.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SELF endpoint — the acting crew member's BACKUP calls: the ones they
	/* accepted after the call was already full, call_crew_map.status = 7
	/* (Backup). These are the "you're on standby" calls.
	/*
	/* Backups are NOT in the `calendars` table (a calendar row is only created
	/* on confirm), and they are excluded from BOTH my-call-offers.php (which is
	/* status IN (0,1)) and my-shifts.php (confirmed-only) — so without this
	/* endpoint a backed-up call is invisible to the crew member. Each call's
	/* wall-clock start/end is resolved from calls.start_date (unix date) +
	/* start_time (time) + est_length (hours), emitted in the same ISO shape as
	/* my-call-offers.php / my-shifts.php so the portal renders it with the same
	/* formatter.
	/*
	/* Read-only: a backup can't be confirmed or declined by the crew member from
	/* here (that guard lives in respond-to-call.php, which only acts on status
	/* <= 1). Promotion is an admin action. Whether a crew member may withdraw
	/* from standby is a Phase 3 UX decision — see the handover doc.
	/*
	/* PHP 5.x -- mysql_*, no null-coalescing (??), no short array syntax.
	*/

	$userID = (int) goat_acting_user_id();

	if ($userID <= 0)
	{
		http_response_code(403);
		die('{"error":"not authorised"}');
	}

	$sql = "
		SELECT
			calls.id          AS call_id,
			calls.bookingID   AS booking_id,
			calls.call_name   AS call_name,
			calls.start_date  AS start_date,
			calls.start_time  AS start_time,
			calls.est_length  AS est_length,
			calls.required    AS required,
			calls.link_group  AS link_group,
			call_crew_map.status       AS status,
			call_crew_map.is_call_boss AS is_call_boss,
			bookings.name     AS booking_name,
			venues.venue      AS venue_name,
			venues.address    AS venue_address,
			venues.suburb     AS venue_suburb,
			venues.state      AS venue_state,
			venues.postcode   AS venue_postcode,
			cca.prev_start_date AS prev_start_date,
			cca.prev_start_time AS prev_start_time,
			cca.prev_est_length AS prev_est_length,
			cca.changed_at      AS changed_at
		FROM call_crew_map
		LEFT JOIN calls    ON call_crew_map.callID  = calls.id
		LEFT JOIN bookings ON calls.bookingID       = bookings.id
		LEFT JOIN venues   ON bookings.venueID      = venues.id
		LEFT JOIN call_change_ack cca
		       ON cca.callID = call_crew_map.callID
		      AND cca.userID = call_crew_map.userID
		WHERE call_crew_map.userID = " . $userID . "
		  AND call_crew_map.status = 7
		  AND calls.id IS NOT NULL
		ORDER BY calls.start_date ASC, calls.start_time ASC
	";

	$res = mysql_query($sql);

	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"backups query failed: ' . addslashes(mysql_error()) . '"}');
	}

	$backups = array();

	while ($row = mysql_fetch_object($res))
	{
		$dateStr    = date('Y-m-d', (int) $row->start_date);
		$startUnix  = strtotime($dateStr . ' ' . $row->start_time);
		$lengthSecs = (int) round(((double) $row->est_length) * 3600);
		$endUnix    = $startUnix + $lengthSecs;

		$entry = array(
			'call_id'      => (int) $row->call_id,
			'booking_id'   => (int) $row->booking_id,
			'call_name'    => $row->call_name,
			'booking_name' => $row->booking_name,
			'venue'          => $row->venue_name,
			'venue_address'  => (string) $row->venue_address,
			'venue_suburb'   => (string) $row->venue_suburb,
			'venue_state'    => (string) $row->venue_state,
			'venue_postcode' => (string) $row->venue_postcode,
			'start'        => date('Y-m-d\TH:i:s', $startUnix),
			'end'          => date('Y-m-d\TH:i:s', $endUnix),
			'est_length'   => (double) $row->est_length,
			'required'     => (int) $row->required,
			'link_group'   => ($row->link_group === null ? null : (int) $row->link_group),
			'status'       => (int) $row->status,
			'is_call_boss' => (int) $row->is_call_boss
		);

		/* Contact hierarchy — future standby calls only (see my-shifts.php). */

		if ($endUnix >= time())
			$entry['contacts'] = goat_resolve_call_contact((int) $row->call_id, $userID);

		/*
		/* A matched call_change_ack row means this standby call's timing changed
		/* since the crew member was contacted. Emit the "was" timing as a heads-up
		/* (the portal renders it as a heads-up, not action-needed). Resolved the
		/* same way as the display start/end above. No match -> omit change_pending.
		*/
		if ($row->prev_start_date !== null)
		{
			$prevDateStr = date('Y-m-d', (int) $row->prev_start_date);
			$prevStartTs = strtotime($prevDateStr . ' ' . $row->prev_start_time);
			$prevEndTs   = $prevStartTs + (int) round(((double) $row->prev_est_length) * 3600);

			$entry['change_pending'] = true;
			$entry['prev_start']     = date('Y-m-d\TH:i:s', $prevStartTs);
			$entry['prev_end']       = date('Y-m-d\TH:i:s', $prevEndTs);
			$entry['changed_at']     = (int) $row->changed_at;
		}

		$backups[] = $entry;
	}

	echo json_encode(array('backups' => $backups));

?>
