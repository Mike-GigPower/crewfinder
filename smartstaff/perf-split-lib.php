<?php

	/*
	/* Day/night splitter — shared, and tested against a fixture table THE GOAT's
	/* Python engine runs too.
	/*
	/* On the licence-catalogue-lib.php pattern: one implementation, included by
	/* its consumers, so a second consumer cannot acquire a second copy of the
	/* rule. Today the consumer is get-performance.php's uninvoiced band; the
	/* forward-pricing work will want the same cut.
	/*
	/* Deliberately has NO database access and NO global.php dependency, so
	/* test-perf-split.php can include it and run the fixtures on their own:
	/*
	/*     php test-perf-split.php
	/*
	/* PHP 5.x — no ??, no short array syntax, no scalar type hints.
	*/

	if (!function_exists('goat_perf_split_day_night'))
	{

		/*
		/* Cut a worked span into day and night segments at the 08:00 / 20:00
		/* boundaries — the ONLY part of the Estimator's pricing engine the uninvoiced
		/* band needs, and a direct port of splitIntoDayNightByDate() from that engine's
		/* calc.ts. THE GOAT's estimator_calc.py carries the same algorithm; both run
		/* estimator_split_fixtures.json, which is what stops them drifting.
		/*
		/* Everything is seconds from midnight of the call date, so a span running
		/* past midnight simply has an end beyond 86400 — exactly how the caller
		/* already handles an overnight `off`. This is WALL-CLOCK arithmetic with no
		/* timezone anywhere, which is correct for this band: `on` and `off` are bare
		/* times a crew boss wrote down reading a watch, and on the October
		/* transition night "on 22:00, off 09:00" means eleven hours to the payroll
		/* even though only ten real ones passed. The business bills and pays from
		/* the sheet. (The Estimator's own forward pricing uses absolute time
		/* instead — a different consumer wanting a different clock, not a bug in
		/* either. See BRIEF Decision 1.)
		/*
		/* Two details are load-bearing and both come straight from calc.ts:
		/*
		/*   * a segment's period is decided at its OWN START, not across it. The
		/*     loop cuts at every boundary, so a segment never straddles one.
		/*   * boundary candidates are STRICTLY GREATER than the cursor. A span
		/*     starting exactly at 08:00 does not treat 08:00 as its next boundary;
		/*     the next cut is 20:00. Using >= would spin on a zero-length segment.
		/*
		/* Returns an array of array('day_offset', 'day_hrs', 'night_hrs'), or NULL
		/* if the span needed more than 96 segments. calc.ts breaks out of that guard
		/* silently and returns a short answer; here NULL forces the caller to drop
		/* the row and count it, because a silently truncated span under-states
		/* revenue and nothing downstream would ever notice. At up to three
		/* boundaries a day, 96 segments is about 32 days — a crew row cannot reach
		/* it, so a NULL means the input is wrong, not the span long.
		*/
		function goat_perf_split_day_night($start_sec, $end_sec, $day_start_sec, $night_start_sec)
		{
			$out = array();

			if ($end_sec <= $start_sec)
				return $out;

			$guard  = 0;
			$cursor = $start_sec;

			while ($cursor < $end_sec)
			{
				$guard++;
				if ($guard > 96)
					return null;

				$day_index = (int) floor($cursor / 86400);
				$midnight  = $day_index * 86400;

				$day_start     = $midnight + $day_start_sec;
				$night_start   = $midnight + $night_start_sec;
				$next_midnight = $midnight + 86400;

				/* Next boundary strictly after the cursor. next_midnight always
				   qualifies, so the candidate set is never empty. */
				$next = $next_midnight;

				if ($day_start > $cursor && $day_start < $next)
					$next = $day_start;
				if ($night_start > $cursor && $night_start < $next)
					$next = $night_start;

				$seg_end = ($end_sec < $next) ? $end_sec : $next;

				if ($seg_end <= $cursor)
					break;                         /* unreachable — see above */

				$hrs    = ($seg_end - $cursor) / 3600.0;
				$is_day = ($cursor >= $day_start && $cursor < $night_start);

				$out[] = array(
					'day_offset' => $day_index,
					'day_hrs'    => $is_day ? $hrs : 0.0,
					'night_hrs'  => $is_day ? 0.0 : $hrs
				);

				$cursor = $seg_end;
			}

			return $out;
		}

	}

?>
