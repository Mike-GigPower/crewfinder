<?php

	/*
	/* Crew hours — worked vs scheduled, and the 144-hour probation runs.
	/*
	/* ONE implementation, included by both consumers:
	/*
	/*   get-crew-hours.php          the Hours tab on the crew record
	/*   list-probation-progress.php the Today › Operations "Probation" lane
	/*
	/* so the tab and the lane cannot disagree about who crossed 144 hours, or
	/* when. A second code path that reaches the same answer is a defect
	/* (BRIEF-crew-hours-probation.md §3.2).
	/*
	/* On the perf-split-lib.php pattern: NO database access and NO global.php
	/* dependency, so test-crew-hours.php can include it and run its cases on
	/* their own:
	/*
	/*     php test-crew-hours.php
	/*
	/* WHAT "RECORDED" MEANS. The times keyed into call_crew_map after a shift,
	/* minus breaks. When a shift runs long these are the real times. When it is
	/* short, SmartStaff writes a fabricated finish so the row adds up to the
	/* four-hour minimum (ADDENDUM-2-boss-checkin-ops-decisions §3) — so recorded
	/* is the PAID figure, top-ups included. That is also what Employment Hero
	/* counts toward probation (Rich, 6 Oct 2026), and EH is the reference.
	/*
	/* PHP 5.x — no ??, no short array syntax, no scalar type hints, tabs.
	*/

	include_once(dirname(__FILE__) . '/perf-split-lib.php');   /* goat_perf_hms_to_seconds() */


	/* ──────────────────────────────────────────────────────────────────────────
	/* Constants — defined once, here (design decision 6). define() rather than
	/* const so the guard works on any PHP version.
	/* ────────────────────────────────────────────────────────────────────────── */

	if (!defined('GOAT_PROBATION_HOURS'))       define('GOAT_PROBATION_HOURS', 144);
	if (!defined('GOAT_PROBATION_GAP_MONTHS'))  define('GOAT_PROBATION_GAP_MONTHS', 18);
	if (!defined('GOAT_PROBATION_APPROACH_H'))  define('GOAT_PROBATION_APPROACH_H', 120);
	if (!defined('GOAT_PROBATION_RECENT_DAYS')) define('GOAT_PROBATION_RECENT_DAYS', 30);
	if (!defined('GOAT_HOURS_TOLERANCE_H'))     define('GOAT_HOURS_TOLERANCE_H', 0.25);

	/* The four-hour minimum, in seconds — what a padded row's span equals. */
	if (!defined('GOAT_HOURS_MINIMUM_SEC'))     define('GOAT_HOURS_MINIMUM_SEC', 14400);


	if (!function_exists('goat_crew_hours_build'))
	{

		/*
		/* 'Y-m-d' plus N calendar months, with the DAY CLAMPED to the end of the
		/* target month: 2024-08-31 + 18 = 2026-02-28, not 2026-03-03.
		/*
		/* strtotime('+18 months') is NOT used, deliberately: it overflows the
		/* month (31 Aug + 18 months lands in March), which would move the restart
		/* boundary by up to three days at every month end.
		/*
		/* Returns null for a value that is not 'Y-m-d'.
		*/
		function goat_add_months_clamped($date_iso, $months)
		{
			if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date_iso, $m))
				return null;

			$y = (int) $m[1];
			$mo = (int) $m[2];
			$d = (int) $m[3];

			$total = ($y * 12) + ($mo - 1) + (int) $months;
			$ty = (int) floor($total / 12);
			$tm = ($total - ($ty * 12)) + 1;

			/* Days in the target month. date('t') on the 1st at noon — the hour
			   keeps a DST transition from moving the day. */
			$dim = (int) date('t', mktime(12, 0, 0, $tm, 1, $ty));

			if ($d > $dim)
				$d = $dim;

			return sprintf('%04d-%02d-%02d', $ty, $tm, $d);
		}

		/*
		/* Whole calendar months from $a to $b ('Y-m-d', $a <= $b). Display only —
		/* "after a 20-month gap" — never used to decide a restart.
		*/
		function goat_months_between($a, $b)
		{
			if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $a, $x) ||
			    !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $b, $y))
				return null;

			$n = (((int) $y[1] - (int) $x[1]) * 12) + ((int) $y[2] - (int) $x[2]);

			if ((int) $y[3] < (int) $x[3])
				$n--;

			return ($n < 0) ? 0 : $n;
		}

		/* Whole days from $a to $b ('Y-m-d'). Noon anchors keep DST out of it. */
		function goat_days_between($a, $b)
		{
			$ta = strtotime($a . ' 12:00:00');
			$tb = strtotime($b . ' 12:00:00');

			if ($ta === false || $tb === false)
				return null;

			return (int) round(($tb - $ta) / 86400);
		}

		/* 'Y-m-d' minus N days. */
		function goat_date_minus_days($date_iso, $days)
		{
			$t = strtotime($date_iso . ' 12:00:00');
			return date('Y-m-d', strtotime('-' . (int) $days . ' days', $t));
		}


		/*
		/* Memoised wrappers. The list endpoint runs ~100k rows through the
		/* library, and the same few hundred time strings and call dates repeat
		/* endlessly ('08:00:00', '00:00:00', one midnight per call date). The
		/* parse is the shared goat_perf_hms_to_seconds() — unchanged, never
		/* copied — only its RESULT is cached. Values are pure functions of
		/* their input, so a cache cannot change an answer.
		*/
		function goat_crew_hours_hms($val)
		{
			static $cache = array();
			$key = (string) $val;

			if (!array_key_exists($key, $cache))
			{
				if (count($cache) > 5000)
					$cache = array();          /* bound it; cheap to refill */
				$cache[$key] = goat_perf_hms_to_seconds($val);
			}

			return $cache[$key];
		}

		function goat_crew_hours_date_iso($start_date)
		{
			static $cache = array();

			if (!isset($cache[$start_date]))
			{
				if (count($cache) > 20000)
					$cache = array();
				$cache[$start_date] = date('Y-m-d', $start_date);   /* one clock: PHP's */
			}

			return $cache[$start_date];
		}

		/* goat_add_months_clamped(), memoised for the run walk. */
		function goat_crew_hours_restart_from($date_iso)
		{
			static $cache = array();

			if (!isset($cache[$date_iso]))
			{
				if (count($cache) > 20000)
					$cache = array();
				$cache[$date_iso] = goat_add_months_clamped($date_iso, GOAT_PROBATION_GAP_MONTHS);
			}

			return $cache[$date_iso];
		}


		/*
		/* One raw call_crew_map row -> its per-row figures (§2.1–2.3).
		/*
		/* $raw keys: call_id, booking_id, call_name, booking_name, start_date,
		/*            start_time, est_length, on, off, break, break_night
		/*
		/* ELIGIBILITY (status 5, booking not hidden, dated today or earlier) is
		/* the SQL's job; this function decides only recorded / awaiting /
		/* excluded. An excluded row comes back as array('excluded' => <counter>)
		/* — counted by the caller, never coerced into a value.
		*/
		function goat_crew_hours_row($raw)
		{
			$start_date = isset($raw['start_date']) ? (int) $raw['start_date'] : 0;

			if ($start_date <= 0)
				return array('excluded' => 'unparseable_time_rows');

			$est = isset($raw['est_length']) ? (double) $raw['est_length'] : 0.0;

			$row = array(
				'call_id'      => isset($raw['call_id'])    ? (int) $raw['call_id']    : 0,
				'booking_id'   => isset($raw['booking_id']) ? (int) $raw['booking_id'] : 0,
				'call_name'    => isset($raw['call_name'])    ? (string) $raw['call_name']    : '',
				'booking_name' => isset($raw['booking_name']) ? (string) $raw['booking_name'] : '',
				'date_iso'     => goat_crew_hours_date_iso($start_date),
				'start_time'   => isset($raw['start_time']) ? trim((string) $raw['start_time']) : '',
				'state'        => 'recorded',
				'scheduled_h'  => $est,
				'has_estimate' => ($est > 0) ? 1 : 0,
				'recorded_h'   => null,
				'span_h'       => null,
				'at_minimum'   => 0,
				'topup_est_h'  => 0.0,
				'malformed_breaks' => 0,
				'run_index'    => null,
				'cumulative_h' => null,
				'source'       => 'smartstaff'
			);

			$on_raw  = isset($raw['on'])  ? trim((string) $raw['on'])  : '';
			$off_raw = isset($raw['off']) ? trim((string) $raw['off']) : '';

			/* Never keyed: awaiting times. Scheduled only. */
			if ($on_raw === '00:00:00' && $off_raw === '00:00:00')
			{
				$row['state'] = 'awaiting';
				return $row;
			}

			/*
			/* on keyed, off at midnight: EITHER a genuine midnight finish OR an
			/* off time nobody entered, and nothing on the row tells them apart.
			/* Rolling the overnight rule over it would invent a ~16-hour shift
			/* out of a missing keystroke. Same rule as get-performance.php.
			*/
			if ($off_raw === '00:00:00' && $on_raw !== '00:00:00')
				return array('excluded' => 'ambiguous_off_rows');

			$on  = goat_crew_hours_hms($on_raw);
			$off = goat_crew_hours_hms($off_raw);

			if ($on === null || $off === null)
				return array('excluded' => 'unparseable_time_rows');

			$span = $off - $on;
			if ($off < $on)
				$span += 86400;                    /* overnight */

			$worked = $span;

			/*
			/* Breaks. A malformed value ('00:75') is SKIPPED and counted — the
			/* row still counts. Coercion would turn a keying error into a
			/* slightly-wrong total nobody could find again.
			*/
			$cols = array('break', 'break_night');

			for ($i = 0; $i < count($cols); $i++)
			{
				$v = isset($raw[$cols[$i]]) ? trim((string) $raw[$cols[$i]]) : '';

				if ($v === '')
					continue;

				$s = goat_crew_hours_hms($v);

				if ($s === null)
				{
					$row['malformed_breaks']++;
					continue;
				}

				$worked -= $s;
			}

			if ($worked <= 0)
				return array('excluded' => 'nonpositive_rows');

			$row['span_h']     = $span / 3600.0;
			$row['recorded_h'] = $worked / 3600.0;

			/*
			/* A likely four-hour top-up: the SPAN (not the recorded figure) is
			/* exactly four hours and the call was booked for less. Padding is
			/* written on `off`, so the span is what carries it. A row with no
			/* estimate is never flagged — "est 0" would claim the whole four
			/* hours as padding.
			*/
			if (abs($span - GOAT_HOURS_MINIMUM_SEC) < 1 && $est > 0 && $est < 4)
			{
				$row['at_minimum']  = 1;
				$row['topup_est_h'] = 4.0 - $est;
			}

			return $row;
		}


		/* Recorded rows in run order: date, then start time, then call id. */
		function goat_crew_hours_cmp($a, $b)
		{
			if ($a['date_iso'] !== $b['date_iso'])
				return ($a['date_iso'] < $b['date_iso']) ? -1 : 1;
			if ($a['start_time'] !== $b['start_time'])
				return ($a['start_time'] < $b['start_time']) ? -1 : 1;
			if ($a['call_id'] !== $b['call_id'])
				return ($a['call_id'] < $b['call_id']) ? -1 : 1;
			return 0;
		}


		/*
		/* The whole calculation for one crew member. The ONLY function either
		/* endpoint calls.
		/*
		/* Returns:
		/*   shifts        every recorded and awaiting row, OLDEST first
		/*   runs          probation runs, oldest first (§2.4)
		/*   current_run   index of the last run, or null when nothing is recorded
		/*   totals        all-time figures (§2.2)
		/*   data_quality  the excluded-row and malformed-break counters
		/*
		/* Full precision throughout. Rounding is the endpoint's job, on output.
		*/
		function goat_crew_hours_build($raw_rows)
		{
			$dq = array(
				'ambiguous_off_rows'    => 0,
				'unparseable_time_rows' => 0,
				'nonpositive_rows'      => 0,
				'malformed_breaks'      => 0,
				'no_estimate_rows'      => 0
			);

			$totals = array(
				'scheduled_h'          => 0.0,
				'recorded_h'           => 0.0,
				'topup_est_h'          => 0.0,
				'over_h'               => 0.0,
				'over_n'               => 0,
				'early_h'              => 0.0,
				'early_n'              => 0,
				'awaiting_n'           => 0,
				'awaiting_scheduled_h' => 0.0
			);

			$recorded = array();
			$awaiting = array();

			for ($i = 0; $i < count($raw_rows); $i++)
			{
				$r = goat_crew_hours_row($raw_rows[$i]);

				if (isset($r['excluded']))
				{
					$dq[$r['excluded']]++;
					continue;
				}

				if ($r['state'] === 'awaiting')
				{
					$awaiting[] = $r;
					$totals['awaiting_n']++;
					$totals['awaiting_scheduled_h'] += $r['scheduled_h'];
					continue;
				}

				$dq['malformed_breaks'] += $r['malformed_breaks'];
				$recorded[] = $r;
			}

			usort($recorded, 'goat_crew_hours_cmp');

			/* ── runs, cumulative, crossing ─────────────────────────────────── */

			$runs = array();
			$run  = -1;
			$cum  = 0.0;
			$prev_date = null;

			for ($i = 0; $i < count($recorded); $i++)
			{
				$d = $recorded[$i]['date_iso'];

				$restart = ($prev_date === null)
				        || ($d >= goat_crew_hours_restart_from($prev_date));

				if ($restart)
				{
					$run++;
					$cum = 0.0;
					$runs[$run] = array(
						'index'             => $run,
						'start_date_iso'    => $d,
						'end_date_iso'      => $d,
						'total_h'           => 0.0,
						'shifts'            => 0,
						'gap_before_days'   => ($prev_date === null) ? null : goat_days_between($prev_date, $d),
						'gap_before_months' => ($prev_date === null) ? null : goat_months_between($prev_date, $d),
						'crossed'           => null
					);
				}

				$h    = $recorded[$i]['recorded_h'];
				$cum += $h;

				$recorded[$i]['run_index']    = $run;
				$recorded[$i]['cumulative_h'] = $cum;

				$runs[$run]['end_date_iso'] = $d;
				$runs[$run]['total_h']      = $cum;
				$runs[$run]['shifts']++;

				/* The FIRST row in the run to reach 144. 1e-9 absorbs float
				   accumulation so a run landing exactly on 144.00 crosses. */
				if ($runs[$run]['crossed'] === null && ($cum + 1e-9) >= GOAT_PROBATION_HOURS)
				{
					$runs[$run]['crossed'] = array(
						'date_iso'     => $d,
						'call_id'      => $recorded[$i]['call_id'],
						'call_name'    => $recorded[$i]['call_name'],
						'cumulative_h' => $cum
					);
				}

				$prev_date = $d;

				/* ── totals ─────────────────────────────────────────────────── */

				$totals['recorded_h']  += $h;
				$totals['topup_est_h'] += $recorded[$i]['topup_est_h'];

				/*
				/* Ran over / finished early compare like with like: only rows
				/* with an estimate. Hour sums take every difference (as Probe A
				/* measured); the COUNTS use the 15-minute tolerance, one rounding
				/* step, so rounding noise is not a "shift that ran over".
				*/
				if (!$recorded[$i]['has_estimate'])
				{
					$dq['no_estimate_rows']++;
					continue;
				}

				$sched = $recorded[$i]['scheduled_h'];
				$diff  = $h - $sched;

				$totals['scheduled_h'] += $sched;

				/*
				/* A padded row is NOT an overrun. Its finish was written to make
				/* the four-hour minimum, so "recorded minus scheduled" on it is the
				/* top-up, not time anyone stayed late. Counting it would inflate
				/* the exact figure Rich asked for. Its hours still count (toward
				/* probation, in recorded, and as topup_est_h); it just takes no
				/* part in ran-over / finished-early.
				*/
				if ($recorded[$i]['at_minimum'])
					continue;

				if ($diff > 0)
					$totals['over_h'] += $diff;
				else
					$totals['early_h'] += -$diff;

				if ($diff > GOAT_HOURS_TOLERANCE_H)
					$totals['over_n']++;
				else if ($diff < -GOAT_HOURS_TOLERANCE_H)
					$totals['early_n']++;
			}

			/* All rows, oldest first. Awaiting rows sit in date order but carry
			   no run or cumulative figure. */
			$shifts = array_merge($recorded, $awaiting);
			usort($shifts, 'goat_crew_hours_cmp');

			return array(
				'shifts'       => $shifts,
				'runs'         => array_values($runs),
				'current_run'  => count($runs) ? (count($runs) - 1) : null,
				'totals'       => $totals,
				'data_quality' => $dq
			);
		}


		/*
		/* Which lane group the CURRENT run falls in, or null (§2.5).
		/*
		/*   pay_rate_due  crossed 144 on or after today − 30 days
		/*   approaching   not crossed, total >= 120, and still LIVE
		/*
		/* "Live" means the next shift would not restart the count: today is
		/* before run end + 18 months. A run that has gone dormant is not
		/* approaching anything — the next shift starts again at zero. A recent
		/* crossing is always live, so pay_rate_due needs no such test.
		*/
		function goat_crew_hours_lane_group($built, $today_iso)
		{
			if ($built['current_run'] === null)
				return null;

			$run = $built['runs'][$built['current_run']];

			if ($run['crossed'] !== null)
			{
				$since = goat_date_minus_days($today_iso, GOAT_PROBATION_RECENT_DAYS);
				return ($run['crossed']['date_iso'] >= $since) ? 'pay_rate_due' : null;
			}

			if ($run['total_h'] + 1e-9 < GOAT_PROBATION_APPROACH_H)
				return null;

			if ($today_iso >= goat_add_months_clamped($run['end_date_iso'], GOAT_PROBATION_GAP_MONTHS))
				return null;

			return 'approaching';
		}


		/*
		/* The constants block every response carries, so the client labels its
		/* thresholds from the server rather than from a second copy.
		*/
		function goat_crew_hours_constants()
		{
			return array(
				'probation_hours' => GOAT_PROBATION_HOURS,
				'gap_months'      => GOAT_PROBATION_GAP_MONTHS,
				'approach_h'      => GOAT_PROBATION_APPROACH_H,
				'recent_days'     => GOAT_PROBATION_RECENT_DAYS,
				'tolerance_h'     => GOAT_HOURS_TOLERANCE_H
			);
		}


		/*
		/* Output rounding — 2 dp on every hour figure, applied ONCE, at the edge.
		/* Internal fields (has_estimate, malformed_breaks) are dropped from shift
		/* rows here; malformed breaks are already summed into data_quality.
		*/
		function goat_crew_hours_round($built)
		{
			$hour_keys = array('scheduled_h', 'recorded_h', 'span_h', 'topup_est_h', 'cumulative_h');

			for ($i = 0; $i < count($built['shifts']); $i++)
			{
				$s = $built['shifts'][$i];

				for ($k = 0; $k < count($hour_keys); $k++)
				{
					$key = $hour_keys[$k];
					if ($s[$key] !== null)
						$s[$key] = round($s[$key], 2);
				}

				unset($s['has_estimate']);
				unset($s['malformed_breaks']);

				$built['shifts'][$i] = $s;
			}

			for ($i = 0; $i < count($built['runs']); $i++)
			{
				$built['runs'][$i]['total_h'] = round($built['runs'][$i]['total_h'], 2);

				if ($built['runs'][$i]['crossed'] !== null)
					$built['runs'][$i]['crossed']['cumulative_h'] =
						round($built['runs'][$i]['crossed']['cumulative_h'], 2);
			}

			foreach ($built['totals'] as $k => $v)
			{
				if (is_float($v))
					$built['totals'][$k] = round($v, 2);
			}

			return $built;
		}

	}

?>
