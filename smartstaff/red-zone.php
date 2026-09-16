<?php

	/*
	/* global file
	/*
	/* SS_NO_SMARTY sits on the line IMMEDIATELY ABOVE the global.php include,
	/* tab-indented, no blank line between them — GUIDE-endpoint-header-ss-no-smarty.md.
	/* global.php otherwise loads Smarty 2 unconditionally and Smarty 2 fatals on
	/* PHP 8 at class-declaration time, before a line of this file runs.
	/*
	/* There must be EXACTLY ONE global.php include in this file. The house check
	/* is the grep in GUIDE-endpoint-header-ss-no-smarty.md, run against this file
	/* after any edit to the lines below; it must return 1. Two includes is valid
	/* syntax, invisible in a casual diff, and a fatal on request.
	/*
	/* THE CHECK STRING IS DELIBERATELY NOT QUOTED IN THIS COMMENT. Writing it out
	/* here would make the grep match the comment as well as the code and report 2
	/* on a correct file — breaking the verification for everyone who runs it
	/* afterwards. Same reason the two rules below are described rather than
	/* spelled: the house rules are enforced by grep, so the prose must not
	/* contain the tokens the greps look for.
	*/

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');

	header('Content-Type: application/json');

	/*
	/* THE RED ZONE — no-shows and late arrivals per crew member, over a rolling
	/* window, for THE GOAT's Today -> Operations lane.
	/*
	/* DESIGN-ops-red-zone-v0_1.md (v0.2). Probe run on prod 16 Sep 2026.
	/*
	/* TWO MODES, one file, because the SS_NO_SMARTY/cohort/PDO preamble should
	/* exist once and the two share every filter:
	/*
	/*   mode=summary   (default)  the lane CARD. 30-day counters + the two donut
	/*                             segment distributions + the register headcount.
	/*                             Returns NO crew rows.
	/*   mode=register             the DRILL-THROUGH. The full ranked register
	/*                             plus twelve monthly trend buckets.
	/*
	/* WHY THE SPLIT EXISTS. THE GOAT's Ops lanes load STRICTLY ONE AT A TIME
	/* behind a per-session PHP file lock, so every millisecond here is added to
	/* every lane after it. On prod the 30-day query is 48ms and the twelve-month
	/* register is 406ms. The card pays the register cost too (the donuts are
	/* distributions over it) but Flask memoises for 900s, and what this split
	/* really saves is SHIPPING ~150 crew rows of JSON on every Ops page load.
	/*
	/* WHAT COUNTS (design D1/D4, confirmed by probe P1a/P1b/P4 on prod):
	/*
	/*   no-show  call_crew_map.status = 8
	/*   late     call_crew_map.status = 5 AND call_crew_map.late = '1'
	/*
	/* `late` is binary(1) holding the CHARACTERS '0' and '1' — HEX() returned
	/* exactly 0x30 (248,798 rows) and 0x31 (4,528) on prod, nothing else, no
	/* NULLs. So `= '1'` is the correct test. It is the is_call_boss binary(50)
	/* trap one size down: binary(1) does not pad, so this survives, but NEVER
	/* write `late = 1` or use `late = 0` as the negative test — the positive byte
	/* is tested explicitly here and everything else is not-late, which also
	/* survives a future writer introducing a third value.
	/*
	/* The `status = 5` guard on the late test is REDUNDANT TODAY and kept anyway:
	/* probe P1b found all 720 lates in the window sitting on status 5 and zero on
	/* 0/1/6/7/8/9. Stating the intent costs nothing and outlives that being true.
	/*
	/* WHAT IS EXCLUDED, and both matter:
	/*
	/*   calls.cancelled_at IS NULL   a cancelled call's rows are nobody's failure
	/*   status 9                     WE stood them down. get-crew-offer-stats.php
	/*                                is explicit that cancelling must never count
	/*                                against a crew member, and a screen called
	/*                                Red Zone must not be the place that breaks
	/*                                that rule. Excluded by status IN (5,8).
	/*
	/* LATE WITHDRAWALS ARE NOT HERE and cannot be. A 5 -> 6 transition stamps
	/* nothing: the live ccm_responded_at_upd trigger has no arm for it, and
	/* prev_status is written only by cancel-call.php. Probe P4 on prod: of 5,887
	/* declined rows in the window, prev_status was non-null on ZERO. A withdrawn
	/* row is indistinguishable from a straight decline. Design §4.4 — it needs a
	/* column and a trigger arm and starts from a cutover; it is not a query.
	/*
	/* THE DENOMINATOR IS status IN (5,8), NOT status = 5. A no-show IS a shift
	/* they were rostered for. Counting only 5 shrinks the denominator by exactly
	/* the numerator, inflating every rate and punishing hardest the people with
	/* the fewest shifts.
	/*
	/* calls.start_date IS A UNIX TIMESTAMP AT LOCAL MIDNIGHT (int), with
	/* start_time a separate TIME column. Every bound below is built in PHP with
	/* strtotime(), which knows about the two Melbourne DST transitions, rather
	/* than by adding clock seconds to an instant in SQL. Same reasoning as
	/* goat_call_window() in calls-awaiting-times.php.
	/*
	/* PHP 5.x — array() rather than short array syntax, no null-coalescing
	/* operator, tabs. PDO via goat_pdo(), never the legacy ext/mysql API: this
	/* endpoint must not add to the 903-call-site conversion backlog. Same handle
	/* and same house pattern as list-licence-holders.php.
	*/

	/*
	/* AUTH.
	/*
	/* goat_can_read_all() — the shared gate, mirroring the Flask route's
	/* @require_cohort(*READ_ALL_COHORTS) so the two agree by construction rather
	/* than by coincidence. Admin, leadership and operations.
	/*
	/* KNOWN AND ACCEPTED: goat_can_read_all() ALSO grants on the Crew API service
	/* key (cohort.php branch 2). The design originally called for excluding it on
	/* the grounds that a named-individual performance register should not be
	/* reachable with a shared secret — that was written before the helper was
	/* read, and the branch lives INSIDE the shared gate. Writing a bespoke
	/* stricter gate here would put the cohort list in two places, which is the
	/* drift the licence register's header warns about, to defend against a caller
	/* that does not exist: nothing in Crew Hub calls this endpoint.
	/*
	/* If a service-key caller is ever added, that is a DELIBERATE decision to
	/* expose this data through the portal and it should be taken on purpose, in
	/* the design, not discovered here.
	*/

	if (!goat_can_read_all())
	{
		goat_json_error(403, 'Admin, Leadership or Operations required');
		exit;
	}

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		goat_json_error(503, 'Red Zone storage is unavailable');
		exit;
	}

	/*
	/* ── INPUT ────────────────────────────────────────────────────────────────
	/*
	/*   mode        summary | register
	/*   start,end   YYYY-MM-DD, on the CALL DATE. This is a report about a period
	/*               of WORK, unlike get-crew-offer-stats.php whose window is on
	/*               when the offer was made.
	/*   min_rate    the inclusion floor as a percentage (D8, default 5.0). See
	/*               the block where it is parsed — it exists because the first
	/*               prod run returned 37 people who were late once in a year.
	/*   min_shifts  the floor (D6, default 20). Probe P3 on prod: a floor of 20
	/*               excludes 268 of 515 crew — 52% of the people — but only 1,666
	/*               of 19,937 shifts, 8.4% of the work. Below 20 the ranking is
	/*               dominated by tiny denominators; P5 at a floor of 5 put a man
	/*               with 5 incidents in 7 shifts at the top of the list, his last
	/*               one ten months earlier.
	/*   since_days  recency cap on the last incident (D5, default 90). 0 lifts it.
	/*               THE CAP IS APPLIED IN PHP, not in SQL, so one query serves
	/*               both the capped default and the uncapped view — the client
	/*               clears a chip and the register is re-filtered without a second
	/*               round trip. The row count here is in the low hundreds.
	*/

	$mode = isset($_GET['mode']) ? (string) $_GET['mode'] : 'summary';

	if ($mode !== 'summary' && $mode !== 'register')
	{
		goat_json_error(400, 'mode must be summary or register');
		exit;
	}

	$today_ts = strtotime('today');          /* local midnight, matches start_date */
	$end_ts   = strtotime('tomorrow');       /* exclusive upper bound */

	$start_raw = isset($_GET['start']) ? (string) $_GET['start'] : '';
	$end_raw   = isset($_GET['end'])   ? (string) $_GET['end']   : '';

	if ($start_raw !== '')
	{
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_raw))
		{
			goat_json_error(400, 'start must be YYYY-MM-DD');
			exit;
		}

		$start_ts = strtotime($start_raw . ' 00:00:00');

		if ($start_ts === false)
		{
			goat_json_error(400, 'invalid start date');
			exit;
		}
	}
	else
	{
		$start_ts = strtotime('-12 months', $today_ts);
	}

	if ($end_raw !== '')
	{
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_raw))
		{
			goat_json_error(400, 'end must be YYYY-MM-DD');
			exit;
		}

		$e = strtotime($end_raw . ' 00:00:00');

		if ($e === false)
		{
			goat_json_error(400, 'invalid end date');
			exit;
		}

		/* end is INCLUSIVE of that whole day, so the bound is the next midnight */
		$end_ts = strtotime('+1 day', $e);
	}

	if ($end_ts <= $start_ts)
	{
		goat_json_error(400, 'invalid date range');
		exit;
	}

	/* Cap the span to protect the database, exactly as get-crew-offer-stats.php
	/* does. 400 days leaves room for a full year plus a shifted boundary. */

	if (($end_ts - $start_ts) > (400 * 86400))
	{
		goat_json_error(400, 'window exceeds 400 days');
		exit;
	}

	$min_shifts = isset($_GET['min_shifts']) ? (int) $_GET['min_shifts'] : 20;

	if ($min_shifts < 1)  { $min_shifts = 1; }
	if ($min_shifts > 500) { $min_shifts = 500; }

	/*
	/* min_rate — THE INCLUSION FLOOR (D8, default 5.0%). Added 16 Sep after the
	/* first prod run, which is the only reason it exists.
	/*
	/* At 20 shifts / 90 days and no rate floor the register returned 58 crew, 37
	/* of them under 10%, with a tail like this:
	/*
	/*     Cameron Orlov     1 incident in 182 shifts   0.5%
	/*     Jeremy Bird       1 incident in 170 shifts   0.6%
	/*
	/* One late in 182 shifts is not a red zone, it is an excellent year. A screen
	/* that names individuals has to mean what its title says, and 37 rows of
	/* people who were late once is how Rich stops trusting the other 21.
	/*
	/* THIS ALSO REPLACES AN EARLIER WRONG FIX OF MINE. The design's bands were
	/* severe >= 20 / elevated 10-19 / watch 5-9, which left anyone below 5% in
	/* the register and in NO band — a row on screen belonging to no donut segment
	/* and reachable by no chip. I closed that by making `watch` a catch-all,
	/* which treated the symptom: the hole was never in the band definition, it
	/* was in the inclusion predicate. With a 5% floor the original bands are
	/* exactly total and nothing is homeless.
	/*
	/* The band function below STAYS total anyway, because min_rate is a request
	/* parameter: a caller passing min_rate=0 must not reopen the gap.
	*/

	$min_rate = isset($_GET['min_rate']) ? (float) $_GET['min_rate'] : 5.0;

	if ($min_rate < 0.0)   { $min_rate = 0.0; }
	if ($min_rate > 100.0) { $min_rate = 100.0; }

	$since_days = isset($_GET['since_days']) ? (int) $_GET['since_days'] : 90;

	if ($since_days < 0)    { $since_days = 0; }
	if ($since_days > 3650) { $since_days = 3650; }

	$since_ts = ($since_days > 0) ? strtotime('-' . $since_days . ' days', $today_ts) : 0;

	/*
	/* ── THE REGISTER AGGREGATE ───────────────────────────────────────────────
	/*
	/* One row per crew member with at least `min_shifts` rostered shifts and at
	/* least one incident.
	/*
	/* PLAN (EXPLAIN, read on test; prod timings confirm the same indexes are
	/* working there): range on idx_calls_start_date -> ref on idx_ccm_call_status
	/* (callID, status) -> eq_ref on users PRIMARY. ~7 crew rows per call.
	/*
	/* HAVING references the SELECT aliases, which MySQL allows and which keeps
	/* the two long CASE sums written once.
	/*
	/* users.active is NOT filtered. A deactivated crew member's history is still
	/* history, and somebody who left after a run of no-shows is exactly who ops
	/* may be asked about. The roster flag is RETURNED and the client badges it —
	/* same decision, and the same reasoning, as list-licence-holders.php.
	*/

	$register_sql = "
		SELECT ccm.`userID`                                            AS user_id,
		       u.`ein`, u.`firstname`, u.`lastname`, u.`active`,
		       COUNT(*)                                                AS rostered,
		       SUM(ccm.`status` = 8)                                   AS no_shows,
		       SUM(ccm.`status` = 5 AND ccm.`late` = '1')              AS lates,
		       SUM(ccm.`status` = 8)
		         + SUM(ccm.`status` = 5 AND ccm.`late` = '1')          AS incidents,
		       ROUND(100 * (SUM(ccm.`status` = 8)
		                    + SUM(ccm.`status` = 5 AND ccm.`late` = '1'))
		                 / COUNT(*), 1)                                AS rate_pct,
		       MAX(CASE WHEN ccm.`status` = 8
		                  OR (ccm.`status` = 5 AND ccm.`late` = '1')
		                THEN c.`start_date` END)                       AS last_incident_ts
		  FROM `calls` c
		  INNER JOIN `call_crew_map` ccm ON ccm.`callID` = c.`id`
		  INNER JOIN `users` u           ON u.`id`       = ccm.`userID`
		 WHERE c.`start_date` >= :start_ts
		   AND c.`start_date` <  :end_ts
		   AND c.`cancelled_at` IS NULL
		   AND u.`usergroupID` = 3
		   AND ccm.`status` IN (5, 8)
		 GROUP BY ccm.`userID`
		HAVING rostered >= :min_shifts
		   AND incidents > 0
		   AND rate_pct >= :min_rate
		 ORDER BY rate_pct DESC, incidents DESC, u.`lastname` ASC
	";

	/*
	/* TWO NOTES ON THE SHAPE ABOVE, both deliberate.
	/*
	/* 1. THE RATE IS COMPUTED IN SQL, not in PHP, so the ordering the database
	/*    applied and the number the client displays can never disagree. An
	/*    earlier draft ordered by an expression and recomputed the percentage in
	/*    PHP — two roundings of the same quantity, which is how a list ends up
	/*    subtly out of order at the ties.
	/*
	/* 2. u.ein / firstname / lastname / active are SELECTed while the GROUP BY is
	/*    on ccm.userID alone. They are functionally dependent on it, and MariaDB
	/*    does not carry ONLY_FULL_GROUP_BY in its default sql_mode — this exact
	/*    shape is what probe P5 ran on prod on 16 Sep, successfully, in 0.4055s.
	/*    Adding them to the GROUP BY would also be correct and would produce the
	/*    identical row set, but it would mean shipping a query the probe did not
	/*    test. If sql_mode ever gains ONLY_FULL_GROUP_BY this is the line that
	/*    breaks, and adding the four columns to the GROUP BY is the whole fix.
	*/

	/*
	/* ERRMODE_EXCEPTION means a broken query throws rather than returning false,
	/* so the try/catch IS the error check — there is no `=== false` branch to
	/* write. The message is logged and never returned: it can carry schema detail.
	*/

	try
	{
		$stmt = $pdo->prepare($register_sql);
		$stmt->bindValue(':start_ts',   $start_ts,   PDO::PARAM_INT);
		$stmt->bindValue(':end_ts',     $end_ts,     PDO::PARAM_INT);
		$stmt->bindValue(':min_shifts', $min_shifts, PDO::PARAM_INT);

		/* PARAM_STR, not PARAM_INT: this is a decimal and PARAM_INT would
		/* truncate 5.0 to 5 harmlessly but 12.5 to 12 silently. sprintf pins the
		/* format so the value bound is the value validated. */
		$stmt->bindValue(':min_rate', sprintf('%.2f', $min_rate), PDO::PARAM_STR);

		$stmt->execute();
		$found = $stmt->fetchAll();
	}
	catch (PDOException $e)
	{
		error_log('red-zone: register query failed: ' . $e->getMessage());
		goat_json_error(500, 'Red Zone read failed');
		exit;
	}

	/*
	/* ZERO ROWS IS A LEGITIMATE RESULT HERE, and this is the opposite call to
	/* list-licence-holders.php, deliberately. That endpoint refuses an empty
	/* result because user_licenses holds ~16,800 rows and an empty read can only
	/* mean a broken query. Here, "nobody cleared the floor with an incident" is a
	/* real and welcome state of the world. The DENOMINATOR is returned alongside
	/* so the client can always tell "no incidents" from "no work happened".
	*/

	$register   = array();
	$profile    = array('no_shows_only' => 0, 'both' => 0, 'lates_only' => 0);
	$band       = array('severe' => 0, 'elevated' => 0, 'watch' => 0);
	$total_rows = 0;

	foreach ($found as $r)
	{
		$rostered  = (int) $r['rostered'];
		$no_shows  = (int) $r['no_shows'];
		$lates     = (int) $r['lates'];
		$incidents = (int) $r['incidents'];

		$last_ts = ($r['last_incident_ts'] === null) ? null : (int) $r['last_incident_ts'];

		/*
		/* THE RECENCY CAP (D5). Applied here rather than in the HAVING so that
		/* one query serves both views. A crew member whose last incident was ten
		/* months ago is a fact about last year, not a person Ops needs today —
		/* P5 on prod had exactly that man at the top of the list, at 71.4%.
		*/

		$total_rows++;

		if ($since_ts > 0 && ($last_ts === null || $last_ts < $since_ts))
		{
			continue;
		}

		/*
		/* CAST BEFORE COMPARING. PDO with EMULATE_PREPARES => false does not
		/* promise strings back — mysqlnd can hand back native ints — so an
		/* identity test against '1' would silently fail and badge the whole
		/* roster 'unflagged'. (string) makes it true either way. Same note, same
		/* reason, as list-licence-holders.php.
		/*
		/* Blank is NOT inactive: it is the largest bucket on this database and it
		/* contains people who work.
		*/

		$active = (string) $r['active'];

		if ($active === '1')
			$flag = 'active';
		else if ($active === '0')
			$flag = 'inactive';
		else
			$flag = 'unflagged';

		/* Taken from the database, never recomputed — see note 1 on the query.
		/* PDO hands ROUND() back as a string; (float) is the cast, and the value
		/* is already rounded to one place server-side. */

		$rate = (float) $r['rate_pct'];

		if ($no_shows > 0 && $lates === 0)
			$prof = 'no_shows_only';
		else if ($lates > 0 && $no_shows === 0)
			$prof = 'lates_only';
		else
			$prof = 'both';

		/*
		/* severe >= 20 · elevated 10-19 · watch below 10.
		/*
		/* With min_rate at its 5.0 default these are exactly the design's three
		/* bands and `watch` is 5-9.9 as intended. `watch` is written as the
		/* catch-all rather than as a 5-9 range ON PURPOSE: min_rate is a request
		/* parameter, so a caller passing min_rate=0 would otherwise put rows in
		/* the register that belong to no segment and answer to no chip. The
		/* inclusion floor decides who is here; the bands never leave anyone who
		/* IS here without a home. Thresholds are Rich's to set (Q6).
		*/

		if ($rate >= 20.0)
			$bnd = 'severe';
		else if ($rate >= 10.0)
			$bnd = 'elevated';
		else
			$bnd = 'watch';

		$profile[$prof]++;
		$band[$bnd]++;

		/* SmartStaff stores HTML-encoded names — decode before the client
		/* escapes, or "O'Brien" arrives as "O&#039;Brien". */

		$register[] = array(
			'user_id'       => (int) $r['user_id'],
			'ein'           => $r['ein'],
			'firstname'     => html_entity_decode((string) $r['firstname'], ENT_QUOTES, 'UTF-8'),
			'lastname'      => html_entity_decode((string) $r['lastname'],  ENT_QUOTES, 'UTF-8'),
			'roster_flag'   => $flag,
			'rostered'      => $rostered,
			'no_shows'      => $no_shows,
			'lates'         => $lates,
			'incidents'     => $incidents,
			'rate'          => $rate,
			'profile'       => $prof,
			'band'          => $bnd,
			'last_incident' => ($last_ts === null) ? null : date('Y-m-d', $last_ts)
		);
	}

	/*
	/* ── THE CARD'S 30-DAY COUNTERS ───────────────────────────────────────────
	/*
	/* The badge is incidents in the last 30 days (D3) — a live number that moves
	/* week to week, unlike the near-static twelve-month headcount. Probe P7 on
	/* prod: 1,464 rostered, 16 no-shows + 31 lates = 47 incidents across 36 crew.
	/* The feared outcome, a badge reading 0 most weeks, does not happen.
	/*
	/* This window is INDEPENDENT of start/end — it is always the last 30 days,
	/* because it is the badge, not the report.
	*/

	$recent = array(
		'days'          => 30,
		'rostered'      => 0,
		'no_shows'      => 0,
		'lates'         => 0,
		'incidents'     => 0,
		'crew_involved' => 0
	);

	$recent_sql = "
		SELECT COUNT(*)                                       AS rostered,
		       SUM(ccm.`status` = 8)                          AS no_shows,
		       SUM(ccm.`status` = 5 AND ccm.`late` = '1')     AS lates,
		       COUNT(DISTINCT CASE WHEN ccm.`status` = 8
		                             OR (ccm.`status` = 5 AND ccm.`late` = '1')
		                           THEN ccm.`userID` END)     AS crew_involved
		  FROM `calls` c
		  INNER JOIN `call_crew_map` ccm ON ccm.`callID` = c.`id`
		  INNER JOIN `users` u           ON u.`id`       = ccm.`userID`
		 WHERE c.`start_date` >= :start_ts
		   AND c.`start_date` <  :end_ts
		   AND c.`cancelled_at` IS NULL
		   AND u.`usergroupID` = 3
		   AND ccm.`status` IN (5, 8)
	";

	try
	{
		$stmt = $pdo->prepare($recent_sql);
		$stmt->bindValue(':start_ts', strtotime('-30 days', $today_ts), PDO::PARAM_INT);
		$stmt->bindValue(':end_ts',   strtotime('tomorrow'),            PDO::PARAM_INT);
		$stmt->execute();
		$rrow = $stmt->fetch();
	}
	catch (PDOException $e)
	{
		error_log('red-zone: 30-day query failed: ' . $e->getMessage());
		goat_json_error(500, 'Red Zone read failed');
		exit;
	}

	if ($rrow !== false && $rrow !== null)
	{
		$recent['rostered']      = (int) $rrow['rostered'];
		$recent['no_shows']      = (int) $rrow['no_shows'];
		$recent['lates']         = (int) $rrow['lates'];
		$recent['incidents']     = $recent['no_shows'] + $recent['lates'];
		$recent['crew_involved'] = (int) $rrow['crew_involved'];
	}

	/*
	/* ── THE TREND STRIP ──────────────────────────────────────────────────────
	/*
	/* Twelve monthly buckets for the drill-through window's header — the one
	/* thing on this screen that answers "is this getting worse?". Register mode
	/* only: the card does not draw it (design D7 — house donuts), so the card
	/* does not pay for it.
	/*
	/* Months with no work are NOT emitted by the GROUP BY and are NOT backfilled
	/* here. The client renders one cell per returned bucket; a zero-work month
	/* and a zero-incident month are different facts and the `rostered` figure is
	/* what tells them apart.
	*/

	$trend = array();

	if ($mode === 'register')
	{
		$trend_sql = "
			SELECT FROM_UNIXTIME(c.`start_date`, '%Y-%m')          AS ym,
			       COUNT(*)                                        AS rostered,
			       SUM(ccm.`status` = 8)                           AS no_shows,
			       SUM(ccm.`status` = 5 AND ccm.`late` = '1')      AS lates
			  FROM `calls` c
			  INNER JOIN `call_crew_map` ccm ON ccm.`callID` = c.`id`
			  INNER JOIN `users` u           ON u.`id`       = ccm.`userID`
			 WHERE c.`start_date` >= :start_ts
			   AND c.`start_date` <  :end_ts
			   AND c.`cancelled_at` IS NULL
			   AND u.`usergroupID` = 3
			   AND ccm.`status` IN (5, 8)
			 GROUP BY ym
			 ORDER BY ym ASC
		";

		try
		{
			$stmt = $pdo->prepare($trend_sql);
			$stmt->bindValue(':start_ts', $start_ts, PDO::PARAM_INT);
			$stmt->bindValue(':end_ts',   $end_ts,   PDO::PARAM_INT);
			$stmt->execute();
			$tfound = $stmt->fetchAll();
		}
		catch (PDOException $e)
		{
			error_log('red-zone: trend query failed: ' . $e->getMessage());
			goat_json_error(500, 'Red Zone read failed');
			exit;
		}

		foreach ($tfound as $t)
		{
			$tn = (int) $t['no_shows'];
			$tl = (int) $t['lates'];

			$trend[] = array(
				'month'     => $t['ym'],
				'rostered'  => (int) $t['rostered'],
				'no_shows'  => $tn,
				'lates'     => $tl,
				'incidents' => $tn + $tl
			);
		}
	}

	/*
	/* ── RESPONSE ─────────────────────────────────────────────────────────────
	/*
	/* `window` echoes what was actually used, including the two thresholds, so a
	/* screen naming individuals can always say what rule produced the list.
	/* `crew_total` is the count BEFORE the recency cap: the client needs it to
	/* label the chip honestly ("6 of 41 in the last 90 days") rather than
	/* implying the other 35 do not exist.
	*/

	$out = array(
		'generated_at' => date('Y-m-d\TH:i:s'),
		'window'       => array(
			'start'      => date('Y-m-d', $start_ts),
			'end'        => date('Y-m-d', strtotime('-1 day', $end_ts)),
			'min_shifts' => $min_shifts,
			'min_rate'   => $min_rate,
			'since_days' => $since_days
		),
		'recent'     => $recent,
		'profile'    => $profile,
		'band'       => $band,
		'crew_shown' => count($register),
		'crew_total' => $total_rows
	);

	if ($mode === 'register')
	{
		$out['trend']    = $trend;
		$out['register'] = $register;
	}

	echo json_encode($out);

?>
