<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — returns one crew member's editable fields to pre-fill
	/* the GOAT admin edit form. Admin-gated. Never returns salt/password.
	/*
	/* Also carries, for the GOAT crew-details header banner:
	/*   - is_visa_worker  (users flag) + visa_expiry (user_visa, YYYY-MM-DD or '')
	/*   - stats { late_all, noshow_all, late_12mo, noshow_12mo, over_12mo }
	/*     computed IDENTICALLY to list-crew-bulk.php so the header matches the
	/*     CrewFinder rollover card exactly.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		http_response_code(403);
		die('{"error":"Admin only"}');
	}

	$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

	if ($id <= 0)
	{
		http_response_code(400);
		die('{"error":"id required"}');
	}

	/* Main record. LEFT JOIN user_visa (keyed on its `user` column) so a crew
	/* member with no visa row still returns, with visa_expiry NULL. */
	$sql = "SELECT u.id, u.firstname, u.lastname, u.ein, u.mobile, u.phone, u.dob,
	               u.address, u.suburb, u.state, u.postcode, u.email,
	               u.emergency_contact, u.emergency_phone, u.active, u.rating,
	               u.notes, u.is_visa_worker, v.visa_expiry
	        FROM users u
	        LEFT JOIN user_visa v ON v.`user` = u.id
	        WHERE u.id = " . $id . "
	        LIMIT 1";

	$res = mysql_query($sql);

	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"query failed: ' . addslashes(mysql_error()) . '"}');
	}

	if (mysql_num_rows($res) == 0)
	{
		http_response_code(404);
		die('{"error":"crew member not found"}');
	}

	$u = mysql_fetch_object($res);

	/* Names are stored HTML-entity-encoded; decode for display in the form. */
	$dobOut = '';
	if ($u->dob !== '' && (int) $u->dob != 0)
	{
		$dt = new DateTime('@' . (int) $u->dob);   /* @ts is UTC */
		$dt->setTimezone(new DateTimeZone('Australia/Melbourne'));
		$dobOut = $dt->format('Y-m-d');
	}

	/* visa_expiry is stored as a plain YYYY-MM-DD string (or NULL / 0000-00-00).
	/* Pass it straight through; the client formats + colour-codes it. */
	$visaExpiryOut = '';
	if ($u->visa_expiry !== null && $u->visa_expiry !== '' && $u->visa_expiry !== '0000-00-00')
	{
		$visaExpiryOut = $u->visa_expiry;
	}

	/* Reliability tallies — Late (call_crew_map.late = '1') and No-show
	/* (call_crew_map.status = 8), all-time and last-12-months. This mirrors the
	/* section-4 query in list-crew-bulk.php EXACTLY (same status/late semantics,
	/* same 12-month cutoff, same over_12mo derivation) so the header agrees with
	/* the CrewFinder rollover. Single user, so a plain WHERE — no GROUP BY. */
	$twelve_mo_ago = strtotime('-1 year');   /* leap-safe unix ts */

	$stats = array(
		'late_all'    => 0,
		'noshow_all'  => 0,
		'late_12mo'   => 0,
		'noshow_12mo' => 0,
		'over_12mo'   => false
	);

	$sql_stats = "
		SELECT SUM(CASE WHEN ccm.status = 8   THEN 1 ELSE 0 END) AS noshow_all,
		       SUM(CASE WHEN ccm.late   = '1' THEN 1 ELSE 0 END) AS late_all,
		       SUM(CASE WHEN ccm.status = 8   AND c.start_date >= " . $twelve_mo_ago . " THEN 1 ELSE 0 END) AS noshow_12mo,
		       SUM(CASE WHEN ccm.late   = '1' AND c.start_date >= " . $twelve_mo_ago . " THEN 1 ELSE 0 END) AS late_12mo,
		       MIN(c.start_date) AS first_seen_ts
		FROM call_crew_map ccm
		LEFT JOIN calls c ON c.id = ccm.callID
		WHERE ccm.userID = " . $id;

	/* Guarded on !== false: if this query errors, the header shows all-zero
	/* stats rather than a 500. If the card shows all zeros, THIS query failed
	/* (check the column names on the test box first). */
	$sres = mysql_query($sql_stats);
	if ($sres !== false)
	{
		$srow = mysql_fetch_object($sres);   /* aggregate w/o GROUP BY: always one row */
		if ($srow)
		{
			$first_seen = ($srow->first_seen_ts !== null) ? (int) $srow->first_seen_ts : 0;

			$stats['noshow_all']  = (int) $srow->noshow_all;
			$stats['late_all']    = (int) $srow->late_all;
			$stats['noshow_12mo'] = (int) $srow->noshow_12mo;
			$stats['late_12mo']   = (int) $srow->late_12mo;
			$stats['over_12mo']   = ($first_seen > 0 && $first_seen < $twelve_mo_ago);
		}
	}

	/* group memberships + master list for the edit-form checkboxes */
	$allGroups = array();
	$gres = mysql_query("SELECT id, group_name FROM crew_groups ORDER BY group_name ASC");
	if ($gres !== false)
	{
		while ($grow = mysql_fetch_object($gres))
			$allGroups[] = array('id' => (int) $grow->id, 'name' => $grow->group_name);
	}
	$groupIds = array();
	$mres = mysql_query("SELECT groupID FROM crew_groups_map WHERE userID = " . $id);
	if ($mres !== false)
	{
		while ($mrow = mysql_fetch_object($mres))
			$groupIds[] = (int) $mrow->groupID;
	}

	echo json_encode(array(
		'id'                => (int) $u->id,
		'firstname'         => html_entity_decode($u->firstname, ENT_QUOTES),
		'lastname'          => html_entity_decode($u->lastname, ENT_QUOTES),
		'ein'               => $u->ein,
		'mobile'            => $u->mobile,
		'phone'             => $u->phone,
		'dob'               => $dobOut,
		'address'           => $u->address,
		'suburb'            => $u->suburb,
		'state'             => $u->state,
		'postcode'          => $u->postcode,
		'email'             => $u->email,
		'emergency_contact' => $u->emergency_contact,
		'emergency_phone'   => $u->emergency_phone,
		'active'            => $u->active,
		'rating'            => (int) $u->rating,
		'notes'             => $u->notes,
		'is_visa_worker'    => (int) $u->is_visa_worker,
		'visa_expiry'       => $visaExpiryOut,
		'stats'             => $stats,
		'all_groups'        => $allGroups,
		'group_ids'         => $groupIds
	));

?>
