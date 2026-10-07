<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');
	include(dirname(__FILE__) . '/crew-hours-lib.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — the Today › Operations "Probation — 144 hours" lane.
	/*
	/* Returns two lists, both about each person's CURRENT probation run:
	/*
	/*   pay_rate_due  crossed 144 hours in the last 30 days — Rich changes their
	/*                 base pay rate in Employment Hero
	/*   approaching   120 hours or more, not yet 144, run still live
	/*
	/* Every figure comes from goat_crew_hours_build() and
	/* goat_crew_hours_lane_group() in crew-hours-lib.php — the SAME functions the
	/* Hours tab's get-crew-hours.php calls — so a name in this list and that
	/* person's Hours tab always agree (BRIEF-crew-hours-probation.md §3.5).
	/*
	/* CANDIDATES. Anyone with a recorded, confirmed shift in the last 18 months.
	/* Nobody else can be in either list: without one, their next shift starts a
	/* new run at zero. Probe D on prod, 6 Oct 2026: 633 candidates, 105,787 rows
	/* across their whole histories, 2.0 s.
	/*
	/* ONE STREAMED PASS. The rows query runs UNBUFFERED, ordered by user, and is
	/* walked one person at a time: collect that person's rows, build, keep only
	/* the lane result, discard. Peak memory is one person's history (the heaviest
	/* is ~4,600 rows), not all 106k. Brief decision 9: no early-stop optimisation
	/* — the whole history is read, so a returner's run boundaries are exact.
	/*
	/* Gate: admin only, as get-crew-hours.php. A lane row opens the crew record,
	/* which is admin-only, so any other cohort would 403 on every click.
	/*
	/* PHP 5.x — array(), no ??, no short arrays, tabs. PDO via goat_pdo().
	*/

	if (goat_user_cohort() !== 'admin')
	{
		goat_json_error(403, 'Admin only');
		exit;
	}

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		goat_json_error(503, 'Database handle unavailable');
		exit;
	}

	$t0        = microtime(true);
	$today_ts  = strtotime('today');                         /* local midnight */
	$today_iso = date('Y-m-d', $today_ts);

	/*
	/* ── SAVED RESULT ──────────────────────────────────────────────────────────
	/*
	/* WHY. Measured 7 Oct 2026 on the test box: the calculation for ~100k shifts
	/* costs 2.85 s from the command line under PHP 5.6 (bench-crew-hours.php)
	/* and 10.6 s inside a web request. No code change gets that under the 3 s
	/* target, so the result is computed at most once per GOAT_PROBATION_CACHE_TTL
	/* and every request in between is a file read.
	/*
	/* The list only moves when times are keyed or the date rolls over, so a few
	/* hours' staleness costs nothing Rich would act on. ?force=1 recomputes on
	/* demand (THE GOAT's "Recalculate now").
	/*
	/* WHERE. Outside every web root: four levels up from this file is the
	/* account home on both boxes (/home/smartst), so the file is never served.
	/* It holds names and EINs, so it is written 0600. The name carries a hash
	/* of THIS directory, so test and prod — same account, different folders —
	/* can never read each other's result.
	/*
	/* A cache that cannot be read or written is never an error: the request
	/* computes live and says so in `cache`.
	*/
	if (!defined('GOAT_PROBATION_CACHE_TTL'))
		define('GOAT_PROBATION_CACHE_TTL', 3 * 3600);

	$cache_dir  = dirname(dirname(dirname(dirname(__FILE__)))) . '/goat-cache';
	$cache_file = $cache_dir . '/probation-' . substr(md5(dirname(__FILE__)), 0, 12) . '.json';
	$force      = isset($_GET['force']) && (string) $_GET['force'] === '1';

	if (!$force && is_readable($cache_file))
	{
		$saved = json_decode((string) @file_get_contents($cache_file), true);

		if (is_array($saved) && isset($saved['as_at'], $saved['generated_ts'])
		    && $saved['as_at'] === $today_iso
		    && (time() - (int) $saved['generated_ts']) < GOAT_PROBATION_CACHE_TTL)
		{
			$saved['cache'] = array(
				'hit'   => 1,
				'age_s' => time() - (int) $saved['generated_ts'],
				'ttl_s' => GOAT_PROBATION_CACHE_TTL
			);

			$body = json_encode($saved);

			if ($body !== false)
			{
				echo $body;
				exit;
			}
		}
	}

	/* 18 calendar months back, clamped — the same boundary the library uses. */
	$cand_from_iso = goat_add_months_clamped($today_iso, -GOAT_PROBATION_GAP_MONTHS);
	$cand_from_ts  = strtotime($cand_from_iso);              /* local midnight */

	/*
	/* TWO QUERIES, not one. v1 ran the candidates as an IN (derived-table)
	/* subquery inside the rows query, ordered by user, date, time and call. On
	/* test (7 Oct 2026, 522 candidates, 100,793 rows) that took 9.6 s against a
	/* 3 s target, where Probe D's candidate query alone took 2.0 s on prod.
	/*
	/*   1. candidates — Probe D's inner query, on its own, buffered
	/*   2. rows       — userID IN (<literal ints>), ORDER BY userID ONLY
	/*
	/* The ints are cast with (int) before they are joined, so the literal list
	/* is injection-safe. Ordering by user alone is all the walk needs: the
	/* library sorts each person's rows itself (goat_crew_hours_cmp), so a
	/* four-column filesort over 100k rows bought nothing.
	/*
	/* Recorded = not BOTH on and off at midnight. Same eligibility as
	/* get-crew-hours.php: status 5, booking not hidden, dated today or earlier.
	/*
	/* `timing` in the response splits the cost (candidates / rows / build) so
	/* the next slow day says where it went instead of needing a probe.
	*/
	$sql_cand = "
		SELECT DISTINCT ccm.userID AS user_id
		FROM call_crew_map ccm
		JOIN calls    c ON c.id = ccm.callID
		JOIN bookings b ON b.id = c.bookingID
		WHERE ccm.status   = 5
		  AND b.hidden     = 0
		  AND c.start_date >= :cand_from
		  AND c.start_date <= :today
		  AND NOT (ccm.`on` = '00:00:00' AND ccm.`off` = '00:00:00')
	";

	$sql = "
		SELECT
			ccm.userID      AS user_id,
			u.firstname     AS firstname,
			u.lastname      AS lastname,
			u.ein           AS ein,
			u.active        AS active,
			c.id            AS call_id,
			c.bookingID     AS booking_id,
			c.call_name     AS call_name,
			b.name          AS booking_name,
			c.start_date    AS start_date,
			c.start_time    AS start_time,
			c.est_length    AS est_length,
			ccm.`on`        AS on_time,
			ccm.`off`       AS off_time,
			ccm.`break`     AS break_time,
			ccm.break_night AS break_night_time
		FROM call_crew_map ccm
		JOIN calls    c ON c.id = ccm.callID
		JOIN bookings b ON b.id = c.bookingID
		JOIN users    u ON u.id = ccm.userID
		WHERE ccm.status   = 5
		  AND b.hidden     = 0
		  AND c.start_date > 0
		  AND c.start_date <= :today
		  AND ccm.userID IN (%s)
		ORDER BY ccm.userID
	";

	$t_cand  = 0.0;
	$t_build = 0.0;

	$pay_rate_due = array();
	$approaching  = array();
	$candidates   = 0;
	$rows_read    = 0;

	/*
	/* Finish one person: build, classify, keep the summary, drop the rows.
	*/
	function goat_probation_flush($person, $rows, $today_iso, &$pay_rate_due, &$approaching)
	{
		if ($person === null || !count($rows))
			return;

		$built = goat_crew_hours_build($rows);
		$group = goat_crew_hours_lane_group($built, $today_iso);

		if ($group === null)
			return;

		$run = $built['runs'][$built['current_run']];

		/* "Lastname, Firstname" AS STORED (HTML-encoded) — the client decodes. */
		$name = trim((string) $person['lastname']);
		if (trim((string) $person['firstname']) !== '')
			$name .= ($name !== '' ? ', ' : '') . trim((string) $person['firstname']);

		$entry = array(
			'user_id'         => (int) $person['user_id'],
			'ein'             => (string) $person['ein'],
			'name'            => $name,
			'active'          => ((int) $person['active']) ? 1 : 0,
			'current_total_h' => round($run['total_h'], 2),
			'run_start_iso'   => $run['start_date_iso'],
			'last_shift_iso'  => $run['end_date_iso'],
			'restarted'       => ($built['current_run'] > 0) ? 1 : 0
		);

		if ($group === 'pay_rate_due')
		{
			$entry['crossed_date_iso']  = $run['crossed']['date_iso'];
			$entry['crossed_call_id']   = (int) $run['crossed']['call_id'];
			$entry['crossed_call_name'] = (string) $run['crossed']['call_name'];
			$pay_rate_due[] = $entry;
		}
		else
		{
			$entry['remaining_h'] = round(GOAT_PROBATION_HOURS - $run['total_h'], 2);
			$approaching[] = $entry;
		}
	}

	try
	{
		/* 1. candidates (buffered, a few hundred ints) */
		$cstmt = $pdo->prepare($sql_cand);
		$cstmt->execute(array(':cand_from' => $cand_from_ts, ':today' => $today_ts));

		$ids = array();
		while ($c = $cstmt->fetch(PDO::FETCH_ASSOC))
			$ids[] = (int) $c['user_id'];
		$cstmt->closeCursor();

		$t_cand = microtime(true) - $t0;

		if (!count($ids))
			$ids[] = 0;                        /* matches nobody; keeps the SQL valid */

		/* 2. rows. Unbuffered for THIS statement only; restored straight
		   after. Nothing else runs on the handle while the cursor is open. */
		$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

		$stmt = $pdo->prepare(sprintf($sql, implode(',', array_map('intval', $ids))));
		$stmt->execute(array(':today' => $today_ts));

		$person = null;
		$rows   = array();

		while ($r = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$rows_read++;
			$uid = (int) $r['user_id'];

			if ($person === null || $uid !== (int) $person['user_id'])
			{
				$tb = microtime(true);
				goat_probation_flush($person, $rows, $today_iso, $pay_rate_due, $approaching);
				$t_build += microtime(true) - $tb;

				$candidates++;
				$person = array(
					'user_id'   => $uid,
					'firstname' => $r['firstname'],
					'lastname'  => $r['lastname'],
					'ein'       => $r['ein'],
					'active'    => $r['active']
				);
				$rows = array();
			}

			$rows[] = array(
				'call_id'      => $r['call_id'],
				'booking_id'   => $r['booking_id'],
				'call_name'    => $r['call_name'],
				'booking_name' => $r['booking_name'],
				'start_date'   => $r['start_date'],
				'start_time'   => $r['start_time'],
				'est_length'   => $r['est_length'],
				'on'           => $r['on_time'],
				'off'          => $r['off_time'],
				'break'        => $r['break_time'],
				'break_night'  => $r['break_night_time']
			);
		}

		$tb = microtime(true);
		goat_probation_flush($person, $rows, $today_iso, $pay_rate_due, $approaching);
		$t_build += microtime(true) - $tb;

		$stmt->closeCursor();
		$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
	}
	catch (PDOException $e)
	{
		error_log('list-probation-progress: ' . $e->getMessage());
		goat_json_error(500, 'probation query failed');
		exit;
	}

	/* pay_rate_due: newest crossing first. approaching: most hours first. */

	function goat_probation_cmp_crossed($a, $b)
	{
		if ($a['crossed_date_iso'] === $b['crossed_date_iso'])
			return strcmp($a['name'], $b['name']);
		return ($a['crossed_date_iso'] < $b['crossed_date_iso']) ? 1 : -1;
	}

	function goat_probation_cmp_hours($a, $b)
	{
		if ($a['current_total_h'] == $b['current_total_h'])
			return strcmp($a['name'], $b['name']);
		return ($a['current_total_h'] < $b['current_total_h']) ? 1 : -1;
	}

	usort($pay_rate_due, 'goat_probation_cmp_crossed');
	usort($approaching,  'goat_probation_cmp_hours');

	$elapsed_ms = (int) round((microtime(true) - $t0) * 1000);

	$result = array(
		'ok'            => true,
		'generated_at'  => date('Y-m-d\TH:i:s'),
		'generated_ts'  => time(),
		'as_at'         => $today_iso,
		'constants'     => goat_crew_hours_constants(),
		'pay_rate_due'  => $pay_rate_due,
		'approaching'   => $approaching,
		'candidates'    => $candidates,
		'rows_read'     => $rows_read,
		'elapsed_ms'    => $elapsed_ms,
		'timing'        => array(
			'candidates_ms' => (int) round($t_cand * 1000),
			'rows_ms'       => max(0, $elapsed_ms - (int) round($t_cand * 1000) - (int) round($t_build * 1000)),
			'build_ms'      => (int) round($t_build * 1000)
		)
	);

	$body = json_encode($result);

	if ($body === false)
	{
		goat_json_error(500, 'could not encode response');
		exit;
	}

	/* Save it: temp file + rename, so a reader never sees half a file. */
	$stored = 0;

	if ((is_dir($cache_dir) || @mkdir($cache_dir, 0700)) && is_writable($cache_dir))
	{
		$tmp = $cache_file . '.' . getmypid() . '.tmp';

		if (@file_put_contents($tmp, $body) !== false)
		{
			@chmod($tmp, 0600);
			$stored = @rename($tmp, $cache_file) ? 1 : 0;
			if (!$stored)
				@unlink($tmp);
		}
	}

	if (!$stored)
		error_log('list-probation-progress: could not save result to ' . $cache_dir);

	$result['cache'] = array('hit' => 0, 'age_s' => 0, 'ttl_s' => GOAT_PROBATION_CACHE_TTL,
	                         'stored' => $stored, 'forced' => $force ? 1 : 0);
	$body = json_encode($result);

	echo $body;

?>
