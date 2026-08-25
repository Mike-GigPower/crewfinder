<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* THE GOAT / Ops landing — induction exceptions, TWO lanes from ONE endpoint.
	/*
	/*   at_risk  (lane A): crew BOOKED (call_crew_map status 5/7) on upcoming calls
	/*                      at induction venues who will NOT be compliant ON THE DAY
	/*                      — never inducted, or their induction expires on/before
	/*                      the call. Anchored to the CALL.
	/*   expiring (lane B): the whole ACTIVE roster whose induction expires within
	/*                      warn_days of NOW. Anchored to the EXPIRY. Excludes crew
	/*                      with no induction row — that is lane A's business only.
	/*
	/* ONE endpoint because the status arithmetic (expiry = complete_date +
	/* round(validity_months/12*365) days) must exist ONCE. Two endpoints would be
	/* two copies of it in PHP — the drift this design exists to prevent.
	/*
	/* Validity is resolved by VENUE_ID through venue_induction_covers ->
	/* venue_induction_catalogue, copied from my-induction-venues.php. A call knows
	/* its venue_id, so this path never needs a catalogue CODE and sidesteps the
	/* Phase 3 code-mapping blocker entirely. Melbourne Park's five arenas each get
	/* their own crew_venue_induction row at completion time (add-my-induction.php
	/* fans the write across every submitted venue_id), so the plain venue_id join
	/* below covers all five with no read-time expansion.
	/*
	/* PHP 5.x — mysql_* only, no ?? operator, no short [] arrays, no closures.
	*/

	/* soonest-expiry-first comparator for lane B. Named function: no closures on
	/* PHP 5.x. Sorts on the exact expiry ts (finer than the day-truncated
	/* days_left, so two rows expiring the same day still order correctly). */
	function goat_expiry_cmp($a, $b)
	{
		return $a['_expiry'] - $b['_expiry'];
	}

	/* orders a crew member's expiring groups soonest-expiry first, so the venues
	/* list on the card reads most-pressing venue first. */
	function goat_group_expiry_cmp($a, $b)
	{
		return $a['expiry'] - $b['expiry'];
	}

	/* venue segments: biggest first, then label A-Z. The label tie-break is not
	/* cosmetic — without it two venues on the same count swap places between
	/* refreshes and the donut visibly reshuffles for no reason. */
	function goat_venue_seg_cmp($a, $b)
	{
		$d = $b['value'] - $a['value'];
		if ($d !== 0)
			return $d;
		return strcasecmp($a['label'], $b['label']);
	}

	/*
	/* AUTH — the dual gate, copied verbatim from get-pending-acks-bulk.php.
	*/

	$goat_key = isset($_SERVER['HTTP_X_GOAT_SERVICE_KEY'])
	          ? $_SERVER['HTTP_X_GOAT_SERVICE_KEY'] : '';

	if (!goat_service_key_ok($goat_key) && !goat_can_read_all())
	{
		goat_json_error(403, 'Service key or Admin/Leadership session required');
	}

	/*
	/* validate input — window handling identical to get-pending-acks-bulk.php.
	/* start defaults to today (Melbourne), end to start + 28 days, INCLUSIVE of
	/* the whole end day (+86400). The window bounds lane A (the CALL date); lane B
	/* is roster-wide and ignores it.
	*/

	$start_raw = isset($_GET['start']) ? $_GET['start'] : '';
	$end_raw   = isset($_GET['end'])   ? $_GET['end']   : '';

	if ($start_raw === '')
		$start_raw = date('Y-m-d');

	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_raw))
		goat_json_error(400, 'start must be YYYY-MM-DD');

	$start_ts = strtotime($start_raw . ' 00:00:00');

	if ($start_ts === false)
		goat_json_error(400, 'invalid start date');

	if ($end_raw === '')
		$end_raw = date('Y-m-d', $start_ts + (28 * 86400));

	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_raw))
		goat_json_error(400, 'end must be YYYY-MM-DD');

	$end_ts = strtotime($end_raw . ' 00:00:00');

	if ($end_ts === false || $end_ts < $start_ts)
		goat_json_error(400, 'invalid date range');

	/* cap the window at 120 days to protect the DB */

	if (($end_ts - $start_ts) > (120 * 86400))
		goat_json_error(400, 'window exceeds 120 days');

	$start_i = (int) $start_ts;
	$end_i   = (int) $end_ts + 86400;        /* inclusive of the whole end day */

	/* One instant for the whole response, computed once so every expiry is judged
	/* against the same now. global.php has set the Melbourne tz, so time() and the
	/* start_date/complete_date frame agree without offset arithmetic. */
	$now_unix = time();

	/*
	/* ─── LANE A — bookings at risk ──────────────────────────────────────────
	/*
	/* Every status 5/7 crew booking on a windowed call at a has_induction venue.
	/* Coverage is resolved through the induction GROUP, not the exact venue: an
	/* induction is against the induction (the catalogue), not the arena — which is
	/* exactly what venue_induction_covers exists to express. Prod holds ~1,510
	/* crew with partial-group coverage (inducted at Rod Laver but not Margaret
	/* Court, say), so an exact-venue match would report "No induction" for most of
	/* Melbourne Park and tell ops to pull compliant crew off calls.
	/*
	/* Done set-based in two queries — house style, no correlated subquery (see the
	/* timeout note in get-calls-bulk.php):
	/*   A1: the bookings, each carrying its venue's catalogue_id + validity.
	/*   A2: every completion for those crew, tagged with its catalogue_id.
	/* then resolve each booking to the MOST RECENT completion in the same group.
	/* Most-recent matters: a stale Rod Laver record must not expire someone who
	/* re-inducted at Margaret Court last month. A venue with NO catalogue row has
	/* no group, so it falls back to exact venue_id matching + the 12mo/14d default.
	/*
	/* has_induction = 1 gates to venues that REQUIRE induction; v.active is NOT
	/* required — a call already booked at a since-deactivated induction venue still
	/* needs someone compliant on the day. (Lane B, roster-wide, does require active.)
	*/

	$sql_a = "
		SELECT
			ccm.userID   AS user_id,
			u.ein        AS ein,
			u.firstname  AS crew_fn,
			u.lastname   AS crew_ln,
			c.id         AS call_id,
			c.bookingID  AS booking_id,
			c.call_name  AS call_name,
			c.start_date AS start_date,
			c.start_time AS start_time,
			b.name       AS booking_name,
			v.id         AS venue_id,
			v.venue      AS venue_name,
			cov.catalogue_id    AS catalogue_id,
			cat.validity_months AS validity_months,
			cat.warn_days       AS warn_days
		FROM calls c
		INNER JOIN call_crew_map ccm ON ccm.callID = c.id AND ccm.status IN (5,7)
		INNER JOIN users         u   ON u.id       = ccm.userID
		LEFT  JOIN bookings      b   ON b.id       = c.bookingID
		INNER JOIN venues        v   ON v.id       = b.venueID AND v.has_induction = 1
		LEFT  JOIN venue_induction_covers    cov ON cov.venue_id = v.id
		LEFT  JOIN venue_induction_catalogue cat ON cat.id       = cov.catalogue_id
		WHERE c.start_date >= $start_i
		  AND c.start_date <  $end_i
		  AND (b.hidden IS NULL OR b.hidden = 0)
		  AND b.status <> 1
		ORDER BY c.start_date ASC, c.start_time ASC
	";

	$res_a = mysql_query($sql_a);

	if ($res_a === false)
		goat_json_error(500, 'at-risk query failed: ' . mysql_error());

	/* Buffer the bookings and collect the crew set for the completion lookup. */
	$book_a   = array();
	$crew_set = array();
	while ($row = mysql_fetch_object($res_a))
	{
		$book_a[] = $row;
		$crew_set[(int) $row->user_id] = true;
	}

	/* A2: every completion for those crew, tagged with its catalogue_id (NULL for
	/* an ungrouped venue). Two lookups — most-recent completion per crew per
	/* catalogue GROUP, and per crew per exact VENUE for the ungrouped fallback. */
	$best_by_cat = array();   /* "crew:catID"   -> max complete_date */
	$best_by_ven = array();   /* "crew:venueID" -> max complete_date */

	if (count($crew_set))
	{
		$crew_csv = implode(',', array_keys($crew_set));
		$sql_a2 = "
			SELECT i.crew_id       AS crew_id,
			       i.venue_id      AS venue_id,
			       i.complete_date AS complete_date,
			       cov.catalogue_id AS catalogue_id
			FROM crew_venue_induction i
			LEFT JOIN venue_induction_covers cov ON cov.venue_id = i.venue_id
			WHERE i.crew_id IN ($crew_csv) AND i.complete_date > 0
		";
		$res_a2 = mysql_query($sql_a2);
		if ($res_a2 === false)
			goat_json_error(500, 'at-risk induction lookup failed: ' . mysql_error());

		while ($ir = mysql_fetch_object($res_a2))
		{
			$icrew = (int) $ir->crew_id;
			$iven  = (int) $ir->venue_id;
			$icd   = (int) $ir->complete_date;

			$vk = $icrew . ':' . $iven;
			if (!isset($best_by_ven[$vk]) || $icd > $best_by_ven[$vk])
				$best_by_ven[$vk] = $icd;

			if ($ir->catalogue_id !== null)
			{
				$ck = $icrew . ':' . (int) $ir->catalogue_id;
				if (!isset($best_by_cat[$ck]) || $icd > $best_by_cat[$ck])
					$best_by_cat[$ck] = $icd;
			}
		}
	}

	$rows_a  = array();
	$ca_risk = array('none' => 0, 'expired_by' => 0, 'expiring_by' => 0);
	$ca_lead = array('under48' => 0, 'from48to168' => 0, 'over168' => 0);

	foreach ($book_a as $row)
	{
		$crew    = (int) $row->user_id;
		$venueId = (int) $row->venue_id;
		$catId   = ($row->catalogue_id !== null) ? (int) $row->catalogue_id : null;

		/* Resolve the completion through the group where the venue has one, else
		/* the exact venue. Most-recent wins in both maps; null = never inducted. */
		if ($catId !== null)
		{
			$ck = $crew . ':' . $catId;
			$cd = isset($best_by_cat[$ck]) ? $best_by_cat[$ck] : null;
		}
		else
		{
			$vk = $crew . ':' . $venueId;
			$cd = isset($best_by_ven[$vk]) ? $best_by_ven[$vk] : null;
		}

		/* call start, built exactly as the other bulk reads build it */
		$date_i  = (int) $row->start_date;
		$time_hm = substr($row->start_time, 0, 5);
		if (!preg_match('/^\d{2}:\d{2}$/', $time_hm)) $time_hm = '00:00';
		list($hh, $mm) = array_map('intval', explode(':', $time_hm));
		$call_start = $date_i + ($hh * 3600) + ($mm * 60);

		$warn = ($row->warn_days !== null) ? (int) $row->warn_days : 14;

		/* Risk is anchored to the CALL, not to now — deliberately STRONGER than
		/* induction_status_for_venue (which only asks "valid right now?"). A row is
		/* emitted ONLY when at risk. */
		if ($cd === null || $cd === 0)
		{
			$risk       = 'none';
			$expiry_iso = null;
		}
		else
		{
			$validity = ($row->validity_months !== null) ? (int) $row->validity_months : 12;
			$days     = (int) round($validity / 12.0 * 365);
			$expiry   = (int) $cd + ($days * 86400);

			if ($expiry <= $call_start)
				$risk = 'expired_by';                       /* lapsed BY the call */
			else if ($expiry <= $call_start + ($warn * 86400))
				$risk = 'expiring_by';                      /* lapses within warn before it */
			else
				continue;                                   /* compliant on the day — not a row */

			$expiry_iso = date('Y-m-d\TH:i:s', $expiry);
		}

		/* lead bucket: now -> call start, the SAME 172800/604800 boundaries lanes
		/* 1 and 3 use, from the single $now_unix. */
		$lead_secs = $call_start - $now_unix;
		if ($lead_secs < 172800)          $lead_bucket = 'under48';
		else if ($lead_secs < 604800)     $lead_bucket = 'from48to168';
		else                              $lead_bucket = 'over168';

		$ca_risk[$risk]++;
		$ca_lead[$lead_bucket]++;

		$crew_name = trim($row->crew_ln);
		if (strlen(trim($row->crew_fn)) > 0)
		{
			if (strlen($crew_name) > 0) $crew_name .= ', ';
			$crew_name .= trim($row->crew_fn);
		}

		$rows_a[] = array(
			'user_id'      => $crew,
			'ein'          => ($row->ein === null ? null : (string) $row->ein),
			'name'         => $crew_name,
			'call_id'      => (int) $row->call_id,
			'booking_id'   => (int) $row->booking_id,
			'call_name'    => $row->call_name,
			'booking_name' => $row->booking_name,
			'venue'        => $row->venue_name,
			'start'        => date('Y-m-d\TH:i:s', $call_start),
			'date_iso'     => date('Y-m-d',        $date_i),
			'time'         => $time_hm,
			'risk'         => $risk,          /* none | expired_by | expiring_by */
			'expiry_iso'   => $expiry_iso,    /* null when none */
			'lead_bucket'  => $lead_bucket,
		);
	}

	/*
	/* ─── LANE B — inductions expiring SOON (roster-wide, ONE ROW PER CREW) ───
	/*
	/* Active crew at active induction venues whose induction expires SOON:
	/*   expiry > now                       -- NOT already expired
	/*   AND expiry <= now + warn * 86400   -- inside the warning window
	/*
	/* Already-expired is excluded BY DESIGN. Prod's gate returned 965 rows, 858
	/* already expired (376 of them over a year old): long-lapsed inductions for
	/* crew who stopped working a venue are a data-cleanup project, not an action a
	/* landing page can carry. That backlog stays visible in the Induction Checker.
	/*
	/* Emitted ONE ROW PER CREW MEMBER. Two collapses stack:
	/*   1. per crew per induction GROUP (catalogue where the venue has one, else
	/*      the venue itself), most-recent completion winning — same rule as lane A;
	/*   2. then per CREW, carrying every expiring group of theirs.
	/* The lane's unit is the unit of work: Rich has ONE conversation with a crew
	/* member, not one per venue. Everything — sort, days_left, lead_bucket — is
	/* derived from that crew member's SOONEST expiry (Marvel in 2 days outranks MCG
	/* in 11); `venues` is ordered soonest-first so the most pressing reads first.
	/* venue_label is the catalogue's title where grouped (the honest "Melbourne
	/* Park"), else the venue name.
	*/

	$sql_b = "
		SELECT
			i.crew_id    AS user_id,
			u.ein        AS ein,
			u.firstname  AS crew_fn,
			u.lastname   AS crew_ln,
			v.id         AS venue_id,
			v.venue      AS venue_name,
			i.complete_date     AS complete_date,
			cov.catalogue_id    AS catalogue_id,
			cat.title           AS cat_title,
			cat.validity_months AS validity_months,
			cat.warn_days       AS warn_days
		FROM crew_venue_induction i
		INNER JOIN users  u ON u.id = i.crew_id  AND u.active = 1
		INNER JOIN venues v ON v.id = i.venue_id AND v.active = 1 AND v.has_induction = 1
		LEFT  JOIN venue_induction_covers    cov ON cov.venue_id = v.id
		LEFT  JOIN venue_induction_catalogue cat ON cat.id       = cov.catalogue_id
		WHERE i.complete_date > 0
	";

	$res_b = mysql_query($sql_b);

	if ($res_b === false)
		goat_json_error(500, 'expiring query failed: ' . mysql_error());

	/* Collapse to one entry per crew per group, keeping the most-recent completion
	/* and that group's label/validity. Group key: "crew:cN" for a catalogue group,
	/* "crew:vN" for an ungrouped venue. */
	$grp = array();
	$grp_lb = array();
	while ($row = mysql_fetch_object($res_b))
	{
		$crew  = (int) $row->user_id;
		$cd    = (int) $row->complete_date;
		$catId = ($row->catalogue_id !== null) ? (int) $row->catalogue_id : null;

		if ($catId !== null)
		{
			$gk    = $crew . ':c' . $catId;
			$vkey  = 'c' . $catId;
			$label = ($row->cat_title !== null && trim($row->cat_title) !== '') ? $row->cat_title : $row->venue_name;
		}
		else
		{
			$gk    = $crew . ':v' . ((int) $row->venue_id);
			$vkey  = 'v' . ((int) $row->venue_id);
			$label = $row->venue_name;
		}

		if (!isset($grp[$gk]) || $cd > $grp[$gk]['complete_date'])
		{
			$crew_name = trim($row->crew_ln);
			if (strlen(trim($row->crew_fn)) > 0)
			{
				if (strlen($crew_name) > 0) $crew_name .= ', ';
				$crew_name .= trim($row->crew_fn);
			}

			$grp[$gk] = array(
				'user_id'       => $crew,
				'ein'           => ($row->ein === null ? null : (string) $row->ein),
				'name'          => $crew_name,
				'venue_label'   => $label,
				'venue_key'     => $vkey,
				'complete_date' => $cd,
				'validity'      => ($row->validity_months !== null) ? (int) $row->validity_months : 12,
				'warn'          => ($row->warn_days !== null) ? (int) $row->warn_days : 14,
			);
		}

		/* Label is chosen independently of the complete_date winner: always the
		/* alphabetically first label seen for this $gk. Without this the label
		/* rides on whichever row happened to win on date, which for a 'c' key
		/* spanning several venues is arbitrary and flips with row order. */
		if (!isset($grp_lb[$gk]) || strcasecmp($label, $grp_lb[$gk]) < 0)
			$grp_lb[$gk] = $label;
	}

	foreach ($grp_lb as $lb_k => $lb_v)
		$grp[$lb_k]['venue_label'] = $lb_v;

	/* Second collapse: gather each crew member's expiring groups. Filter to
	/* expiring-soon (strictly future, inside the warn window) as we go. */
	$per_crew = array();
	foreach ($grp as $g)
	{
		$days   = (int) round($g['validity'] / 12.0 * 365);
		$expiry = $g['complete_date'] + ($days * 86400);

		if ($expiry <= $now_unix || $expiry > $now_unix + ($g['warn'] * 86400))
			continue;

		$uid = $g['user_id'];
		if (!isset($per_crew[$uid]))
		{
			$per_crew[$uid] = array(
				'user_id' => $uid,
				'ein'     => $g['ein'],
				'name'    => $g['name'],
				'groups'  => array(),
			);
		}
		$per_crew[$uid]['groups'][] = array('label' => $g['venue_label'], 'key' => $g['venue_key'], 'expiry' => $expiry);
	}

	/* One row per crew member. Everything derives from the SOONEST expiry: sort,
	/* days_left and lead_bucket. venues reads soonest-first. total is CREW, so
	/* sum(lead) == total (each crew member falls in exactly one bucket). */
	$rows_b  = array();
	$cb_lead = array('under48' => 0, 'from48to168' => 0, 'over168' => 0);
	$cb_venue_ct = array();
	$cb_venue_lb = array();

	foreach ($per_crew as $pc)
	{
		usort($pc['groups'], 'goat_group_expiry_cmp');     /* soonest expiry first */

		$soonest    = $pc['groups'][0]['expiry'];
		$venues     = array();
		$venue_keys = array();
		$ng         = count($pc['groups']);
		/* ONE pass builds both arrays, so venues and venue_keys are parallel by
		/* construction rather than by care. The tally rides along. */
		for ($gi = 0; $gi < $ng; $gi++)
		{
			$g_label = $pc['groups'][$gi]['label'];
			$g_key   = $pc['groups'][$gi]['key'];

			$venues[]     = $g_label;
			$venue_keys[] = $g_key;

			if (!isset($cb_venue_ct[$g_key]))
				$cb_venue_ct[$g_key] = 0;
			$cb_venue_ct[$g_key]++;

			/* NOT last-write-wins. A 'c' key spans several venues, and when cat_title
			/* is empty the label falls back to that venue's own name — so one key can
			/* legitimately arrive carrying different labels. Keep the alphabetically
			/* first one so the segment label does not depend on row order. */
			if (!isset($cb_venue_lb[$g_key]) || strcasecmp($g_label, $cb_venue_lb[$g_key]) < 0)
				$cb_venue_lb[$g_key] = $g_label;
		}

		$days_left = (int) (($soonest - $now_unix) / 86400);   /* >= 0 here */

		/* lead bucket from the SOONEST expiry: the SAME 172800/604800 boundaries
		/* every other lane uses (there is no "expired" state left to chart). */
		$lead_secs = $soonest - $now_unix;
		if ($lead_secs < 172800)          $lead_bucket = 'under48';
		else if ($lead_secs < 604800)     $lead_bucket = 'from48to168';
		else                              $lead_bucket = 'over168';

		$cb_lead[$lead_bucket]++;

		$rows_b[] = array(
			'user_id'            => $pc['user_id'],
			'ein'                => $pc['ein'],
			'name'               => $pc['name'],
			'venues'             => $venues,          /* soonest-expiry first */
			'venue_keys'         => $venue_keys,      /* parallel to venues */
			'soonest_expiry_iso' => date('Y-m-d\TH:i:s', $soonest),
			'days_left'          => $days_left,
			'lead_bucket'        => $lead_bucket,
			'venue_count'        => $ng,
			'_expiry'            => $soonest,   /* internal sort key, stripped below */
		);
	}

	/* Venue spread — crew x venue PAIRS, so this deliberately does NOT sum to
	/* count($rows_b). One crew member with two expiring groups is two pairs.
	/* sum(venue) == venue_pairs == sum(rows[].venue_count). */
	$venue_all = array();
	foreach ($cb_venue_ct as $vk => $vn)
	{
		$venue_all[] = array(
			'key'   => $vk,
			'label' => $cb_venue_lb[$vk],
			'value' => $vn,
		);
	}
	usort($venue_all, 'goat_venue_seg_cmp');

	/* Top 5 plus a rolled-up remainder — but only when the tail holds 2 or more.
	/* A rolled-up "All other (1 venue)" is worse than simply drawing six.
	/* Every segment carries a keys array, singles included, so the client has ONE
	/* filter rule (does this row's venue_keys intersect this segment's keys) with
	/* no special case for __other__. */
	$VENUE_TOP  = 5;
	$venue_segs = array();
	$venue_pairs = 0;
	$n_v = count($venue_all);
	if ($n_v > $VENUE_TOP + 1)
	{
		for ($vi = 0; $vi < $VENUE_TOP; $vi++)
		{
			$venue_segs[] = array(
				'key'   => $venue_all[$vi]['key'],
				'label' => $venue_all[$vi]['label'],
				'value' => $venue_all[$vi]['value'],
				'keys'  => array($venue_all[$vi]['key']),
			);
			$venue_pairs += $venue_all[$vi]['value'];
		}
		$ot_val  = 0;
		$ot_keys = array();
		for ($vi = $VENUE_TOP; $vi < $n_v; $vi++)
		{
			$ot_val   += $venue_all[$vi]['value'];
			$ot_keys[] = $venue_all[$vi]['key'];
		}
		$venue_pairs += $ot_val;
		$venue_segs[] = array(
			'key'   => '__other__',
			'label' => 'All other (' . count($ot_keys) . ' venues)',
			'value' => $ot_val,
			'keys'  => $ot_keys,
		);
	}
	else
	{
		for ($vi = 0; $vi < $n_v; $vi++)
		{
			$venue_segs[] = array(
				'key'   => $venue_all[$vi]['key'],
				'label' => $venue_all[$vi]['label'],
				'value' => $venue_all[$vi]['value'],
				'keys'  => array($venue_all[$vi]['key']),
			);
			$venue_pairs += $venue_all[$vi]['value'];
		}
	}

	/* soonest expiry first */
	usort($rows_b, 'goat_expiry_cmp');

	/* strip the internal sort key before emit */
	$n_b = count($rows_b);
	for ($bi = 0; $bi < $n_b; $bi++)
		unset($rows_b[$bi]['_expiry']);

	/* Both blocks' identities hold by construction: every emitted row increments
	/* exactly one tag in each group, and total = count(rows).
	/*
	/* expiring.counts.venue is the ONE exception, and it is not a bug: it counts
	/* crew x venue PAIRS, not crew, so sum(counts.venue[].value) deliberately does
	/* NOT equal counts.total. It equals counts.venue_pairs, which in turn equals
	/* sum(rows[].venue_count). A crew member expiring at two venues is two pairs
	/* and one row. Do not "fix" it to match total. */
	echo json_encode(array(
		'generated_at' => date('Y-m-d\TH:i:s'),
		'window'       => array('start' => $start_raw, 'end' => $end_raw),
		'at_risk'      => array(
			'counts' => array(
				'total' => count($rows_a),
				'risk'  => $ca_risk,
				'lead'  => $ca_lead,
			),
			'rows'   => $rows_a,
		),
		'expiring'     => array(
			'counts' => array(
				'total'       => $n_b,
				'lead'        => $cb_lead,
				'venue_pairs' => $venue_pairs,
				/* Belt and braces: $venue_segs is built by append and is already
				/* sequential, so this is a no-op today. Kept because a future edit
				/* that unsets a segment would otherwise encode as a JSON object and
				/* silently cost the client the ranking. */
				'venue'       => array_values($venue_segs),
			),
			'rows'   => array_values($rows_b),
		),
	));

?>
