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
		echo json_encode(array('crew' => array()));
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
	/* 5. emit
	*/

	echo json_encode(array(
		'crew' => array_values($crew_by_id),
	));

?>
