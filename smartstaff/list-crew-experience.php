<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');
	include(dirname(__FILE__) . '/crew-hours-lib.php');
	include_once('resolve-call-contact.php');   /* goat_is_boss_call_name() — unguarded file: include_once ONLY */

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — the staffing planner's experience data
	/* (BRIEF-staffing-plans-a §2.2).
	/*
	/* For a list of crew and ONE call, returns per person:
	/*
	/*   current_run_h   hours that count toward probation TODAY
	/*                   (goat_crew_hours_live_current_h — 0 when dormant)
	/*   is_new          current_run_h < GOAT_PROBATION_HOURS (D2, D12). Decided
	/*                   HERE, never in Python, so there is one 144 in the system.
	/*   all_time_h      every recorded hour, across every run (D11)
	/*   past_boss_3y    distinct DEDICATED Crew Boss calls confirmed on in the
	/*                   last 3 years (D20, D23, D24) — goat_is_boss_call_name(),
	/*                   the Crew Hub contact rule. call_crew_map.is_call_boss
	/*                   (the per-call Set Boss flag) is NOT read.
	/*   last_shift_iso  end of the last run, or null
	/*   excluded        1 for placeholder accounts (Casper) — the planner drops them
	/*
	/* plus whether the call itself is a Crew Boss call.
	/*
	/* Every hour figure comes from goat_crew_hours_build() — the same library the
	/* Hours tab and the Probation lane use — so "new" here and a person's Hours
	/* tab meter always agree.
	/*
	/* Request: POST, JSON body { "call_id": int, "user_ids": [int, ...] }
	/*          (max 1500 ids). ?force=1 recomputes every requested id.
	/*
	/* Gate: admin only (D17).
	/*
	/* PHP 5.x — array(), no ??, no short arrays, tabs. PDO via goat_pdo().
	*/

	if (goat_user_cohort() !== 'admin')
	{
		goat_json_error(403, 'Admin only');
		exit;
	}

	/* Bump whenever eligibility or the boss rule changes, so a day cache
	   written under the old rules is never served.
	     1  staffing plans stage A — 22 Oct 2026 */
	if (!defined('GOAT_EXPERIENCE_RULES'))
		define('GOAT_EXPERIENCE_RULES', 1);

	if (!defined('GOAT_EXPERIENCE_BOSS_YEARS'))
		define('GOAT_EXPERIENCE_BOSS_YEARS', 3);

	if (!defined('GOAT_EXPERIENCE_MAX_IDS'))
		define('GOAT_EXPERIENCE_MAX_IDS', 1500);

	/* ---- parse body ---- */

	$raw     = file_get_contents('php://input');
	$payload = json_decode($raw, true);

	if (!is_array($payload))
	{
		goat_json_error(400, 'Invalid or missing JSON body');
		exit;
	}

	$call_id = isset($payload['call_id']) ? (int) $payload['call_id'] : 0;

	if ($call_id <= 0)
	{
		goat_json_error(400, 'call_id required');
		exit;
	}

	if (!isset($payload['user_ids']) || !is_array($payload['user_ids']))
	{
		goat_json_error(400, 'user_ids must be a list');
		exit;
	}

	$ids  = array();
	$seen = array();

	foreach ($payload['user_ids'] as $v)
	{
		$id = (int) $v;

		if ($id <= 0 || isset($seen[$id]))
			continue;

		$seen[$id] = 1;
		$ids[] = $id;
	}

	sort($ids);

	if (count($ids) > GOAT_EXPERIENCE_MAX_IDS)
	{
		goat_json_error(400, 'Too many user_ids (max ' . GOAT_EXPERIENCE_MAX_IDS . ')');
		exit;
	}

	$force = isset($_GET['force']) && (string) $_GET['force'] === '1';

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		goat_json_error(503, 'Database handle unavailable');
		exit;
	}

	$t0        = microtime(true);
	$today_ts  = strtotime('today');                         /* local midnight */
	$today_iso = date('Y-m-d', $today_ts);

	/* ---- the call ---- */

	try
	{
		$cs = $pdo->prepare('SELECT id, call_name FROM calls WHERE id = :id');
		$cs->execute(array(':id' => $call_id));
		$call_row = $cs->fetch(PDO::FETCH_ASSOC);
		$cs->closeCursor();
	}
	catch (PDOException $e)
	{
		error_log('list-crew-experience: call: ' . $e->getMessage());
		goat_json_error(500, 'call query failed');
		exit;
	}

	if (!$call_row)
	{
		goat_json_error(404, 'Unknown call');
		exit;
	}

	/* The RAW stored name, as call-supervision.php passes it. */
	$call_out = array(
		'call_id'      => (int) $call_row['id'],
		'call_name'    => (string) $call_row['call_name'],
		'is_boss_call' => goat_is_boss_call_name((string) $call_row['call_name']) ? 1 : 0
	);

	/*
	/* ── DAY CACHE (§2.3) ──────────────────────────────────────────────────────
	/*
	/* The list-probation-progress.php saved-result pattern, but PER USER: two
	/* calls planned on the same day share most of their candidate pool, so the
	/* second Plan is mostly cache hits.
	/*
	/* Same directory as the probation file — four levels up from this file is
	/* the account home on both boxes (/home/smartst), outside every web root.
	/* The name carries a hash of THIS directory so test and prod never read
	/* each other's file. 0600: it is per-person hours.
	/*
	/* Valid for one calendar day under one rules version. Hours keyed today show
	/* tomorrow — the same trade-off as the Probation lane.
	/*
	/* Concurrent writers can lose each other's new entries. Harmless: those
	/* people are recomputed on the next request. No locking, deliberately.
	/*
	/* A cache that cannot be read or written is never an error: compute live and
	/* say so in `cache`.
	*/
	$cache_dir  = dirname(dirname(dirname(dirname(__FILE__)))) . '/goat-cache';
	$cache_file = $cache_dir . '/experience-' . substr(md5(dirname(__FILE__)), 0, 12) . '.json';

	$cached = array();                         /* uid (string) => entry, today's, current rules */

	if (is_readable($cache_file))
	{
		$saved = json_decode((string) @file_get_contents($cache_file), true);

		if (is_array($saved) && isset($saved['as_at'], $saved['rules'], $saved['users'])
		    && $saved['as_at'] === $today_iso
		    && (int) $saved['rules'] === GOAT_EXPERIENCE_RULES
		    && is_array($saved['users']))
		{
			$cached = $saved['users'];
		}
	}

	$users   = array();                        /* uid => entry, the response */
	$missing = array();

	for ($i = 0; $i < count($ids); $i++)
	{
		$k = (string) $ids[$i];

		if (!$force && isset($cached[$k]) && is_array($cached[$k]))
			$users[$k] = $cached[$k];
		else
			$missing[] = $ids[$i];
	}

	$hit_users      = count($users);
	$computed_users = count($missing);

	$t_rows  = 0.0;
	$t_build = 0.0;
	$t_boss  = 0.0;

	/*
	/* Finish one person: build, keep the summary, drop the rows.
	/* is_new is decided here (D2, D12): a dormant run reads 0, so a returner
	/* after an 18-month gap is new.
	*/
	function goat_experience_flush($uid, $rows, $today_iso, &$users)
	{
		if ($uid === null)
			return;

		$built = goat_crew_hours_build($rows);
		$cur   = goat_crew_hours_live_current_h($built, $today_iso);

		$last = null;
		if ($built['current_run'] !== null)
			$last = $built['runs'][$built['current_run']]['end_date_iso'];

		$users[(string) $uid] = array(
			'current_run_h'  => round($cur, 2),
			'is_new'         => ($cur < GOAT_PROBATION_HOURS) ? 1 : 0,
			'all_time_h'     => round($built['totals']['recorded_h'], 2),
			'past_boss_3y'   => 0,
			'last_shift_iso' => $last,
			'excluded'       => 0
		);
	}

	if (count($missing))
	{
		$id_list = implode(',', array_map('intval', $missing));

		/*
		/* KEEP IN SYNC with list-probation-progress.php's rows query: same
		/* eligibility, or the planner and the Probation lane disagree about who
		/* is new.
		*/
		$sql = "
			SELECT
				ccm.userID      AS user_id,
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

		/* Past boss: confirmed on a call in the last 3 years. The boss test is
		   goat_is_boss_call_name() in PHP, not a SQL LIKE, so its exclusions
		   (cancelled, the two working "boss" calls) apply exactly as for Crew
		   Hub. Buffered: a few thousand short rows at most. */
		$sql_boss = "
			SELECT ccm.userID AS user_id, ccm.callID AS call_id, c.call_name AS call_name
			FROM call_crew_map ccm
			JOIN calls    c ON c.id = ccm.callID
			JOIN bookings b ON b.id = c.bookingID
			WHERE ccm.status   = 5
			  AND b.hidden     = 0
			  AND c.start_date >= :from3y
			  AND c.start_date <= :today
			  AND ccm.userID IN (%s)
		";

		try
		{
			/* 1. hours. Unbuffered for THIS statement only; restored straight
			   after. Nothing else runs on the handle while the cursor is open. */
			$tr = microtime(true);
			$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

			$stmt = $pdo->prepare(sprintf($sql, $id_list));
			$stmt->execute(array(':today' => $today_ts));

			$uid  = null;
			$rows = array();

			while ($r = $stmt->fetch(PDO::FETCH_ASSOC))
			{
				$ruid = (int) $r['user_id'];

				if ($uid === null || $ruid !== $uid)
				{
					$tb = microtime(true);
					goat_experience_flush($uid, $rows, $today_iso, $users);
					$t_build += microtime(true) - $tb;

					$uid  = $ruid;
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
			goat_experience_flush($uid, $rows, $today_iso, $users);
			$t_build += microtime(true) - $tb;
			$rows = array();

			$stmt->closeCursor();
			$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
			$t_rows = (microtime(true) - $tr) - $t_build;

			/* A requested id with no rows still appears: new, nothing recorded. */
			for ($i = 0; $i < count($missing); $i++)
			{
				$k = (string) $missing[$i];

				if (!isset($users[$k]))
				{
					$users[$k] = array(
						'current_run_h'  => 0.0,
						'is_new'         => 1,
						'all_time_h'     => 0.0,
						'past_boss_3y'   => 0,
						'last_shift_iso' => null,
						'excluded'       => 0
					);
				}
			}

			/* 2. past boss */
			$tbo = microtime(true);
			$from3y_ts = strtotime(goat_add_months_clamped($today_iso, -12 * GOAT_EXPERIENCE_BOSS_YEARS));

			$bstmt = $pdo->prepare(sprintf($sql_boss, $id_list));
			$bstmt->execute(array(':from3y' => $from3y_ts, ':today' => $today_ts));

			$boss_calls = array();             /* uid => array(callID => 1) */

			while ($r = $bstmt->fetch(PDO::FETCH_ASSOC))
			{
				if (!goat_is_boss_call_name((string) $r['call_name']))
					continue;

				$boss_calls[(string) (int) $r['user_id']][(int) $r['call_id']] = 1;
			}
			$bstmt->closeCursor();

			foreach ($boss_calls as $k => $set)
			{
				if (isset($users[$k]))
					$users[$k]['past_boss_3y'] = count($set);
			}

			$t_boss = microtime(true) - $tbo;
		}
		catch (PDOException $e)
		{
			error_log('list-crew-experience: ' . $e->getMessage());
			goat_json_error(500, 'experience query failed');
			exit;
		}
	}

	/* Placeholder accounts — applied on every response, cached or not, so a
	   change to the list never waits for tomorrow. */
	$excluded_ids = goat_probation_excluded_user_ids();

	foreach ($users as $k => $u)
		$users[$k]['excluded'] = in_array((int) $k, $excluded_ids) ? 1 : 0;

	/* ---- save: merge the computed ones in, temp file + rename ---- */

	$stored = 0;

	if (count($missing))
	{
		$merged = $cached;

		for ($i = 0; $i < count($missing); $i++)
		{
			$k = (string) $missing[$i];
			$merged[$k] = $users[$k];
		}

		$save = json_encode(array(
			'as_at' => $today_iso,
			'rules' => GOAT_EXPERIENCE_RULES,
			'users' => (object) $merged
		));

		if ($save !== false && (is_dir($cache_dir) || @mkdir($cache_dir, 0700)) && is_writable($cache_dir))
		{
			$tmp = $cache_file . '.' . getmypid() . '.tmp';

			if (@file_put_contents($tmp, $save) !== false)
			{
				@chmod($tmp, 0600);
				$stored = @rename($tmp, $cache_file) ? 1 : 0;
				if (!$stored)
					@unlink($tmp);
			}
		}

		if (!$stored)
			error_log('list-crew-experience: could not save cache to ' . $cache_dir);
	}

	/* Response in id order — deterministic. */
	$out = array();
	for ($i = 0; $i < count($ids); $i++)
		$out[(string) $ids[$i]] = $users[(string) $ids[$i]];

	$constants = goat_crew_hours_constants();
	$constants['boss_years'] = GOAT_EXPERIENCE_BOSS_YEARS;

	$elapsed_ms = (int) round((microtime(true) - $t0) * 1000);

	$result = array(
		'ok'           => true,
		'as_at'        => $today_iso,
		'generated_at' => date('Y-m-d\TH:i:s'),
		'rules'        => GOAT_EXPERIENCE_RULES,
		'constants'    => $constants,
		'call'         => $call_out,
		'users'        => (object) $out,
		'cache'        => array(
			'hit_users'      => $hit_users,
			'computed_users' => $computed_users,
			'stored'         => $stored,
			'forced'         => $force ? 1 : 0
		),
		'elapsed_ms'   => $elapsed_ms,
		'timing'       => array(
			'rows_ms'  => max(0, (int) round($t_rows * 1000)),
			'build_ms' => (int) round($t_build * 1000),
			'boss_ms'  => (int) round($t_boss * 1000)
		)
	);

	$body = json_encode($result);

	if ($body === false)
	{
		goat_json_error(500, 'could not encode response');
		exit;
	}

	echo $body;

?>
