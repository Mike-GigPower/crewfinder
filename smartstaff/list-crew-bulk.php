<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* admin OR leadership — full crew roster including phone numbers.
	/* Leadership is read-only; this is a read endpoint, so it is permitted.
	*/

	if (!goat_can_read_all())
	{
		http_response_code(403);
		die('{"error":"Admin or Leadership only"}');
	}

	/*
	/* optional ?active=0 to include inactive crew (default: active only) */

	$active_only = !isset($_GET['active']) || $_GET['active'] != '0';
	$active_clause = $active_only ? " AND u.active = '1'" : '';

	/*
	/* 12-month cutoff for the reliability tallies. SmartStaff stores
	/* calls.start_date as a unix timestamp, so this is a plain integer compare.
	*/

	$twelve_mo_ago = strtotime('-1 year');   /* leap-safe; whole-second unix ts */

	/*
	/* 0. induction policy (the catalogue) — emitted ONCE, not per crew member.
	/*
	/* Phase 1c. Crew Hub's computeVenueStatus carries its own local 24-month set;
	/* this lets it read the real per-venue policy instead, and gives the reminders
	/* cron validity_changed_at for the self-suppress guard.
	/*
	/* Keyed by venue NAME because that is how each crew member's `inductions` map
	/* is keyed, so the consumer joins on a key it already holds. One entry per
	/* COVERED venue (23 at time of writing), not per catalogue row, since several
	/* venues share one induction (Melbourne Park covers five arenas).
	/*
	/* A venue ABSENT from this block has no catalogue row: fall back to 12 months
	/* and warn_days_default, the same fallback every other surface uses.
	/*
	/* Built BEFORE the crew roster because it does not depend on it, and because
	/* the no-crew early return below must emit the same response shape.
	*/

	/* Must match get-induction-catalogue.php and the Phase 1a crew-facing files.
	/* Phase 2 should move this into goat_settings so it has a single home. */
	$WARN_DAYS_DEFAULT = 14;

	$induction_policy = array();

	$sql_policy = "
		SELECT v.venue AS venue_name,
		       c.code AS code,
		       c.validity_months AS validity_months,
		       c.warn_days AS warn_days,
		       c.show_in_checker AS show_in_checker,
		       c.published AS published,
		       c.validity_changed_at AS validity_changed_at
		FROM venue_induction_catalogue c
		INNER JOIN venue_induction_covers cov ON cov.catalogue_id = c.id
		INNER JOIN venues v ON v.id = cov.venue_id
		ORDER BY c.sort_order ASC, v.venue ASC
	";

	$policy_result = mysql_query($sql_policy);

	if ($policy_result !== false)
	{
		while ($row = mysql_fetch_object($policy_result))
		{
			/*
			/* INNER JOIN on venues is deliberate: covers.venue_id carries no FK
			/* (venues is MyISAM, cross-engine FK unenforceable), so an orphaned
			/* covers row pointing at a deleted venue is dropped here rather than
			/* emitted under an empty key.
			/*
			/* warn_days stays null when NULL — the consumer resolves it against
			/* warn_days_default. Collapsing it here would lose the distinction
			/* between "inherits the default" and "explicitly set to 14", which
			/* the Phase 2 editor needs.
			*/
			$induction_policy[$row->venue_name] = array(
				'code'                => $row->code,
				'validity_months'     => (int) $row->validity_months,
				'warn_days'           => ($row->warn_days !== null) ? (int) $row->warn_days : null,
				'show_in_checker'     => ((int) $row->show_in_checker === 1),
				'published'           => ((int) $row->published === 1),
				'validity_changed_at' => $row->validity_changed_at ? $row->validity_changed_at : null
			);
		}
	}

	/*
	/* A failed policy query leaves the block EMPTY rather than 500-ing the whole
	/* roster: consumers fall back to 12 / warn_days_default and the crew list
	/* still loads. Same posture as sections 2-4 below.
	/*
	/* Cast to object on emit: an empty PHP array json_encodes as [] , but this is
	/* a MAP keyed by venue name and a consumer expecting an object would break on
	/* an array. (object) gives {} when empty and the same keys when populated.
	/* Note this is the OPPOSITE of get-induction-catalogue.php, which needs
	/* array_values() because its payload really is a list.
	*/

	/*
	/* 1. crew roster
	*/

	$sql_crew = "
		SELECT u.id,
		       u.firstname,
		       u.lastname,
		       u.mobile,
		       u.email,
		       u.rating,
		       u.paygradeID,
		       u.ein,
		       u.postcode,
		       u.notes,
		       u.active
		FROM users u
		WHERE u.usergroupID = 3
		$active_clause
		ORDER BY u.lastname ASC, u.firstname ASC
	";

	$crew_result = mysql_query($sql_crew);

	if ($crew_result === false)
	{
		http_response_code(500);
		die('{"error":"crew query failed: ' . addslashes(mysql_error()) . '"}');
	}

	$crew_by_id = array();

	while ($row = mysql_fetch_object($crew_result))
	{
		/* "Lastname, Firstname" — matches what the scraper currently returns */
		$display_name = trim($row->lastname);
		if (strlen(trim($row->firstname)) > 0)
			$display_name .= ', ' . trim($row->firstname);

		$crew_by_id[(int) $row->id] = array(
			'id'         => (int) $row->id,
			'name'       => $display_name,
			'firstname'  => $row->firstname,
			'lastname'   => $row->lastname,
			'mobile'     => $row->mobile,
			'email'      => $row->email,
			'rating'     => (int) $row->rating,
			'paygradeID' => (int) $row->paygradeID,
			'ein'        => $row->ein,
			'postcode'   => $row->postcode,
			'notes'      => $row->notes,
			'active'     => $row->active,
			'groups'     => array(),
			'inductions' => array(),
			'licences'   => array(),
			'stats'      => array(
				'late_all'    => 0,
				'noshow_all'  => 0,
				'late_12mo'   => 0,
				'noshow_12mo' => 0,
				'over_12mo'   => false,   /* set by section 4 below */
			),
		);
	}

	if (count($crew_by_id) == 0)
	{
		/* licences_ok true on this path: there are no crew, so no licence data is
		/* missing. Emitting false here would tell the consumer the licence query
		/* had failed and make it refuse a filter it could legitimately run. */
		echo json_encode(array(
			'crew'              => array(),
			'induction_policy'  => (object) $induction_policy,
			'warn_days_default' => (int) $WARN_DAYS_DEFAULT,
			'licences_ok'       => true,
		));
		return;
	}

	$crew_ids_csv = implode(',', array_keys($crew_by_id));

	/*
	/* 2. group memberships
	/* crew_groups_map (userID, groupID) -> crew_groups (id, group_name)
	*/

	$sql_groups = "
		SELECT m.userID AS user_id, g.group_name
		FROM crew_groups_map m
		INNER JOIN crew_groups g ON g.id = m.groupID
		WHERE m.userID IN ($crew_ids_csv)
		ORDER BY g.group_name ASC
	";

	$group_result = mysql_query($sql_groups);
	if ($group_result !== false)
	{
		while ($row = mysql_fetch_object($group_result))
		{
			$uid = (int) $row->user_id;
			if (isset($crew_by_id[$uid]))
				$crew_by_id[$uid]['groups'][] = $row->group_name;
		}
	}

	/*
	/* 3. inductions
	/* crew_venue_induction (crew_id, venue_id, complete_date) -> venues (id, venue)
	/*
	/* The table has no explicit "status" column; presence of a row means the
	/* crew member has completed the induction. complete_date is a Unix
	/* timestamp (int). We surface the date in the same human format the
	/* scraper used ("DD Mon YYYY") and mark status = "Complete" for every row
	/* returned. If SmartStaff later distinguishes Expired/Expiring Soon, that
	/* logic can be layered on (e.g. compare complete_date against an expiry
	/* policy in `venues` or `settings`).
	*/

	$sql_inductions = "
		SELECT i.crew_id AS user_id,
		       v.venue AS venue_name,
		       i.complete_date AS complete_date
		FROM crew_venue_induction i
		INNER JOIN venues v ON v.id = i.venue_id
		WHERE i.crew_id IN ($crew_ids_csv)
	";

	$ind_result = mysql_query($sql_inductions);
	if ($ind_result !== false)
	{
		while ($row = mysql_fetch_object($ind_result))
		{
			$uid = (int) $row->user_id;
			if (isset($crew_by_id[$uid]))
			{
				$crew_by_id[$uid]['inductions'][$row->venue_name] = array(
					'status'    => 'Complete',
					'completed' => $row->complete_date
					                ? date('d M Y', (int) $row->complete_date)
					                : '',
				);
			}
		}
	}

	/*
	/* 4. reliability tallies — Late (call_crew_map.late = '1') and No-show
	/*    (call_crew_map.status = 8), counted all-time and for the last 12 months.
	/*
	/* LEFT JOIN calls so a row whose call was later deleted still counts toward
	/* the all-time totals (it just can't land inside the 12-month window, which
	/* needs the call date). GROUP BY userID — one cheap aggregate, same pattern
	/* as get-calls-bulk.php (a correlated subquery there timed out; don't).
	/*
	/* first_seen_ts (earliest call date) drives the "12+ months of history"
	/* split. It's a proxy for tenure (first booking, not employment start) but
	/* needs no change to the roster query and can't break the endpoint.
	/*
	/* Guarded on !== false: if this query errors, crew come back with all-zero
	/* stats rather than a 500 — so if the card shows all zeros, THIS query
	/* failed (check the column names on the test box first).
	*/

	$sql_stats = "
		SELECT ccm.userID AS user_id,
		       SUM(CASE WHEN ccm.status = 8   THEN 1 ELSE 0 END) AS noshow_all,
		       SUM(CASE WHEN ccm.late   = '1' THEN 1 ELSE 0 END) AS late_all,
		       SUM(CASE WHEN ccm.status = 8   AND c.start_date >= $twelve_mo_ago THEN 1 ELSE 0 END) AS noshow_12mo,
		       SUM(CASE WHEN ccm.late   = '1' AND c.start_date >= $twelve_mo_ago THEN 1 ELSE 0 END) AS late_12mo,
		       MIN(c.start_date) AS first_seen_ts
		FROM call_crew_map ccm
		LEFT JOIN calls c ON c.id = ccm.callID
		WHERE ccm.userID IN ($crew_ids_csv)
		GROUP BY ccm.userID
	";

	$stats_result = mysql_query($sql_stats);
	if ($stats_result !== false)
	{
		while ($row = mysql_fetch_object($stats_result))
		{
			$uid = (int) $row->user_id;
			if (isset($crew_by_id[$uid]))
			{
				$first_seen = ($row->first_seen_ts !== null) ? (int) $row->first_seen_ts : 0;

				$crew_by_id[$uid]['stats']['noshow_all']  = (int) $row->noshow_all;
				$crew_by_id[$uid]['stats']['late_all']    = (int) $row->late_all;
				$crew_by_id[$uid]['stats']['noshow_12mo'] = (int) $row->noshow_12mo;
				$crew_by_id[$uid]['stats']['late_12mo']   = (int) $row->late_12mo;
				$crew_by_id[$uid]['stats']['over_12mo']   =
					($first_seen > 0 && $first_seen < $twelve_mo_ago);
			}
		}
	}

	/*
	/* 5. licences — the canonical, non-induction, empty-venue rows.
	/*
	/* Feeds the Crew Finder licence filter. The code is what the filter matches
	/* on and the expiry is what dates it against the CALL, so nothing else from
	/* the row is needed here; the Manage Crew tab still reads full rows through
	/* admin-list-licenses.php.
	/*
	/* type_canonical IS NOT NULL drops the untriaged backlog. A row whose free
	/* text has not been confirmed against the stored card cannot be matched
	/* safely, and guessing at it inside a compliance filter is the unsafe
	/* direction.
	/*
	/* Induction rows can never appear: the empty-venue discriminator is the same
	/* one admin-list-licenses.php uses, and the explicit type check is kept as
	/* harmless extra cover (handover rule #2, enforced at every licence read/
	/* write boundary). The venue = '' arm is deliberate — the column is not
	/* strictly typed and the sibling endpoint already carries all three.
	/*
	/* Guarded on !== false like sections 2-4, so a failed query still serves the
	/* roster. licences_ok is what lets the consumer tell "this crew member holds
	/* no licences" from "the licence query did not run": without it an empty
	/* array reads as the former and the filter silently returns nobody, which is
	/* the one failure a compliance filter must not have.
	*/

	$licences_ok = false;

	$sql_licences = "
		SELECT l.`user` AS user_id,
		       l.type_canonical AS code,
		       l.date_expiry AS date_expiry
		FROM user_licenses l
		WHERE l.`user` IN ($crew_ids_csv)
		  AND l.type_canonical IS NOT NULL
		  AND (l.venue IS NULL OR l.venue = 0 OR l.venue = '')
		  AND l.`type` != 'Induction Certificate'
	";

	$lic_result = mysql_query($sql_licences);

	if ($lic_result !== false)
	{
		$licences_ok = true;

		while ($row = mysql_fetch_object($lic_result))
		{
			$uid = (int) $row->user_id;

			if (!isset($crew_by_id[$uid]))
				continue;

			/*
			/* SQL NULL and the junk value 0000-00-00 both map to JSON null — the
			/* same normalisation admin-list-licenses.php applies to this column,
			/* so the app never sees a zero-date masquerading as a real one.
			*/

			$expiry = $row->date_expiry;
			if ($expiry === null || $expiry === '0000-00-00')
				$expiry = null;

			$crew_by_id[$uid]['licences'][] = array(
				'code'   => $row->code,
				'expiry' => $expiry
			);
		}
	}

	/*
	/* 6. emit
	*/

	echo json_encode(array(
		'crew'              => array_values($crew_by_id),
		'induction_policy'  => (object) $induction_policy,
		'warn_days_default' => (int) $WARN_DAYS_DEFAULT,
		'licences_ok'       => $licences_ok,
	));

?>
