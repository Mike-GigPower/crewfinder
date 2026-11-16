<?php

	/*
	/* Fixture harness for goat_perf_window() (perf-week-lib.php).
	/*
	/*     php test-perf-week.php
	/*
	/* Checks the window against an INDEPENDENT construction: the Monday of
	/* each week is found with mktime() at noon (noon never falls in a DST
	/* gap), and its key is date('o') . '-W' . date('W'). The lib walks
	/* strtotime() relative strings from midnight instead, so the two only
	/* agree if the lib has the boundaries right.
	/*
	/* Pinned "today" values: a Monday, a Sunday, a midweek day in the first
	/* ISO week of a year, and the weeks either side of Melbourne's DST
	/* changes (first Sunday of October, first Sunday of April). Each runs in
	/* Australia/Melbourne and in the server's own default zone.
	/*
	/* CLI only. No database, no global.php. Exit 0 = all passed, 1 = a failure.
	*/

	if (php_sapi_name() !== 'cli')
	{
		header('Content-Type: text/plain');
		echo "CLI only.\n";
		exit(1);
	}

	include('perf-week-lib.php');

	$fail = 0;
	$pass = 0;

	function tpw_check($ok, $label)
	{
		global $fail, $pass;
		if ($ok) { $pass++; return; }
		$fail++;
		echo "FAIL: " . $label . "\n";
	}

	/* Monday (noon) of the ISO week containing $y-$m-$d (noon), stepped $k weeks. */
	function tpw_monday_noon($y, $m, $d, $k)
	{
		$t   = mktime(12, 0, 0, $m, $d, $y);
		$dow = (int) date('N', $t);
		return mktime(12, 0, 0, (int) date('n', $t), (int) date('j', $t) - ($dow - 1) + 7 * $k, (int) date('Y', $t));
	}

	$days = array(
		array(2026, 11, 16, 'a Monday'),
		array(2026, 11, 15, 'a Sunday'),
		array(2026,  1,  7, 'first ISO week of 2026 (Wed)'),
		array(2027,  1,  1, '1 Jan 2027 (Fri, ISO week 53 of 2026)'),
		array(2026, 10,  5, 'Monday after DST starts (4 Oct 2026)'),
		array(2026, 10,  4, 'DST-start Sunday'),
		array(2026,  4,  6, 'Monday after DST ends (5 Apr 2026)'),
		array(2026,  4,  5, 'DST-end Sunday')
	);

	$zones = array('Australia/Melbourne', date_default_timezone_get());

	foreach ($zones as $zone)
	{
		date_default_timezone_set($zone);

		foreach ($days as $d)
		{
			$today = mktime(0, 0, 0, $d[1], $d[2], $d[0]);
			$tag   = $zone . ' ' . $d[3];

			foreach (array(52, 1, 104) as $weeks)
			{
				$w = goat_perf_window($weeks, $today);
				$span = $weeks * 2;

				tpw_check($w['weeks'] === $weeks, $tag . ": weeks=" . $weeks);
				tpw_check($w['span'] === $span, $tag . ": span");
				tpw_check($w['req_first'] === $weeks, $tag . ": req_first");
				tpw_check(count($w['week_keys']) === $span, $tag . ": key count");
				tpw_check(count($w['week_index']) === $span, $tag . ": keys unique");

				/* last bucket = the week BEFORE the one containing today */
				for ($i = 0; $i < $span; $i++)
				{
					$mon = tpw_monday_noon($d[0], $d[1], $d[2], $i - $span);
					$exp = date('o', $mon) . '-W' . date('W', $mon);
					if ($w['week_keys'][$i] !== $exp)
					{
						tpw_check(false, $tag . " weeks=" . $weeks . ": key[" . $i . "] " . $w['week_keys'][$i] . " != " . $exp);
						break;
					}
				}

				/* range_start = midnight Monday of the first bucket */
				$m0 = tpw_monday_noon($d[0], $d[1], $d[2], -$span);
				$exp_start = mktime(0, 0, 0, (int) date('n', $m0), (int) date('j', $m0), (int) date('Y', $m0));
				tpw_check($w['range_start'] === $exp_start, $tag . " weeks=" . $weeks . ": range_start");

				/* range_end = one second before midnight Monday of THIS week */
				$m1 = tpw_monday_noon($d[0], $d[1], $d[2], 0);
				$exp_end = mktime(0, 0, 0, (int) date('n', $m1), (int) date('j', $m1), (int) date('Y', $m1)) - 1;
				tpw_check($w['range_end'] === $exp_end, $tag . " weeks=" . $weeks . ": range_end");
			}
		}
	}

	/* clamp: out of range -> 52, exactly as get-performance.php */
	foreach (array(0, -3, 105, 'abc', null) as $bad)
	{
		$w = goat_perf_window($bad, mktime(0, 0, 0, 11, 16, 2026));
		tpw_check($w['weeks'] === 52, "clamp " . var_export($bad, true) . " -> 52");
	}

	echo $pass . " passed, " . $fail . " failed\n";
	exit($fail > 0 ? 1 : 0);
