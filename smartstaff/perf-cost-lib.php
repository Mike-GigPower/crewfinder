<?php

	/*
	/* Performance crew-pay margin: the arithmetic, with no database
	/* (BRIEF-performance-cost-slice-a.md §3).
	/*
	/* get-performance-cost.php reads three sets from SmartStaff and hands them
	/* here; test-perf-cost.php hands it fixtures. Keeping the matching rules in
	/* one pure function is what lets them be tested without a database.
	/*
	/* INPUTS
	/*   $win    goat_perf_window() result (perf-week-lib.php)
	/*   $calls  call_id => array('start' => int, 'invoice' => int,
	/*                            'booking' => int, 'name' => string)
	/*           every non-hidden call whose start_date is inside the span
	/*   $pay    call_id => array('pay' => float, 'charge' => float,
	/*                            'rows' => int, 'null_calc' => int)
	/*           pay rows for those calls; absent = no pay rows
	/*   $lines  list of array('invoice' => int, 'call' => int,
	/*                         'start' => int, 'revenue' => float)
	/*           every NON-SPACER line on an invoice a span call carries;
	/*           revenue already worked out with the Xero formula
	/*   $cap    most exception rows to return (50)
	/*
	/* RULES (§3.2-3.4)
	/*   - An invoice is MATCHED when it has at least one line AND at least one
	/*     span call carrying it has pay rows.
	/*   - Margin takes a matched invoice's FULL revenue (all its lines,
	/*     including hand-added callID 0 lines) and FULL pay (all its calls).
	/*   - Pay is bucketed by its call's start. A line with a call in the set is
	/*     bucketed by that call's start; otherwise by its own start. Anything
	/*     landing outside the span is dropped.
	/*   - margin_pct is null when revenue is 0 -- never divided.
	/*
	/* Money is summed unrounded and rounded to 2 dp ONCE, at the end.
	/*
	/* PHP 5.6: array(), no null-coalescing, no short arrays.
	*/

	if (!function_exists('goat_perf_cost_empty_week'))
	{
		function goat_perf_cost_empty_week($key)
		{
			return array(
				'key'                 => $key,
				'revenue'             => 0.0,
				'pay'                 => 0.0,
				'margin'              => 0.0,
				'margin_pct'          => null,
				'calls_matched'       => 0,
				'calls_active'        => 0,
				'awaiting_invoice'    => array('calls' => 0, 'pay' => 0.0),
				'not_on_invoice'      => array('calls' => 0, 'pay' => 0.0),
				'hand_billed_unlined' => array('calls' => 0, 'pay' => 0.0),
				'invoiced_no_pay'     => array('invoices' => 0, 'revenue' => 0.0)
			);
		}
	}

	if (!function_exists('goat_perf_cost_pct'))
	{
		/* null when there is no revenue: a 0% or -inf% would read as a result. */
		function goat_perf_cost_pct($margin, $revenue)
		{
			if (abs((float) $revenue) < 0.005)
				return null;
			return round(((float) $margin / (float) $revenue) * 100.0, 1);
		}
	}

	if (!function_exists('goat_perf_cost_idx'))
	{
		/* Bucket index for a timestamp, or -1 when it falls outside the span. */
		function goat_perf_cost_idx($win, $ts)
		{
			$ts = (int) $ts;
			if ($ts < $win['range_start'] || $ts > $win['range_end'])
				return -1;
			$key = goat_perf_week_key_lib($ts);
			return isset($win['week_index'][$key]) ? (int) $win['week_index'][$key] : -1;
		}
	}

	if (!function_exists('goat_perf_cost_compute'))
	{
		function goat_perf_cost_compute($win, $calls, $pay, $lines, $cap)
		{
			$span = (int) $win['span'];

			/* ---- 1. what each invoice has ---- */

			$invHasLine   = array();   /* invoice => true: at least one non-spacer line */
			$invHandLine  = array();   /* invoice => true: has a callID 0 line          */
			$lineCalls    = array();   /* call    => true: a line carries this callID   */

			foreach ($lines as $ln)
			{
				$inv = (int) $ln['invoice'];
				$cid = (int) $ln['call'];
				$invHasLine[$inv] = true;
				if ($cid > 0)
					$lineCalls[$cid] = true;
				else
					$invHandLine[$inv] = true;
			}

			$invPaid = array();        /* invoice => true: a span call on it has pay rows */

			foreach ($calls as $cid => $c)
			{
				$inv = (int) $c['invoice'];
				if ($inv > 0 && isset($pay[$cid]) && (int) $pay[$cid]['rows'] > 0)
					$invPaid[$inv] = true;
			}

			$matched = array();
			foreach ($invHasLine as $inv => $unused)
			{
				if (isset($invPaid[$inv]))
					$matched[$inv] = true;
			}

			/* ---- 2. dense weeks ---- */

			$weeks = array();
			for ($i = 0; $i < $span; $i++)
				$weeks[$i] = goat_perf_cost_empty_week($win['week_keys'][$i]);

			$dq = array(
				'pay_rows'                => 0,
				'null_calculated_rows'    => 0,
				'lines_call_outside_span' => 0,
				'lines_no_call'           => 0,
				'lines_no_call_revenue'   => 0.0
			);

			$exceptions = array();

			/* ---- 3. calls: pay, coverage, exceptions ---- */

			foreach ($calls as $cid => $c)
			{
				$cid = (int) $cid;
				$idx = goat_perf_cost_idx($win, $c['start']);
				if ($idx < 0)
					continue;

				$p       = isset($pay[$cid]) ? $pay[$cid] : null;
				$hasPay  = ($p !== null && (int) $p['rows'] > 0);
				$hasLine = isset($lineCalls[$cid]);
				$inv     = (int) $c['invoice'];
				$amt     = $hasPay ? (float) $p['pay'] : 0.0;

				if ($p !== null)
				{
					$dq['pay_rows']             += (int) $p['rows'];
					$dq['null_calculated_rows'] += (int) $p['null_calc'];
				}

				if ($hasPay || $hasLine)
					$weeks[$idx]['calls_active']++;

				/*
				/* calls_matched counts ACTIVE calls only (pay or a line), so it
				/* is always a subset of calls_active -- the coverage line reads
				/* "matched of active". A call on a matched invoice with neither
				/* (cancelled, never crewed) would otherwise push matched above
				/* active (seen on test, 16 Nov: 157 of 150). Its pay is 0
				/* either way, so the margin is unchanged.
				*/
				if ($inv > 0 && isset($matched[$inv]) && ($hasPay || $hasLine))
				{
					$weeks[$idx]['calls_matched']++;
					$weeks[$idx]['pay'] += $amt;
				}

				if (!$hasPay)
					continue;

				if ($inv <= 0)
				{
					$weeks[$idx]['awaiting_invoice']['calls']++;
					$weeks[$idx]['awaiting_invoice']['pay'] += $amt;
				}
				else if (!$hasLine)
				{
					if (isset($invHandLine[$inv]))
					{
						$weeks[$idx]['hand_billed_unlined']['calls']++;
						$weeks[$idx]['hand_billed_unlined']['pay'] += $amt;
					}
					else
					{
						$weeks[$idx]['not_on_invoice']['calls']++;
						$weeks[$idx]['not_on_invoice']['pay'] += $amt;

						if ($idx >= (int) $win['req_first'])
						{
							$exceptions[] = array(
								'call_id'       => $cid,
								'booking_id'    => (int) $c['booking'],
								'invoice_id'    => $inv,
								'call_name'     => (string) $c['name'],
								'call_date'     => date('Y-m-d', (int) $c['start']),
								'start'         => (int) $c['start'],
								'pay'           => $amt,
								'charge_on_pay' => (float) $p['charge']
							);
						}
					}
				}
			}

			/* ---- 4. lines: revenue ---- */

			$noPayFirst = array();     /* invoice => earliest bucket, for invoiced_no_pay */

			foreach ($lines as $ln)
			{
				$inv = (int) $ln['invoice'];
				$lc  = (int) $ln['call'];
				$rev = (float) $ln['revenue'];

				if ($lc > 0 && isset($calls[$lc]))
					$ts = (int) $calls[$lc]['start'];
				else
					$ts = (int) $ln['start'];

				$idx = goat_perf_cost_idx($win, $ts);
				if ($idx < 0)
					continue;

				if ($lc > 0 && !isset($calls[$lc]))
					$dq['lines_call_outside_span']++;

				if ($lc <= 0)
				{
					$dq['lines_no_call']++;
					$dq['lines_no_call_revenue'] += $rev;
				}

				if (isset($matched[$inv]))
				{
					$weeks[$idx]['revenue'] += $rev;
				}
				else if (!isset($invPaid[$inv]))
				{
					$weeks[$idx]['invoiced_no_pay']['revenue'] += $rev;
					if (!isset($noPayFirst[$inv]) || $idx < $noPayFirst[$inv])
						$noPayFirst[$inv] = $idx;
				}
			}

			foreach ($noPayFirst as $inv => $idx)
				$weeks[$idx]['invoiced_no_pay']['invoices']++;

			/* ---- 5. margins, totals, rounding (once) ---- */

			$tot = array(
				'current' => array('revenue' => 0.0, 'pay' => 0.0, 'calls_matched' => 0, 'calls_active' => 0),
				'prior'   => array('revenue' => 0.0, 'pay' => 0.0, 'calls_matched' => 0, 'calls_active' => 0)
			);

			for ($i = 0; $i < $span; $i++)
			{
				$w = $weeks[$i];
				$t = ($i >= (int) $win['req_first']) ? 'current' : 'prior';
				$tot[$t]['revenue']       += $w['revenue'];
				$tot[$t]['pay']           += $w['pay'];
				$tot[$t]['calls_matched'] += $w['calls_matched'];
				$tot[$t]['calls_active']  += $w['calls_active'];

				$margin = $w['revenue'] - $w['pay'];
				$weeks[$i]['margin_pct'] = goat_perf_cost_pct($margin, $w['revenue']);
				$weeks[$i]['revenue']    = round($w['revenue'], 2);
				$weeks[$i]['pay']        = round($w['pay'], 2);
				$weeks[$i]['margin']     = round($margin, 2);
				$weeks[$i]['awaiting_invoice']['pay']       = round($w['awaiting_invoice']['pay'], 2);
				$weeks[$i]['not_on_invoice']['pay']         = round($w['not_on_invoice']['pay'], 2);
				$weeks[$i]['hand_billed_unlined']['pay']    = round($w['hand_billed_unlined']['pay'], 2);
				$weeks[$i]['invoiced_no_pay']['revenue']    = round($w['invoiced_no_pay']['revenue'], 2);
			}

			foreach (array('current', 'prior') as $t)
			{
				$m = $tot[$t]['revenue'] - $tot[$t]['pay'];
				$tot[$t]['margin_pct'] = goat_perf_cost_pct($m, $tot[$t]['revenue']);
				$tot[$t]['margin']     = round($m, 2);
				$tot[$t]['revenue']    = round($tot[$t]['revenue'], 2);
				$tot[$t]['pay']        = round($tot[$t]['pay'], 2);
			}

			/* Newest first; call id breaks ties so the order is stable. */
			usort($exceptions, 'goat_perf_cost_exc_cmp');

			$truncated = false;
			if (count($exceptions) > (int) $cap)
			{
				$exceptions = array_slice($exceptions, 0, (int) $cap);
				$truncated  = true;
			}

			for ($i = 0; $i < count($exceptions); $i++)
			{
				unset($exceptions[$i]['start']);
				$exceptions[$i]['pay']           = round($exceptions[$i]['pay'], 2);
				$exceptions[$i]['charge_on_pay'] = round($exceptions[$i]['charge_on_pay'], 2);
			}

			$dq['lines_no_call_revenue'] = round($dq['lines_no_call_revenue'], 2);

			return array(
				'weeks'        => $weeks,
				'totals'       => $tot,
				'exceptions'   => array('not_on_invoice' => $exceptions, 'truncated' => $truncated),
				'data_quality' => $dq
			);
		}
	}

	if (!function_exists('goat_perf_cost_exc_cmp'))
	{
		function goat_perf_cost_exc_cmp($a, $b)
		{
			if ($a['start'] !== $b['start'])
				return ($a['start'] > $b['start']) ? -1 : 1;
			if ($a['call_id'] === $b['call_id'])
				return 0;
			return ($a['call_id'] > $b['call_id']) ? -1 : 1;
		}
	}
