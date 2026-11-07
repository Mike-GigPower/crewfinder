<?php

	/*
	/* Cases for mct_unbooked_ids() in my-call-times.php — which live
	/* submissions go into the `unbooked` list.
	/*
	/*     php test-my-call-times-unbooked.php
	/*
	/* CLI only. Includes my-call-times.php with MCT_FUNCTIONS_ONLY, so it
	/* touches no database, includes no global.php and runs none of the
	/* endpoint — safe to leave beside the endpoints and run at deploy, on the
	/* test-crew-hours.php precedent.
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

	define('MCT_FUNCTIONS_ONLY', true);
	include(dirname(__FILE__) . '/my-call-times.php');

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

	/* One live submission row, as goat_time_submissions_for_call() returns it. */
	function t_sub($id, $uid, $noShow)
	{
		$s = array('id' => $id, 'userID' => $uid, 'on_time' => '', 'off_time' => '',
		           'covering_for' => 0);

		if ($noShow !== null)
		{
			$s['no_show'] = $noShow;
		}

		return $s;
	}

	/* The 7 Nov 2026 shape on call 900000: 9734 booked and matched; 298 a
	/* confirmed No-show (status 8, so not in the status-5 roster, and their
	/* live row is the report); 5925 a genuine unbooked row the boss added
	/* by EIN. mysql_fetch_assoc gives strings, so no_show is '1' / '0'. */

	$subByUser = array(
		9734 => t_sub(12, 9734, '0'),
		298  => t_sub(31, 298,  '1'),
		5925 => t_sub(40, 5925, '0')
	);
	$matched = array(9734 => true);

	$ids = mct_unbooked_ids($subByUser, $matched);

	t_ok('confirmed No-show (status 8, no_show 1) is not listed',
	     !in_array(298, $ids, true), 'got ' . json_encode($ids));
	t_ok('genuine unbooked EIN row (no_show 0) is still listed',
	     in_array(5925, $ids, true), 'got ' . json_encode($ids));
	t_ok('booked crew are never listed', !in_array(9734, $ids, true), 'got ' . json_encode($ids));
	t_ok('exactly the one genuine row', $ids === array(5925), 'got ' . json_encode($ids));

	/* Any other status: an int no_show 1 on someone not in the roster at all. */
	$ids = mct_unbooked_ids(array(77 => t_sub(41, 77, 1)), array());
	t_ok('a No-show row is not listed whatever the status', $ids === array(), 'got ' . json_encode($ids));

	/* Before the migration there is no no_show key: unchanged behaviour. */
	$ids = mct_unbooked_ids(array(5925 => t_sub(40, 5925, null)), array());
	t_ok('pre-migration row (no no_show column) is still listed', $ids === array(5925), 'got ' . json_encode($ids));

	echo "\n$passed passed, $failed failed\n";
	exit($failed > 0 ? 1 : 0);

?>
