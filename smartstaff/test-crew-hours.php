<?php

	/*
	/* Cases for crew-hours-lib.php (BRIEF-crew-hours-probation.md §3.3).
	/*
	/*     php test-crew-hours.php
	/*
	/* CLI only. Touches no database, includes no global.php, prints plain text —
	/* safe to leave beside the endpoints and run at deploy, on the
	/* test-perf-split.php precedent.
	/*
	/* Exit status 0 = every case passed, 1 = at least one failed.
	/*
	/* PHP 5.x — no ??, no short array syntax, tabs.
	*/

	if (php_sapi_name() !== 'cli')
	{
		header('Content-Type: text/plain');
		echo "CLI only.\n";
		exit(1);
	}

	/* start_date is local midnight in Melbourne; build fixtures on that clock. */
	date_default_timezone_set('Australia/Melbourne');

	include(dirname(__FILE__) . '/crew-hours-lib.php');

	$passed = 0;
	$failed = 0;

	function t_ok($name, $cond, $detail)
	{
		global $passed, $failed;

		if ($cond)
		{
			$passed++;
			echo "PASS  $name\n";
		}
		else
		{
			$failed++;
			echo "FAIL  $name" . ($detail !== '' ? "  — $detail" : '') . "\n";
		}
	}

	function t_near($a, $b)
	{
		return ($a !== null) && abs((double) $a - (double) $b) < 0.0001;
	}

	/* One raw row. $date 'Y-m-d'; $extra overrides any key. */
	function t_row($date, $on, $off, $est, $extra)
	{
		static $id = 1000;
		$id++;

		$p = explode('-', $date);

		$r = array(
			'call_id'      => $id,
			'booking_id'   => 1,
			'call_name'    => 'Call ' . $id,
			'booking_name' => 'Booking',
			'start_date'   => mktime(0, 0, 0, (int) $p[1], (int) $p[2], (int) $p[0]),
			'start_time'   => '08:00:00',
			'est_length'   => $est,
			'on'           => $on,
			'off'          => $off,
			'break'        => '',
			'break_night'  => ''
		);

		foreach ($extra as $k => $v)
			$r[$k] = $v;

		return $r;
	}

	/* $n shifts of $h hours each, one a day from $from. */
	function t_block($from, $n, $h)
	{
		$rows = array();
		$t = strtotime($from . ' 12:00:00');
		$off = sprintf('%02d:00:00', 8 + $h);

		for ($i = 0; $i < $n; $i++)
		{
			$rows[] = t_row(date('Y-m-d', strtotime('+' . $i . ' days', $t)), '08:00:00', $off, (double) $h, array());
		}

		return $rows;
	}


	/* 1. Ran over: est 4, worked 6 */
	$b = goat_crew_hours_build(array(t_row('2026-03-01', '08:00:00', '14:00:00', 4, array())));
	t_ok('1  ran over 2h on a 4h call',
		t_near($b['totals']['over_h'], 2) && $b['totals']['over_n'] === 1 && t_near($b['totals']['recorded_h'], 6),
		json_encode($b['totals']));

	/* 2. Overnight 22:00 -> 03:00 */
	$b = goat_crew_hours_build(array(t_row('2026-03-01', '22:00:00', '03:00:00', 5, array())));
	t_ok('2  overnight 22:00-03:00 is 5h',
		t_near($b['shifts'][0]['recorded_h'], 5) && t_near($b['totals']['over_h'], 0) && $b['totals']['over_n'] === 0,
		json_encode($b['shifts'][0]));

	/* 3. Malformed break skipped, row counts */
	$b = goat_crew_hours_build(array(t_row('2026-03-01', '08:00:00', '14:00:00', 6, array('break' => '00:75'))));
	t_ok('3  malformed break 00:75 skipped and counted',
		t_near($b['shifts'][0]['recorded_h'], 6) && $b['data_quality']['malformed_breaks'] === 1,
		json_encode($b['data_quality']));

	/* 3b. Valid breaks both columns subtract */
	$b = goat_crew_hours_build(array(t_row('2026-03-01', '08:00:00', '18:00:00', 10,
		array('break' => '00:30:00', 'break_night' => '00:15'))));
	t_ok('3b day + night breaks subtract (10h - 45m)',
		t_near($b['shifts'][0]['recorded_h'], 9.25) && $b['data_quality']['malformed_breaks'] === 0,
		json_encode($b['shifts'][0]));

	/* 4. Ambiguous off */
	$b = goat_crew_hours_build(array(t_row('2026-03-01', '10:00:00', '00:00:00', 4, array())));
	t_ok('4  ambiguous off excluded and counted',
		count($b['shifts']) === 0 && $b['data_quality']['ambiguous_off_rows'] === 1 && $b['current_run'] === null,
		json_encode($b['data_quality']));

	/* 5. Awaiting times */
	$b = goat_crew_hours_build(array(
		t_row('2026-03-01', '08:00:00', '12:00:00', 4, array()),
		t_row('2026-03-02', '00:00:00', '00:00:00', 5, array())));
	t_ok('5  awaiting row counted, not in recorded figures',
		$b['totals']['awaiting_n'] === 1 && t_near($b['totals']['awaiting_scheduled_h'], 5)
		&& t_near($b['totals']['recorded_h'], 4) && t_near($b['totals']['scheduled_h'], 4)
		&& count($b['shifts']) === 2 && $b['shifts'][1]['state'] === 'awaiting'
		&& $b['shifts'][1]['run_index'] === null,
		json_encode($b['totals']));

	/* 6. at_minimum */
	$b = goat_crew_hours_build(array(
		t_row('2026-03-01', '14:00:00', '18:00:00', 2.5, array()),
		t_row('2026-03-02', '14:00:00', '18:00:00', 4,   array())));
	t_ok('6a span 4h on a 2.5h call is a top-up of 1.5h',
		$b['shifts'][0]['at_minimum'] === 1 && t_near($b['shifts'][0]['topup_est_h'], 1.5),
		json_encode($b['shifts'][0]));
	t_ok('6b span 4h on a 4h call is not a top-up',
		$b['shifts'][1]['at_minimum'] === 0 && t_near($b['totals']['topup_est_h'], 1.5),
		json_encode($b['shifts'][1]));

	/* 6c. No estimate: never a top-up, kept out of over/early, counted */
	$b = goat_crew_hours_build(array(t_row('2026-03-01', '14:00:00', '18:00:00', 0, array())));
	t_ok('6c est 0: not a top-up, not "ran over", counted as no_estimate',
		$b['shifts'][0]['at_minimum'] === 0 && t_near($b['totals']['over_h'], 0)
		&& $b['data_quality']['no_estimate_rows'] === 1 && t_near($b['totals']['recorded_h'], 4),
		json_encode($b['totals']));

	/* 6d. A padded row is not an overrun */
	$b = goat_crew_hours_build(array(t_row('2026-03-01', '14:00:00', '18:00:00', 2.5, array())));
	t_ok('6d padded 2.5h call: top-up 1.5h, NOT ran over',
		t_near($b['totals']['over_h'], 0) && $b['totals']['over_n'] === 0
		&& t_near($b['totals']['topup_est_h'], 1.5) && t_near($b['totals']['recorded_h'], 4)
		&& t_near($b['totals']['scheduled_h'], 2.5),
		json_encode($b['totals']));

	/* 7. Finished early */
	$b = goat_crew_hours_build(array(t_row('2026-03-01', '08:00:00', '14:00:00', 8, array())));
	t_ok('7  est 8, worked 6: early 2h',
		t_near($b['totals']['early_h'], 2) && $b['totals']['early_n'] === 1 && $b['totals']['over_n'] === 0,
		json_encode($b['totals']));

	/* 8. Within tolerance */
	$b = goat_crew_hours_build(array(t_row('2026-03-01', '08:00:00', '12:15:00', 4, array())));
	t_ok('8  4.25 on a 4h call: hours counted, shift not counted',
		t_near($b['totals']['over_h'], 0.25) && $b['totals']['over_n'] === 0,
		json_encode($b['totals']));

	/* 9. Exactly 144 */
	$b = goat_crew_hours_build(t_block('2026-01-01', 18, 8));        /* 18 x 8 = 144 */
	$run = $b['runs'][0];
	t_ok('9  crosses on the row that lands exactly on 144',
		$run['crossed'] !== null && $run['crossed']['date_iso'] === '2026-01-18'
		&& t_near($run['crossed']['cumulative_h'], 144),
		json_encode($run['crossed']));

	/* 9b. Fractional accumulation still lands on 144 */
	$rows = array();
	for ($i = 0; $i < 576; $i++)                                       /* 576 x 0.25h */
		$rows[] = t_row(date('Y-m-d', strtotime('2026-01-01 12:00:00 +' . $i . ' days')), '08:00:00', '08:15:00', 0.25, array());
	$b = goat_crew_hours_build($rows);
	t_ok('9b 576 quarter-hours accumulate to a crossing on the last row',
		$b['runs'][0]['crossed'] !== null && $b['runs'][0]['shifts'] === 576
		&& $b['runs'][0]['crossed']['date_iso'] === $b['runs'][0]['end_date_iso'],
		json_encode($b['runs'][0]['crossed']));

	/* 10. Mid-shift crossing: 17 x 8 = 136, then a 10h shift */
	$rows = t_block('2026-01-01', 17, 8);
	$rows[] = t_row('2026-02-01', '08:00:00', '18:00:00', 10, array());
	$b = goat_crew_hours_build($rows);
	t_ok('10 136 + 10 crosses on that row at 146',
		$b['runs'][0]['crossed']['date_iso'] === '2026-02-01'
		&& t_near($b['runs'][0]['crossed']['cumulative_h'], 146),
		json_encode($b['runs'][0]['crossed']));

	/* 10b. Shifts out of order still accumulate in date order */
	$b2 = goat_crew_hours_build(array_reverse($rows));
	t_ok('10b input order does not matter',
		$b2['runs'][0]['crossed']['date_iso'] === '2026-02-01' && $b2['shifts'][0]['date_iso'] === '2026-01-01',
		json_encode($b2['runs'][0]['crossed']));

	/* 11. Gap of 17 months 30 days: no restart */
	$b = goat_crew_hours_build(array(
		t_row('2024-01-15', '08:00:00', '12:00:00', 4, array()),
		t_row('2025-07-14', '08:00:00', '12:00:00', 4, array())));
	t_ok('11 gap 2024-01-15 -> 2025-07-14 does not restart',
		count($b['runs']) === 1 && t_near($b['runs'][0]['total_h'], 8),
		json_encode($b['runs']));

	/* 12. Gap of exactly 18 months: restart */
	$b = goat_crew_hours_build(array(
		t_row('2024-01-15', '08:00:00', '12:00:00', 4, array()),
		t_row('2025-07-15', '08:00:00', '12:00:00', 4, array())));
	t_ok('12 gap 2024-01-15 -> 2025-07-15 restarts, cumulative resets',
		count($b['runs']) === 2 && t_near($b['runs'][1]['total_h'], 4)
		&& t_near($b['shifts'][1]['cumulative_h'], 4) && $b['current_run'] === 1
		&& $b['runs'][1]['gap_before_months'] === 18,
		json_encode($b['runs']));

	/* 13. Month-end clamping */
	t_ok('13a 2024-08-31 + 18 = 2026-02-28',
		goat_add_months_clamped('2024-08-31', 18) === '2026-02-28', goat_add_months_clamped('2024-08-31', 18));
	t_ok('13b 2023-08-31 + 18 = 2025-02-28',
		goat_add_months_clamped('2023-08-31', 18) === '2025-02-28', goat_add_months_clamped('2023-08-31', 18));
	t_ok('13c 2022-08-31 + 18 = 2024-02-29 (leap year)',
		goat_add_months_clamped('2022-08-31', 18) === '2024-02-29', goat_add_months_clamped('2022-08-31', 18));
	t_ok('13d 2025-12-15 + 18 = 2027-06-15 (year roll)',
		goat_add_months_clamped('2025-12-15', 18) === '2027-06-15', goat_add_months_clamped('2025-12-15', 18));

	/* 13e. Clamping decides a real restart: 2024-08-31 -> 2026-02-28 restarts */
	$b = goat_crew_hours_build(array(
		t_row('2024-08-31', '08:00:00', '12:00:00', 4, array()),
		t_row('2026-02-28', '08:00:00', '12:00:00', 4, array())));
	t_ok('13e 2024-08-31 -> 2026-02-28 restarts (clamped boundary)',
		count($b['runs']) === 2, json_encode($b['runs']));

	/* 14. Lane groups */
	$today = '2026-10-07';

	$b = goat_crew_hours_build(t_block('2026-09-10', 18, 8));          /* crosses 2026-09-27 */
	t_ok('14a crossed 10 days ago -> pay_rate_due',
		goat_crew_hours_lane_group($b, $today) === 'pay_rate_due', goat_crew_hours_lane_group($b, $today));

	$b = goat_crew_hours_build(t_block('2026-08-20', 18, 8));          /* crosses 2026-09-06 = 31 days */
	t_ok('14b crossed 31 days ago -> still pay_rate_due (no recency window, 8 Oct 2026)',
		goat_crew_hours_lane_group($b, $today) === 'pay_rate_due', $b['runs'][0]['crossed']['date_iso']);

	$rows = t_block('2025-06-01', 18, 8);                               /* crosses mid-June 2025 */
	$rows[] = t_row('2026-09-30', '08:00:00', '12:00:00', 4, array());  /* still working: live */
	$b = goat_crew_hours_build($rows);
	t_ok('14h crossed over a year ago, still working -> pay_rate_due (a missed promotion stays listed)',
		goat_crew_hours_lane_group($b, $today) === 'pay_rate_due' && count($b['runs']) === 1,
		json_encode($b['runs']));

	$b = goat_crew_hours_build(t_block('2024-01-01', 18, 8));           /* crossed, then nothing for 18+ months */
	t_ok('14i crossed but dormant 18+ months -> null (next shift starts a new count)',
		goat_crew_hours_lane_group($b, $today) === null, $b['runs'][0]['end_date_iso']);

	$g = goat_probation_grade_ids();
	t_ok('14j base grade is exactly paygrade 10 (T1 by day, T2 by night)',
		count($g) === 1 && $g[0] === 10, json_encode($g));

	$b = goat_crew_hours_build(t_block('2026-09-01', 13, 10));         /* 130h */
	t_ok('14c at 130h -> approaching',
		goat_crew_hours_lane_group($b, $today) === 'approaching', goat_crew_hours_lane_group($b, $today));

	$rows = t_block('2026-09-01', 11, 10);                              /* 110 */
	$rows[] = t_row('2026-09-20', '08:00:00', '17:54:00', 9.9, array()); /* +9.9 = 119.9 */
	$b = goat_crew_hours_build($rows);
	t_ok('14d at 119.9h -> null',
		goat_crew_hours_lane_group($b, $today) === null && t_near($b['runs'][0]['total_h'], 119.9),
		$b['runs'][0]['total_h']);

	$rows = t_block('2020-01-01', 18, 8);                               /* earlier run crossed */
	$rows = array_merge($rows, t_block('2026-09-01', 5, 8));            /* current run 40h */
	$b = goat_crew_hours_build($rows);
	t_ok('14e earlier run crossed, current run at 40h -> null',
		count($b['runs']) === 2 && goat_crew_hours_lane_group($b, $today) === null,
		json_encode($b['runs'][1]));

	$b = goat_crew_hours_build(t_block('2024-01-01', 13, 10));          /* 130h, dormant */
	t_ok('14f 130h but last shift 18+ months ago -> null (next shift restarts)',
		goat_crew_hours_lane_group($b, $today) === null, $b['runs'][0]['end_date_iso']);

	$b = goat_crew_hours_build(array());
	t_ok('14g nothing recorded -> null, no runs',
		goat_crew_hours_lane_group($b, $today) === null && $b['current_run'] === null && count($b['runs']) === 0, '');

	/* 15. Output rounding is at the edge only */
	$b = goat_crew_hours_round(goat_crew_hours_build(array(
		t_row('2026-03-01', '08:00:00', '08:20:00', 0.3333333, array()))));
	t_ok('15 rounding: 20 minutes -> 0.33, internal fields dropped',
		$b['shifts'][0]['recorded_h'] === 0.33 && !isset($b['shifts'][0]['has_estimate'])
		&& !isset($b['shifts'][0]['malformed_breaks']),
		json_encode($b['shifts'][0]));


	echo "\n$passed passed, $failed failed\n";
	exit($failed > 0 ? 1 : 0);

?>
