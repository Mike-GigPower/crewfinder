<?php

	/*
	/* Benchmark for crew-hours-lib.php — CLI only, no database, writes nothing.
	/*
	/*     php bench-crew-hours.php
	/*
	/* Builds a synthetic population shaped like the probation list on test
	/* (522 people, ~100k shifts, a long tail of heavy veterans) and times each
	/* stage, so the 10.6 s "build_ms" seen on test on 7 Oct 2026 can be pinned
	/* on PHP itself (this script will be slow too) or on the web request's
	/* CPU limit (this script will be fast). Kept (27 Oct 2026):
/* list-probation-progress.php cites its 2.85 s result. Re-run it before
/* tuning the calculation, and after any PHP version change on the box.
	*/

	if (php_sapi_name() !== 'cli') { echo "CLI only.\n"; exit(1); }

	date_default_timezone_set('Australia/Melbourne');
	include(dirname(__FILE__) . '/crew-hours-lib.php');

	mt_srand(7);

	echo 'PHP ' . PHP_VERSION . "\n";

	/* 522 people; shift counts skewed: most small, a few in the thousands. */
	$people = array();
	$total  = 0;
	for ($u = 0; $u < 522; $u++)
	{
		$n = (int) (12 + pow(mt_rand(0, 1000) / 1000, 3) * 700);
		$people[$u] = $n;
		$total += $n;
	}

	$times = array('08:00:00', '09:30:00', '14:00:00', '18:00:00', '22:00:00');

	function bench_rows($n, $times)
	{
		$rows = array();
		$d = mktime(0, 0, 0, 1, 1, 2012) + mt_rand(0, 3000) * 86400;
		for ($i = 0; $i < $n; $i++)
		{
			$d += mt_rand(1, 6) * 86400;
			$on = $times[mt_rand(0, 4)];
			$rows[] = array(
				'call_id' => mt_rand(1, 40000), 'booking_id' => 1,
				'call_name' => 'Load In', 'booking_name' => 'Booking',
				'start_date' => (string) strtotime('today', $d), 'start_time' => $on,
				'est_length' => (string) mt_rand(4, 8),
				'on' => $on, 'off' => sprintf('%02d:%02d:00', (substr($on, 0, 2) + mt_rand(4, 8)) % 24, mt_rand(0, 3) * 15),
				'break' => (mt_rand(0, 3) ? '' : '00:30'), 'break_night' => ''
			);
		}
		return $rows;
	}

	$gen = 0.0; $rowt = 0.0; $build = 0.0;
	$today = date('Y-m-d');
	$groups = array();

	for ($u = 0; $u < 522; $u++)
	{
		$t = microtime(true);
		$rows = bench_rows($people[$u], $times);
		$gen += microtime(true) - $t;

		$t = microtime(true);
		for ($i = 0; $i < count($rows); $i++)
			goat_crew_hours_row($rows[$i]);
		$rowt += microtime(true) - $t;

		$t = microtime(true);
		$b = goat_crew_hours_build($rows);
		$g = goat_crew_hours_lane_group($b, $today);
		$build += microtime(true) - $t;

		if ($g !== null) $groups[$g] = isset($groups[$g]) ? $groups[$g] + 1 : 1;
		unset($rows, $b);
	}

	printf("generate          %6d ms  (%d rows)\n", $gen * 1000, $total);
	printf("row() only        %6d ms  (%.1f us/row)\n", $rowt * 1000, $rowt * 1e6 / $total);
	printf("build+lane (list) %6d ms  (%.1f us/row)   <- compare with build_ms on test\n", $build * 1000, $build * 1e6 / $total);

	printf("peak memory       %6.1f MB\n", memory_get_peak_usage(true) / 1048576);

?>
