<?php

	/*
	/* Performance week window, shared (BRIEF-performance-cost-slice-a.md §4.2).
	/*
	/* goat_perf_window() is an EXACT port of the window block in
	/* get-performance.php (the "Window" section, l. 239-300 at 5d7690f):
	/* weeks clamped 1..104 (default 52), span = weeks * 2, the anchor is the
	/* last COMPLETE week, and boundaries are walked with strtotime() relative
	/* strings because Melbourne observes DST (a week is not always 604800 s).
	/*
	/* get-performance.php is NOT changed in this slice and still has its own
	/* copy; slice A2 makes it include this file. Until then the two must be
	/* kept identical by hand -- test-perf-week.php checks this file's output
	/* against an independent stepping of date('o') . '-W' . date('W').
	/*
	/* goat_perf_week_key_lib() is a copy of goat_perf_week_key() under a NEW
	/* name: get-performance.php declares the old name without a guard, so
	/* reusing it here would fatal with "cannot redeclare" the day A2 includes
	/* this file. A2 merges them.
	/*
	/* $today is optional so the test harness can pin "today". Production
	/* callers pass nothing and get strtotime('today'), as get-performance.php.
	/*
	/* No database, no global.php. PHP 5.6: array(), no null-coalescing.
	*/

	if (!function_exists('goat_perf_week_key_lib'))
	{
		function goat_perf_week_key_lib($ts)
		{
			return date('o', (int) $ts) . '-W' . date('W', (int) $ts);
		}
	}

	if (!function_exists('goat_perf_window'))
	{
		function goat_perf_window($weeks, $today = null)
		{
			$w = 52;
			$requested = (int) $weeks;
			if ($requested >= 1 && $requested <= 104)
				$w = $requested;

			$span = $w * 2;                    /* requested window + comparison */

			if ($today === null)
				$today = strtotime('today');

			$dow = (int) date('N', $today);    /* 1 = Mon .. 7 = Sun */

			/* Guarded rather than relying on strtotime('-0 days') on a Monday. */
			$this_week_start = ($dow > 1)
			                 ? strtotime('-' . ($dow - 1) . ' days', $today)
			                 : $today;

			/* The window ends at the last COMPLETE week (see get-performance.php). */
			$anchor_week_start = strtotime('-1 week', $this_week_start);

			$range_start = strtotime('-' . ($span - 1) . ' weeks', $anchor_week_start);
			$range_end   = strtotime('+1 week', $anchor_week_start) - 1;

			$week_keys  = array();
			$week_index = array();
			$cursor     = $range_start;

			for ($i = 0; $i < $span; $i++)
			{
				$key              = goat_perf_week_key_lib($cursor);
				$week_keys[$i]    = $key;
				$week_index[$key] = $i;
				$cursor           = strtotime('+1 week', $cursor);
			}

			return array(
				'weeks'       => $w,
				'span'        => $span,
				'range_start' => (int) $range_start,
				'range_end'   => (int) $range_end,
				'week_keys'   => $week_keys,
				'week_index'  => $week_index,
				'req_first'   => $w      /* index of the first bucket in the REQUESTED window */
			);
		}
	}
