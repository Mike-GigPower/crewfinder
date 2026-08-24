<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* READ-ALL endpoint — the GM Performance dashboard's single data source.
	/*
	/* Gated on goat_can_read_all(), NOT on goat_user_cohort() === 'admin': this is
	/* a read endpoint and must admit leadership and operations, mirroring
	/* @require_cohort(*READ_ALL_COHORTS) on /api/performance in app.py.
	/*
	/* WHY ONE ENDPOINT AND NOT THREE
	/*
	/*  app.py cannot issue concurrent /ajax/crew/ requests on one session — the
	/*  per-session PHP file lock makes the second call hang (see fetch_calls_for_ops).
	/*  Three endpoints would therefore have to be fetched SEQUENTIALLY, tripling
	/*  latency for no benefit. Every series the dashboard draws is returned here,
	/*  in one response, computed in one pass, so no two numbers on the screen can
	/*  disagree with each other.
	/*
	/* WHAT IS COUNTED
	/*
	/*  Revenue is INVOICED revenue attributed to the EVENT DATE (invoice_lines.
	/*  start_date), not the billing date. It will not match a monthly Xero figure
	/*  and is not meant to: attributing to the event date puts revenue on the same
	/*  clock as the hours, which is the only way revenue-per-hour means anything.
	/*
	/*  A second, SEPARATE band covers work delivered but not yet invoiced — crew
	/*  rows on calls that carry no invoice_lines row at all. That band's money is
	/*  an ESTIMATE and is returned under `estimated_revenue`, deliberately a
	/*  different key from `revenue`, so a client cannot accidentally add them.
	/*
	/* UNIVERSAL FILTERS (every query)
	/*
	/*  start_date > 0   — the BLANK_LINE spacer rows all sit at epoch 0.
	/*  bookings.hidden = 0
	/*
	/*  bookings.status is deliberately NOT filtered. The status = 0 bookings carry
	/*  no lines, so filtering buys nothing and risks excluding a future value.
	/*
	/* WINDOW
	/*
	/*  ?weeks=N (clamped 1..104). The response carries the requested window AND
	/*  the equal window immediately before it, so the client can draw the
	/*  year-on-year comparison without a second request. weeks=52 therefore
	/*  returns 104 week buckets: the FIRST N are the comparison window, the LAST N
	/*  are the requested window. Every scalar under `totals` and every top-level
	/*  customer figure is scoped to the REQUESTED window only; the per-week series
	/*  span both.
	/*
	/*  Buckets are ISO year-week keys ("2026-W33"), computed in PHP with date()
	/*  rather than in SQL with FROM_UNIXTIME(..., '%x-W%v'). The two agree, but
	/*  they read DIFFERENT clocks — MySQL's session timezone and PHP's — and a
	/*  disagreement would land rows in the wrong bucket only at week boundaries,
	/*  which is exactly the kind of fault nobody spots. Bucketing in PHP puts the
	/*  window boundaries and the bucket keys on one clock. The key is returned
	/*  raw; the client formats it.
	/*
	/* PHP 5.x — mysql_* only, array() syntax, no null-coalescing, no scalar type
	/* hints. mysql_* returns every column as a STRING, so each numeric is cast
	/* explicitly before any arithmetic.
	*/

	if (!goat_can_read_all())
		goat_json_error(403, 'Forbidden');


	/* ──────────────────────────────────────────────────────────────────────────
	/* Tunables
	/* ────────────────────────────────────────────────────────────────────────── */

	/*
	/* Equipment — hire lines that consume no crew hours. Revenue per hour is a
	/* CREW figure, so equipment revenue and equipment hours are split out and
	/* reported separately rather than diluting it.
	/*
	/* THE RATE HALF OF THIS TEST WAS DROPPED ON EVIDENCE (test run, 24 Aug 2026).
	/* The original test required the role AND a chargeout rate of 80. Both
	/* counters said that was wrong, in opposite directions:
	/*
	/*   rate80_non_equipment_lines = 0    — nothing else bills at 80, so the rate
	/*                                       added no discriminating power
	/*   equipment_rate_mismatch    = 52   — rate 80 appears on 1 of 322 Harness
	/*                                       lines; they run 41.5–100, plus 0 and
	/*                                       1544.4
	/*
	/* There is no canonical equipment rate, so requiring one excluded 51 of 52
	/* genuine equipment lines. Classification is now the ROLE ALONE.
	/*
	/* GOAT_PERF_EQUIPMENT_RATE and both counters are KEPT, now purely as the
	/* canary that proved this: they cost one comparison per line and they are
	/* what would catch the reverse case — a rate that starts meaning something,
	/* or a role list that stops covering what bills at 80.
	*/
	$GOAT_PERF_EQUIPMENT_RATE = 80.0;

	/* Number of customers ranked individually; the rest roll up into `other`. */
	$GOAT_PERF_TOP_CUSTOMERS = 8;

	/* call_crew_map.status values that never represent delivered work. */
	$GOAT_PERF_EXCLUDED_CREW_STATUS = array(6, 8);


	/* ──────────────────────────────────────────────────────────────────────────
	/* Helpers
	/* ────────────────────────────────────────────────────────────────────────── */

	/*
	/* ISO year-week key for a unix timestamp — "2026-W33". date('o') is the ISO
	/* year (which is NOT date('Y') in the last/first days of a year) and date('W')
	/* is the zero-padded ISO week. This pairing is the exact equivalent of MySQL's
	/* '%x-W%v'.
	*/
	function goat_perf_week_key($ts)
	{
		return date('o', (int) $ts) . '-W' . date('W', (int) $ts);
	}

	/*
	/* Parse "HH:MM" / "HH:MM:SS" to seconds.
	/*
	/* Returns null for an empty or unparseable value, and null for a MALFORMED
	/* value — minutes or seconds >= 60. Those exist in live data ('00:75',
	/* '01:75', '00:60'); PHP would happily coerce '00:75' to 75 minutes and the
	/* over-subtraction would vanish into an aggregate. The caller counts every
	/* null it gets back from a break column, because a silently coerced payroll
	/* error is an invisible payroll error.
	*/
	function goat_perf_hms_to_seconds($val)
	{
		$val = trim((string) $val);
		if ($val === '')
			return null;

		/* A bare '0' / '00' is how "no break" is stored on some rows. That is a
		   zero, not a malformed value — counting it as malformed would bury the
		   21 real ones in noise. */
		if (preg_match('/^0+$/', $val))
			return 0;

		if (!preg_match('/^(\d{1,3}):(\d{1,2})(?::(\d{1,2}))?$/', $val, $m))
			return null;

		$h = (int) $m[1];
		$i = (int) $m[2];
		$s = isset($m[3]) ? (int) $m[3] : 0;

		if ($i > 59 || $s > 59)
			return null;                       /* malformed — caller counts it */

		return ($h * 3600) + ($i * 60) + $s;
	}

	/*
	/* Split invoice_lines.description into its role and its (Period/Tier).
	/*
	/* Live shape: 'Load In <i style="font-size: 9px;">(Day/T1B)</i>'. The markup
	/* is a rendering convention, so it is stripped here and NEVER shipped to the
	/* client.
	/*
	/* An unrecognised tier is left null and counted — never defaulted. The parser
	/* reads a convention rather than a column, so that count is the canary for the
	/* convention changing underneath us.
	/*
	/* unclassified_tiers IS EXPECTED TO BE NON-ZERO on prod and that is correct.
	/* At least 11 Harness lines carry no <i> block at all — they have no tier to
	/* find, so a null is the honest answer and the counter is doing its job. The
	/* signal to act on is the count MOVING, not the count existing; a jump means
	/* the rendering convention shifted under a grade that used to carry one.
	*/
	function goat_perf_parse_description($desc)
	{
		$plain = trim(html_entity_decode(strip_tags((string) $desc), ENT_QUOTES, 'UTF-8'));

		$period = null;
		$tier   = null;

		if (preg_match('/\(\s*([^\/()]+?)\s*\/\s*([^()]+?)\s*\)\s*$/', $plain, $m))
		{
			$period = $m[1];
			$tier   = $m[2];
			$plain  = trim(substr($plain, 0, strlen($plain) - strlen($m[0])));
		}

		return array('role' => $plain, 'period' => $period, 'tier' => $tier);
	}


	/*
	/* Normalise a role / paygrade name for comparison: tags out, entities decoded,
	/* runs of whitespace collapsed to one space, lowercased, trimmed.
	/*
	/* Both sides of the equipment test go through this SAME function. Live data
	/* carries 'PRG Harness Hire', 'Harness Hire - No Charge' and a double-space
	/* variant, so exact equality on the raw strings misses most of them.
	/*
	/* \s+ deliberately without the /u modifier: on invalid UTF-8, a /u pattern
	/* makes preg_replace return null and the name would silently become ''.
	*/
	function goat_perf_normalise_role($s)
	{
		$s = html_entity_decode(strip_tags((string) $s), ENT_QUOTES, 'UTF-8');
		$s = preg_replace('/\s+/', ' ', $s);
		return trim(strtolower((string) $s));
	}


	/* ──────────────────────────────────────────────────────────────────────────
	/* Window
	/* ────────────────────────────────────────────────────────────────────────── */

	$weeks = 52;

	if (isset($_GET['weeks']))
	{
		$requested = (int) $_GET['weeks'];
		if ($requested >= 1 && $requested <= 104)
			$weeks = $requested;
	}

	$span = $weeks * 2;                        /* requested window + comparison */

	/*
	/* Week boundaries are walked with strtotime() relative strings rather than
	/* 86400-second arithmetic: Melbourne observes DST, so a week is not always
	/* 604800 seconds and fixed-second stepping would drift an hour twice a year —
	/* enough to move a Sunday-night or Monday-morning call into the wrong bucket.
	*/
	$today = strtotime('today');
	$dow   = (int) date('N', $today);                          /* 1 = Mon .. 7 = Sun */

	/* Guarded rather than relying on strtotime('-0 days') on a Monday. */
	$this_week_start = ($dow > 1)
	                 ? strtotime('-' . ($dow - 1) . ' days', $today)
	                 : $today;

	/*
	/* The window ends at the last COMPLETE week, not the week in progress. A
	/* part-week bucket is always short, so a chart that includes it draws a cliff
	/* at the right-hand edge every time it is opened and reads as a collapse
	/* rather than as a Tuesday. It also makes the year-on-year pair compare a
	/* partial week against a whole one.
	/*
	/* This is what BRIEF §A.3's own worked example does: weeks=52 on 24 Aug 2026
	/* (which is 2026-W35) is specified as 2025-W35 -> 2026-W34.
	*/
	$anchor_week_start = strtotime('-1 week', $this_week_start);

	$range_start = strtotime('-' . ($span - 1) . ' weeks', $anchor_week_start);
	$range_end   = strtotime('+1 week', $anchor_week_start) - 1;

	/*
	/* Dense, ordered bucket list. Zero-filled gaps matter: a chart that silently
	/* omits a quiet week draws a straight line across it and reads as continuity
	/* rather than as an empty week.
	*/
	$week_keys  = array();
	$week_index = array();
	$cursor     = $range_start;

	for ($i = 0; $i < $span; $i++)
	{
		$key              = goat_perf_week_key($cursor);
		$week_keys[$i]    = $key;
		$week_index[$key] = $i;
		$cursor           = strtotime('+1 week', $cursor);
	}

	/* Index of the first bucket inside the REQUESTED window. */
	$req_first = $weeks;


	/* ──────────────────────────────────────────────────────────────────────────
	/* Accumulators
	/* ────────────────────────────────────────────────────────────────────────── */

	$wk = array();

	for ($i = 0; $i < $span; $i++)
	{
		$wk[$i] = array(
			'inv_hours'     => 0.0,
			'inv_revenue'   => 0.0,
			'eq_hours'      => 0.0,
			'eq_revenue'    => 0.0,
			'inv_bookings'  => array(),        /* set of bookingID -> distinct count */
			'unv_hours'     => 0.0,
			'unv_revenue'   => 0.0
		);
	}

	$cust = array();                           /* customerID => aggregate */

	$tot_revenue  = 0.0;
	$tot_hours    = 0.0;
	$tot_bookings = array();
	$tot_lines    = 0;

	$dq_unclassified_tiers       = 0;
	$dq_zero_qty_lines           = 0;
	$dq_rate80_non_equip         = 0;
	$dq_equip_rate_mismatch      = 0;
	$dq_unbucketed_lines         = 0;
	$dq_malformed_breaks         = 0;
	$dq_unbucketed_crew          = 0;
	$dq_uninvoiced_rows          = 0;
	$dq_night_flagged_rows       = 0;
	$dq_ambiguous_off_rows       = 0;
	$dq_uninvoiced_non_confirmed = 0;

	$call_invoices = array();                  /* callID => set of invoiceID */
	$call_bookings = array();                  /* callID => set of bookingID */


	/* ──────────────────────────────────────────────────────────────────────────
	/* Query 1 — the equipment paygrade names
	/*
	/* A paygrade with ZERO pay and a non-zero charge-out is equipment by
	/* definition: it bills the customer and pays no crew, so its hours are not
	/* crew hours. Deriving the list from paygrades means a second hire grade
	/* added later is picked up with no code change — which the hardcoded
	/* array('harness hire') it replaces could never do.
	/*
	/* Run ONCE, here, against a 27-row table — never per line.
	/*
	/* Currently returns exactly one row. If it ever returns none, nothing is
	/* classified as equipment and the split silently becomes zero, so the count
	/* is reported as data_quality.equipment_paygrades rather than assumed.
	/* ────────────────────────────────────────────────────────────────────────── */

	$equipment_roles = array();

	$res_pg = mysql_query("SELECT day_desc FROM paygrades WHERE rate = 0");

	if ($res_pg === false)
		goat_json_error(500, 'paygrade query failed: ' . mysql_error());

	while ($row = mysql_fetch_object($res_pg))
	{
		$norm = goat_perf_normalise_role($row->day_desc);

		/*
		/* An empty name MUST be dropped. The test below is a substring test, and
		/* strpos($anything, '') returns 0 — which is !== false — so one blank
		/* day_desc would classify EVERY line in the file as equipment and zero
		/* out crew revenue-per-hour entirely.
		*/
		if ($norm !== '')
			$equipment_roles[$norm] = true;
	}

	$equipment_roles = array_keys($equipment_roles);


	/* ──────────────────────────────────────────────────────────────────────────
	/* Query 2 — invoiced lines over the full span
	/*
	/* Fetched as rows rather than aggregated in SQL because the equipment split
	/* and the tier canary both depend on parsing description, which SQL cannot do.
	/* One pass over ~15k rows produces every invoiced series, the customer
	/* ranking and the totals — so those can never drift apart, which is what a
	/* second GROUP BY would eventually let them do.
	/* ────────────────────────────────────────────────────────────────────────── */

	$sql_lines = "
		SELECT
			il.bookingID       AS booking_id,
			il.callID          AS call_id,
			il.invoiceID       AS invoice_id,
			il.start_date      AS start_date,
			il.description     AS description,
			il.hours           AS hours,
			il.qty             AS qty,
			il.chargeout_rate  AS chargeout_rate,
			b.customerID       AS customer_id,
			cu.customer_name   AS customer_name
		FROM invoice_lines il
		JOIN bookings  b  ON b.id  = il.bookingID
		LEFT JOIN customers cu ON cu.id = b.customerID
		WHERE il.start_date > 0
		  AND il.start_date BETWEEN " . (int) $range_start . " AND " . (int) $range_end . "
		  AND b.hidden = 0
	";

	$res_lines = mysql_query($sql_lines);

	if ($res_lines === false)
		goat_json_error(500, 'invoice line query failed: ' . mysql_error());

	while ($row = mysql_fetch_object($res_lines))
	{
		$ts  = (int) $row->start_date;
		$key = goat_perf_week_key($ts);

		if (!isset($week_index[$key]))
		{
			/* Cannot happen while the SQL range and the PHP buckets share a
			   clock; counted rather than dropped silently so it says so if it
			   ever does. */
			$dq_unbucketed_lines++;
			continue;
		}

		$idx        = $week_index[$key];
		$in_request = ($idx >= $req_first);

		/*
		/* hours is PER CREW MEMBER and qty MULTIPLIES it. Reversing that
		/* over-states by a factor of qty, and one live line carries qty = 60.
		/*
		/* No coercion of a zero or negative qty: the reconciliation figures were
		/* taken with the plain product, so coercing here would make this endpoint
		/* disagree with the query it is checked against. Counted instead.
		*/
		$hours_each = (double) $row->hours;
		$qty        = (double) $row->qty;
		$rate       = (double) $row->chargeout_rate;

		if ($qty <= 0)
			$dq_zero_qty_lines++;

		$line_hours   = $hours_each * $qty;
		$line_revenue = $hours_each * $rate * $qty;

		$parsed = goat_perf_parse_description($row->description);

		if ($parsed['tier'] === null)
			$dq_unclassified_tiers++;

		/*
		/* Equipment classification — the ROLE ALONE, by substring, never by rate.
		/* See the note on GOAT_PERF_EQUIPMENT_RATE for the evidence that killed
		/* the rate half of this test.
		/*
		/* Substring rather than equality because the role is free text that has
		/* drifted around the paygrade name: 'PRG Harness Hire', 'Harness Hire -
		/* No Charge' and a double-space variant are all the same grade, and exact
		/* matching caught only the bare one.
		*/
		$role_key     = goat_perf_normalise_role($parsed['role']);
		$role_is_eq   = false;

		if ($role_key !== '')
		{
			for ($e = 0; $e < count($equipment_roles); $e++)
			{
				if (strpos($role_key, $equipment_roles[$e]) !== false)
				{
					$role_is_eq = true;
					break;
				}
			}
		}

		$is_equipment = $role_is_eq;

		/*
		/* Both halves still sized separately, now purely as the canary. These two
		/* counters are what proved the rate test wrong (0 and 52 on the 24 Aug
		/* 2026 test run) and they are what would catch it turning back: a
		/* non-zero rate80_non_equipment_lines means something other than
		/* equipment has started billing at 80, and equipment_rate_mismatch is now
		/* expected to be LARGE and is no longer an error.
		*/
		$rate_is_eq = (abs($rate - $GOAT_PERF_EQUIPMENT_RATE) < 0.0001);

		if ($role_is_eq && !$rate_is_eq)
			$dq_equip_rate_mismatch++;
		if ($rate_is_eq && !$role_is_eq)
			$dq_rate80_non_equip++;

		$booking_id = (int) $row->booking_id;
		$call_id    = (int) $row->call_id;

		$wk[$idx]['inv_hours']   += $line_hours;
		$wk[$idx]['inv_revenue'] += $line_revenue;
		$wk[$idx]['inv_bookings'][$booking_id] = true;

		if ($is_equipment)
		{
			$wk[$idx]['eq_hours']   += $line_hours;
			$wk[$idx]['eq_revenue'] += $line_revenue;
		}

		/*
		/* Two distinct integrity tests, deliberately not merged.
		/*
		/* double_invoiced_calls: a callID appearing on more than one invoiceID.
		/* Revenue for that call is summed twice by every query in this file, and
		/* nothing on screen would reveal it. Reconciles to 24 unbounded on prod
		/* (24 Aug 2026); fewer inside a 104-week window.
		/*
		/* calls_spanning_bookings: a callID carrying lines under more than one
		/* bookingID. A call belongs to exactly one booking, so this is a
		/* referential fault by definition — rarer, more serious, and unrelated to
		/* double billing.
		*/
		if ($call_id > 0)
		{
			$invoice_id = (int) $row->invoice_id;
			if ($invoice_id > 0)
			{
				if (!isset($call_invoices[$call_id]))
					$call_invoices[$call_id] = array();
				$call_invoices[$call_id][$invoice_id] = true;
			}
			if (!isset($call_bookings[$call_id]))
				$call_bookings[$call_id] = array();
			$call_bookings[$call_id][$booking_id] = true;
		}

		/*
		/* Customers. A line whose booking has no customer is bucketed under id 0
		/* rather than dropped — dropping it would make the customer series and
		/* the totals disagree, and the gap is worth seeing.
		*/
		$customer_id = ($row->customer_id === null) ? 0 : (int) $row->customer_id;

		if (!isset($cust[$customer_id]))
		{
			$name = ($row->customer_name === null || trim((string) $row->customer_name) === '')
			      ? '(no customer)'
			      : (string) $row->customer_name;

			$cust[$customer_id] = array(
				'id'      => $customer_id,
				'name'    => $name,
				'revenue' => 0.0,               /* requested window — the ranking basis */
				'hours'   => 0.0,
				'weeks'   => array()            /* full span */
			);
		}

		if (!isset($cust[$customer_id]['weeks'][$idx]))
			$cust[$customer_id]['weeks'][$idx] = array('revenue' => 0.0, 'hours' => 0.0);

		$cust[$customer_id]['weeks'][$idx]['revenue'] += $line_revenue;
		$cust[$customer_id]['weeks'][$idx]['hours']   += $line_hours;

		if ($in_request)
		{
			$cust[$customer_id]['revenue'] += $line_revenue;
			$cust[$customer_id]['hours']   += $line_hours;

			$tot_revenue += $line_revenue;
			$tot_hours   += $line_hours;
			$tot_bookings[$booking_id] = true;
			$tot_lines++;
		}
	}

	$dq_double_invoiced   = 0;
	$dq_spanning_bookings = 0;

	foreach ($call_invoices as $cid => $set)
	{
		if (count($set) > 1)
			$dq_double_invoiced++;
	}

	foreach ($call_bookings as $cid => $set)
	{
		if (count($set) > 1)
			$dq_spanning_bookings++;
	}

	unset($call_invoices);
	unset($call_bookings);


	/* ──────────────────────────────────────────────────────────────────────────
	/* Query 3 — the set of callIDs that have EVER been invoiced
	/*
	/* Read as its own scan rather than as NOT EXISTS / LEFT JOIN inside query 4.
	/* invoice_lines is not known to carry an index on callID, so a correlated
	/* NOT EXISTS would scan 121k rows once per candidate call; and a LEFT JOIN
	/* would multiply every invoiced call by its line count before the IS NULL
	/* discarded the lot. One scan, held as an array of keys, costs a few MB and
	/* is exact.
	/*
	/* Deliberately NOT restricted to the window: a call invoiced on a line dated
	/* outside the window is still an invoiced call, and treating it as
	/* uninvoiced would double-count it against the invoiced band.
	/* ────────────────────────────────────────────────────────────────────────── */

	$invoiced_calls = array();

	$res_ic = mysql_query("SELECT DISTINCT callID FROM invoice_lines WHERE callID > 0");

	if ($res_ic === false)
		goat_json_error(500, 'invoiced call query failed: ' . mysql_error());

	while ($row = mysql_fetch_object($res_ic))
		$invoiced_calls[(int) $row->callID] = true;


	/* ──────────────────────────────────────────────────────────────────────────
	/* Query 4 — delivered, not invoiced
	/*
	/* Crew rows on calls in the window that carry no invoice line at all, where
	/* actual times have been keyed.
	/*
	/*   billable = (off - on) - break - break_night,  off += 24h where off < on
	/*
	/* calls.times_filled is deliberately NOT used as the "times are keyed" test.
	/* It is a human-review flag that THE GOAT's own times writer never sets
	/* (update-call-times.php documents that), so gating on it would drop every
	/* call whose times were entered through THE GOAT — which is an increasing
	/* share of them. The per-row on/off test is the honest one.
	/*
	/* callchargeout / callchargeout_night live on call_crew_map, copied from the
	/* paygrade at save time — they are PER CREW MEMBER, not per call.
	/*
	/* The day/night split is NOT applied to this estimate. The boundary is known
	/* (08:00 / 20:00 — the Estimator's app_settings, corroborated by invoice
	/* 34501 splitting one crew block at 08:00), but no rule for it exists in this
	/* repo, and a modelled figure sitting beside a measured one is worse than a
	/* conservative one. Applying the day rate throughout UNDERSTATES any call with
	/* night hours — a systematic, one-directional bias, which the client must
	/* label. uninvoiced_night_rows sizes it. Release 2 implements the split for
	/* forward segmentation and can revisit this band then.
	/* ────────────────────────────────────────────────────────────────────────── */

	$excluded_status = implode(',', array_map('intval', $GOAT_PERF_EXCLUDED_CREW_STATUS));

	$sql_crew = "
		SELECT
			c.id                     AS call_id,
			c.start_date             AS start_date,
			ccm.`on`                 AS on_time,
			ccm.`off`                AS off_time,
			ccm.`break`              AS break_time,
			ccm.`break_night`        AS break_night_time,
			ccm.status               AS crew_status,
			ccm.callchargeout        AS chargeout,
			ccm.callchargeout_night  AS chargeout_night
		FROM calls c
		JOIN call_crew_map ccm ON ccm.callID = c.id
		JOIN bookings b        ON b.id = c.bookingID
		WHERE c.start_date > 0
		  AND c.start_date BETWEEN " . (int) $range_start . " AND " . (int) $range_end . "
		  AND b.hidden = 0
		  AND ccm.status NOT IN ($excluded_status)
	";

	$res_crew = mysql_query($sql_crew);

	if ($res_crew === false)
		goat_json_error(500, 'crew time query failed: ' . mysql_error());

	while ($row = mysql_fetch_object($res_crew))
	{
		$call_id = (int) $row->call_id;

		if (isset($invoiced_calls[$call_id]))
			continue;                          /* invoiced — belongs to the other band */

		$on_raw  = trim((string) $row->on_time);
		$off_raw = trim((string) $row->off_time);

		/* Times never keyed. */
		if ($on_raw === '00:00:00' && $off_raw === '00:00:00')
			continue;

		$on  = goat_perf_hms_to_seconds($on_raw);
		$off = goat_perf_hms_to_seconds($off_raw);

		if ($on === null || $off === null)
			continue;                          /* nothing usable to measure */

		/*
		/* on keyed, off still at midnight. This is EITHER a genuine midnight
		/* finish OR an off time nobody ever entered, and the row carries nothing
		/* that tells the two apart. Rolling the overnight rule over it would
		/* invent a ~16 hour shift out of a missing keystroke, so the row is
		/* excluded and counted instead. If that count comes back large the
		/* question is real; if it comes back at 3 it never mattered.
		*/
		if ($off_raw === '00:00:00' && $on_raw !== '00:00:00')
		{
			$dq_ambiguous_off_rows++;
			continue;
		}

		if ($off < $on)
			$off += 86400;                     /* overnight */

		$worked = $off - $on;

		if ($worked <= 0)
			continue;

		/*
		/* Breaks. A malformed value ('00:75') is EXCLUDED from the subtraction
		/* and counted — never coerced. Coercion turns a keying error into a
		/* slightly-wrong aggregate that nobody can find again.
		*/
		$brk  = goat_perf_hms_to_seconds($row->break_time);
		$brkn = goat_perf_hms_to_seconds($row->break_night_time);

		if (trim((string) $row->break_time) !== '' && $brk === null)
			$dq_malformed_breaks++;
		if (trim((string) $row->break_night_time) !== '' && $brkn === null)
			$dq_malformed_breaks++;

		if ($brk !== null)
			$worked -= $brk;
		if ($brkn !== null)
			$worked -= $brkn;

		if ($worked <= 0)
			continue;

		$billable_hours = $worked / 3600.0;

		/*
		/* Estimated revenue. The day/night SPLIT is not modelled: nothing in this
		/* codebase carries SmartStaff's night boundary rule, and inventing one
		/* would put a made-up number next to a measured one. The day chargeout is
		/* applied throughout, which UNDER-states any call with night hours. Rows
		/* carrying a night signal are counted so the size of that understatement
		/* is visible rather than assumed.
		*/
		$rate       = (double) $row->chargeout;
		$rate_night = (double) $row->chargeout_night;

		if (($brkn !== null && $brkn > 0) || $rate_night > $rate)
			$dq_night_flagged_rows++;

		$ts  = (int) $row->start_date;
		$key = goat_perf_week_key($ts);

		if (!isset($week_index[$key]))
		{
			$dq_unbucketed_crew++;
			continue;
		}

		$idx = $week_index[$key];

		$wk[$idx]['unv_hours']   += $billable_hours;
		$wk[$idx]['unv_revenue'] += $billable_hours * $rate;

		$dq_uninvoiced_rows++;

		/* Divergence from BRIEF §A.6, made visible rather than assumed. The brief
		/* said count status 5; this endpoint excludes 6 and 8 instead, so 0 and 1
		/* (offered / pending) are included. A pending row with times keyed almost
		/* certainly means the work happened and the status was never updated — but
		/* that is a judgement, so it is sized here rather than asserted. If this
		/* count is material, the reading needs confirming with Rich. */
		if ((int) $row->crew_status !== 5)
			$dq_uninvoiced_non_confirmed++;
	}

	unset($invoiced_calls);


	/* ──────────────────────────────────────────────────────────────────────────
	/* Customer ranking — top N by revenue over the REQUESTED window, remainder
	/* rolled up.
	/*
	/* Done here, never on the client: the roll-up must carry a customer_count or
	/* "All other: $2.1m" is uninterpretable, and a client that ranks its own top
	/* eight will eventually rank a different eight from the totals it is shown
	/* beside.
	/* ────────────────────────────────────────────────────────────────────────── */

	function goat_perf_cmp_revenue($a, $b)
	{
		if ($a['revenue'] == $b['revenue'])
			return 0;
		return ($a['revenue'] < $b['revenue']) ? 1 : -1;
	}

	$ranked = array_values($cust);
	usort($ranked, 'goat_perf_cmp_revenue');

	$top   = array();
	$other = array(
		'customer_count' => 0,
		'revenue'        => 0.0,
		'hours'          => 0.0,
		'weeks'          => array()
	);

	$customer_count = 0;                       /* customers active in the window */

	for ($i = 0; $i < count($ranked); $i++)
	{
		$c = $ranked[$i];

		if ($c['revenue'] != 0.0 || $c['hours'] != 0.0)
			$customer_count++;

		if ($i < $GOAT_PERF_TOP_CUSTOMERS)
		{
			$weeks_out = array();

			foreach ($c['weeks'] as $idx => $v)
			{
				$weeks_out[$week_keys[$idx]] = array(
					'revenue' => round($v['revenue'], 2),
					'hours'   => round($v['hours'], 2)
				);
			}

			$top[] = array(
				'id'      => $c['id'],
				'name'    => $c['name'],
				'revenue' => round($c['revenue'], 2),
				'hours'   => round($c['hours'], 2),
				'weeks'   => $weeks_out
			);

			continue;
		}

		/* Roll-up. Counts only customers with activity in the requested window,
		   so the label reads "and 214 others" rather than "and every customer we
		   have ever had". */
		if ($c['revenue'] != 0.0 || $c['hours'] != 0.0)
			$other['customer_count']++;

		$other['revenue'] += $c['revenue'];
		$other['hours']   += $c['hours'];

		foreach ($c['weeks'] as $idx => $v)
		{
			if (!isset($other['weeks'][$idx]))
				$other['weeks'][$idx] = array('revenue' => 0.0, 'hours' => 0.0);

			$other['weeks'][$idx]['revenue'] += $v['revenue'];
			$other['weeks'][$idx]['hours']   += $v['hours'];
		}
	}

	$other_weeks_out = array();

	foreach ($other['weeks'] as $idx => $v)
	{
		$other_weeks_out[$week_keys[$idx]] = array(
			'revenue' => round($v['revenue'], 2),
			'hours'   => round($v['hours'], 2)
		);
	}

	$other['weeks']   = $other_weeks_out;
	$other['revenue'] = round($other['revenue'], 2);
	$other['hours']   = round($other['hours'], 2);


	/* ──────────────────────────────────────────────────────────────────────────
	/* Response
	/* ────────────────────────────────────────────────────────────────────────── */

	$weeks_out = array();

	for ($i = 0; $i < $span; $i++)
	{
		$w = $wk[$i];

		$weeks_out[] = array(
			'key'       => $week_keys[$i],
			'in_window' => ($i >= $req_first) ? 1 : 0,
			'invoiced'  => array(
				'hours'             => round($w['inv_hours'], 2),
				'revenue'           => round($w['inv_revenue'], 2),
				'bookings'          => count($w['inv_bookings']),
				'equipment_hours'   => round($w['eq_hours'], 2),
				'equipment_revenue' => round($w['eq_revenue'], 2)
			),
			'uninvoiced' => array(
				'hours'             => round($w['unv_hours'], 2),
				'estimated_revenue' => round($w['unv_revenue'], 2)
			)
		);
	}

	echo json_encode(array(
		'ok'           => true,
		'generated_at' => date('Y-m-d\TH:i:s'),
		'window'       => array(
			'weeks' => $weeks,
			'from'  => $week_keys[$req_first],
			'to'    => $week_keys[$span - 1],
			'compare_from' => $week_keys[0],
			'compare_to'   => $week_keys[$req_first - 1]
		),
		'totals' => array(
			'revenue'        => round($tot_revenue, 2),
			'hours'          => round($tot_hours, 2),
			'bookings'       => count($tot_bookings),
			'customer_count' => $customer_count
		),
		'weeks'     => $weeks_out,
		'customers' => $top,
		'other'     => $other,
		'data_quality' => array(
			/* named by the brief */
			'malformed_breaks'              => $dq_malformed_breaks,
			'unclassified_tiers'            => $dq_unclassified_tiers,
			'double_invoiced_calls'         => $dq_double_invoiced,
			/* a call carrying lines under more than one bookingID — a referential
			   fault, unrelated to double billing */
			'calls_spanning_bookings'       => $dq_spanning_bookings,
			/* added — each one sizes an assumption made above */
			'invoiced_lines'                => $tot_lines,
			'zero_qty_lines'                => $dq_zero_qty_lines,
			'equipment_paygrades'           => count($equipment_roles),
			'equipment_rate_mismatch'       => $dq_equip_rate_mismatch,
			'rate80_non_equipment_lines'    => $dq_rate80_non_equip,
			'uninvoiced_crew_rows'          => $dq_uninvoiced_rows,
			'uninvoiced_night_rows'         => $dq_night_flagged_rows,
			'ambiguous_off_rows'            => $dq_ambiguous_off_rows,
			'uninvoiced_non_confirmed_rows' => $dq_uninvoiced_non_confirmed,
			'unbucketed_lines'              => $dq_unbucketed_lines,
			'unbucketed_crew_rows'          => $dq_unbucketed_crew
		)
	));

?>
