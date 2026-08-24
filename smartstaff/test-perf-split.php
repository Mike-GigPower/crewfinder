<?php

	/*
	/* Fixture harness for goat_perf_split_day_night().
	/*
	/*     php test-perf-split.php [path/to/estimator_split_fixtures.json]
	/*
	/* Runs the SAME table THE GOAT's test_estimator_calc.py runs against the
	/* Python engine. The splitter is the only logic the two implementations
	/* share, so it is the only place they can drift — and a shared table is what
	/* stops them, rather than a rule saying they must not (BRIEF Decision 3).
	/*
	/* CLI only. It touches no database, includes no global.php and prints plain
	/* text, so it is safe to leave beside the endpoint and run at deploy, on the
	/* test-induction-catalogue.sh precedent.
	/*
	/* 'duration' cases are absolute-time and Python-only: this band prices bare
	/* wall-clock times off a crew sheet and has no timezone (BRIEF Decision 1).
	/* They are SKIPPED and the skip count is printed — a harness that quietly
	/* ran a subset would read as full coverage.
	/*
	/* Exit status 0 = every runnable case passed, 1 = at least one failed.
	*/

	if (php_sapi_name() !== 'cli')
	{
		header('Content-Type: text/plain');
		echo "CLI only.\n";
		exit(1);
	}

	include('perf-split-lib.php');

	$path = isset($argv[1]) ? $argv[1] : null;

	if ($path === null)
	{
		$candidates = array(
			dirname(__FILE__) . '/estimator_split_fixtures.json',
			dirname(__FILE__) . '/../estimator_split_fixtures.json'
		);

		for ($i = 0; $i < count($candidates); $i++)
		{
			if (file_exists($candidates[$i]))
			{
				$path = $candidates[$i];
				break;
			}
		}
	}

	if ($path === null || !file_exists($path))
	{
		echo "fixture table not found — pass its path as the first argument\n";
		exit(1);
	}

	$raw = file_get_contents($path);
	$doc = json_decode($raw, true);

	if (!is_array($doc) || !isset($doc['cases']))
	{
		echo "fixture table at $path is not readable JSON\n";
		exit(1);
	}

	$passed  = 0;
	$failed  = 0;
	$skipped = 0;

	$cases = $doc['cases'];

	for ($c = 0; $c < count($cases); $c++)
	{
		$case = $cases[$c];
		$name = $case['name'];

		if ($case['mode'] !== 'wallclock' || !isset($case['startSec']) || $case['startSec'] === null)
		{
			$skipped++;
			echo "SKIP  $name (mode " . $case['mode'] . ")\n";
			continue;
		}

		$got = goat_perf_split_day_night(
			$case['startSec'], $case['endSec'],
			$case['dayStartSec'], $case['nightStartSec']);

		if ($got === null)
		{
			$failed++;
			echo "FAIL  $name — splitter hit its 96-segment guard\n";
			continue;
		}

		$want = $case['expected'];
		$problem = null;

		if (count($got) !== count($want))
		{
			$problem = 'expected ' . count($want) . ' segments, got ' . count($got);
		}
		else
		{
			for ($i = 0; $i < count($want); $i++)
			{
				$w = $want[$i];

				if ((int) $got[$i]['day_offset'] !== (int) $w[0])
				{
					$problem = "segment $i day offset: expected " . $w[0]
					         . ', got ' . $got[$i]['day_offset'];
					break;
				}

				if (abs($got[$i]['day_hrs'] - $w[1]) > 0.000000001)
				{
					$problem = "segment $i day hours: expected " . $w[1]
					         . ', got ' . $got[$i]['day_hrs'];
					break;
				}

				if (abs($got[$i]['night_hrs'] - $w[2]) > 0.000000001)
				{
					$problem = "segment $i night hours: expected " . $w[2]
					         . ', got ' . $got[$i]['night_hrs'];
					break;
				}
			}
		}

		if ($problem === null)
		{
			$passed++;
			echo "ok    $name\n";
		}
		else
		{
			$failed++;
			echo "FAIL  $name — $problem\n";
		}
	}

	echo "\n$passed passed, $failed failed, $skipped skipped (duration-mode, Python-only)\n";

	exit($failed === 0 ? 0 : 1);

?>
