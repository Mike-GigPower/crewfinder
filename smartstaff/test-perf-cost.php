<?php

	/*
	/* Fixture harness for goat_perf_cost_compute() (perf-cost-lib.php).
	/*
	/*     php test-perf-cost.php
	/*
	/* Hand-built calls, pay and lines over a pinned 2-week window (span 4),
	/* each one aimed at a rule in BRIEF-performance-cost-slice-a.md §3:
	/* invoice-level matching, hand-added lines, the three exception kinds,
	/* invoiced-with-no-pay, null margin % on an empty week, a negative week,
	/* a line whose call is outside the span, and the exception cap.
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
	include('perf-cost-lib.php');

	date_default_timezone_set('Australia/Melbourne');

	$fail = 0;
	$pass = 0;

	function tpc_eq($got, $exp, $label)
	{
		global $fail, $pass;
		if ($got === $exp) { $pass++; return; }
		$fail++;
		echo "FAIL: " . $label . " -- got " . var_export($got, true) . ", expected " . var_export($exp, true) . "\n";
	}

	/* "Today" = Monday 16 Nov 2026. The anchor is the last complete week
	/* (9-15 Nov = 2026-W46); weeks=2 gives span 4:
	/*   0 = 2026-W43 (19 Oct)  1 = 2026-W44 (26 Oct)   <- prior
	/*   2 = 2026-W45 (2 Nov)   3 = 2026-W46 (9 Nov)    <- current */
	$win = goat_perf_window(2, mktime(0, 0, 0, 11, 16, 2026));

	tpc_eq($win['week_keys'], array('2026-W43', '2026-W44', '2026-W45', '2026-W46'), 'window keys');

	$w46 = mktime(10, 0, 0, 11, 10, 2026);   /* Tue, current week 3 */
	$w45 = mktime(10, 0, 0, 11,  3, 2026);   /* Tue, current week 2 */
	$w44 = mktime(10, 0, 0, 10, 27, 2026);   /* Tue, prior week 1   */
	$out = mktime(10, 0, 0,  9,  1, 2026);   /* before the span     */

	$calls = array(
		/* invoice 1: A lined, B unlined but invoice has a hand line */
		101 => array('start' => $w46, 'invoice' => 1, 'booking' => 11, 'name' => 'A'),
		102 => array('start' => $w46, 'invoice' => 1, 'booking' => 11, 'name' => 'B'),
		/* awaiting invoice */
		103 => array('start' => $w46, 'invoice' => 0, 'booking' => 12, 'name' => 'C'),
		/* invoice 2: D paid not on invoice, E lined with no pay */
		104 => array('start' => $w45, 'invoice' => 2, 'booking' => 13, 'name' => 'Stage &amp; Lights'),
		105 => array('start' => $w45, 'invoice' => 2, 'booking' => 13, 'name' => 'E'),
		/* invoice 3: lines, nobody paid */
		106 => array('start' => $w45, 'invoice' => 3, 'booking' => 14, 'name' => 'F'),
		/* prior week, negative margin */
		107 => array('start' => $w44, 'invoice' => 4, 'booking' => 15, 'name' => 'H'),
		/* neither pay nor line: not active */
		108 => array('start' => $w44, 'invoice' => 0, 'booking' => 16, 'name' => 'I'),
		/* on matched invoice 1 but neither pay nor line: not matched either */
		109 => array('start' => $w46, 'invoice' => 1, 'booking' => 11, 'name' => 'J')
	);

	$pay = array(
		101 => array('pay' => 600.0, 'charge' => 1000.0, 'rows' => 3, 'null_calc' => 0),
		102 => array('pay' => 100.0, 'charge' => 150.0,  'rows' => 1, 'null_calc' => 0),
		103 => array('pay' =>  50.0, 'charge' =>  80.0,  'rows' => 1, 'null_calc' => 0),
		104 => array('pay' =>  80.0, 'charge' => 120.0,  'rows' => 1, 'null_calc' => 1),
		107 => array('pay' => 900.0, 'charge' => 500.0,  'rows' => 2, 'null_calc' => 0)
	);

	$lines = array(
		array('invoice' => 1, 'call' => 101, 'start' => $w46, 'revenue' => 1000.0),
		array('invoice' => 1, 'call' => 0,   'start' => $w46, 'revenue' =>  200.0),   /* hand-added */
		array('invoice' => 2, 'call' => 105, 'start' => $w45, 'revenue' =>  300.0),
		array('invoice' => 3, 'call' => 106, 'start' => $w45, 'revenue' =>  500.0),
		array('invoice' => 4, 'call' => 107, 'start' => $w44, 'revenue' =>  500.0),
		array('invoice' => 4, 'call' => 999, 'start' => $w44, 'revenue' =>   40.0),   /* call not in set */
		array('invoice' => 4, 'call' => 998, 'start' => $out, 'revenue' => 7777.0)    /* outside span: dropped */
	);

	$r = goat_perf_cost_compute($win, $calls, $pay, $lines, 50);
	$wk = $r['weeks'];

	/* dense, oldest first */
	tpc_eq(count($wk), 4, 'weeks dense');
	tpc_eq($wk[0]['key'], '2026-W43', 'oldest first');

	/* empty week: zeros, margin_pct null */
	tpc_eq($wk[0]['revenue'], 0.0, 'empty week revenue');
	tpc_eq($wk[0]['margin_pct'], null, 'empty week pct null');

	/* week 3: invoice 1 matched, full revenue incl. hand line, full pay incl. B */
	tpc_eq($wk[3]['revenue'], 1200.0, 'W46 revenue incl. hand-added line');
	tpc_eq($wk[3]['pay'], 700.0, 'W46 pay incl. unlined B');
	tpc_eq($wk[3]['margin'], 500.0, 'W46 margin');
	tpc_eq($wk[3]['margin_pct'], 41.7, 'W46 margin pct');
	tpc_eq($wk[3]['calls_matched'], 2, 'W46 calls matched (A, B)');
	tpc_eq($wk[3]['calls_active'], 3, 'W46 calls active (A, B, C)');
	tpc_eq($wk[3]['calls_matched'] <= $wk[3]['calls_active'], true, 'W46 matched never exceeds active (J excluded)');
	tpc_eq($wk[3]['awaiting_invoice'], array('calls' => 1, 'pay' => 50.0), 'W46 awaiting');
	tpc_eq($wk[3]['hand_billed_unlined'], array('calls' => 1, 'pay' => 100.0), 'W46 hand billed');
	tpc_eq($wk[3]['not_on_invoice']['calls'], 0, 'W46 not on invoice');

	/* week 2: invoice 2 matched via D; invoice 3 invoiced with no pay */
	tpc_eq($wk[2]['revenue'], 300.0, 'W45 revenue');
	tpc_eq($wk[2]['pay'], 80.0, 'W45 pay');
	tpc_eq($wk[2]['calls_matched'], 2, 'W45 matched (D, E)');
	tpc_eq($wk[2]['calls_active'], 3, 'W45 active (D, E, F)');
	tpc_eq($wk[2]['not_on_invoice'], array('calls' => 1, 'pay' => 80.0), 'W45 not on invoice');
	tpc_eq($wk[2]['invoiced_no_pay'], array('invoices' => 1, 'revenue' => 500.0), 'W45 invoiced no pay');

	/* week 1: negative margin, line with outside call counted */
	tpc_eq($wk[1]['revenue'], 540.0, 'W44 revenue incl. outside-call line');
	tpc_eq($wk[1]['pay'], 900.0, 'W44 pay');
	tpc_eq($wk[1]['margin'], -360.0, 'W44 negative margin');
	tpc_eq($wk[1]['margin_pct'], -66.7, 'W44 negative pct');
	tpc_eq($wk[1]['calls_active'], 1, 'W44 active (H only)');

	/* totals */
	tpc_eq($r['totals']['current']['revenue'], 1500.0, 'current revenue');
	tpc_eq($r['totals']['current']['pay'], 780.0, 'current pay');
	tpc_eq($r['totals']['current']['margin'], 720.0, 'current margin');
	tpc_eq($r['totals']['current']['margin_pct'], 48.0, 'current pct');
	tpc_eq($r['totals']['prior']['margin'], -360.0, 'prior margin');
	tpc_eq($r['totals']['current']['calls_active'], 6, 'current active');

	/* exceptions: requested window only, raw name passed through */
	$ex = $r['exceptions']['not_on_invoice'];
	tpc_eq(count($ex), 1, 'one exception');
	tpc_eq($ex[0]['call_id'], 104, 'exception call');
	tpc_eq($ex[0]['invoice_id'], 2, 'exception invoice');
	tpc_eq($ex[0]['call_name'], 'Stage &amp; Lights', 'name raw (front end decodes)');
	tpc_eq($ex[0]['call_date'], '2026-11-03', 'exception date');
	tpc_eq($ex[0]['charge_on_pay'], 120.0, 'exception charge on pay');
	tpc_eq(isset($ex[0]['start']), false, 'internal sort key stripped');
	tpc_eq($r['exceptions']['truncated'], false, 'not truncated');

	/* data quality */
	tpc_eq($r['data_quality']['pay_rows'], 8, 'dq pay rows');
	tpc_eq($r['data_quality']['null_calculated_rows'], 1, 'dq null calc');
	tpc_eq($r['data_quality']['lines_call_outside_span'], 1, 'dq outside call');
	tpc_eq($r['data_quality']['lines_no_call'], 1, 'dq no call');
	tpc_eq($r['data_quality']['lines_no_call_revenue'], 200.0, 'dq no call revenue');

	/* invariant over every week: matched is a subset of active */
	$ok = true;
	foreach ($wk as $w) { if ($w['calls_matched'] > $w['calls_active']) $ok = false; }
	tpc_eq($ok, true, 'matched <= active in every week');

	/* exception in the PRIOR window is not listed */
	$calls2 = array(201 => array('start' => $w44, 'invoice' => 7, 'booking' => 1, 'name' => 'old'),
	                202 => array('start' => $w44, 'invoice' => 7, 'booking' => 1, 'name' => 'x'));
	$pay2   = array(201 => array('pay' => 10.0, 'charge' => 0.0, 'rows' => 1, 'null_calc' => 0));
	$lines2 = array(array('invoice' => 7, 'call' => 202, 'start' => $w44, 'revenue' => 50.0));
	$r2 = goat_perf_cost_compute($win, $calls2, $pay2, $lines2, 50);
	tpc_eq(count($r2['exceptions']['not_on_invoice']), 0, 'prior-window exception not listed');
	tpc_eq($r2['weeks'][1]['not_on_invoice']['calls'], 1, 'prior-window exception still counted');

	/* cap and newest-first order */
	$calls3 = array(); $pay3 = array(); $lines3 = array();
	for ($i = 0; $i < 3; $i++)
	{
		$calls3[300 + $i] = array('start' => $w45 + $i * 3600, 'invoice' => 9, 'booking' => 1, 'name' => 'n' . $i);
		$pay3[300 + $i]   = array('pay' => 1.0, 'charge' => 1.0, 'rows' => 1, 'null_calc' => 0);
	}
	$calls3[399] = array('start' => $w45, 'invoice' => 9, 'booking' => 1, 'name' => 'lined');
	$lines3[]    = array('invoice' => 9, 'call' => 399, 'start' => $w45, 'revenue' => 10.0);
	$r3 = goat_perf_cost_compute($win, $calls3, $pay3, $lines3, 2);
	tpc_eq(count($r3['exceptions']['not_on_invoice']), 2, 'capped at 2');
	tpc_eq($r3['exceptions']['truncated'], true, 'truncated flag');
	tpc_eq($r3['exceptions']['not_on_invoice'][0]['call_id'], 302, 'newest first');

	/* rounding happens once: 3 x 0.335 = 1.005 -> 1.01 (not 0.34 x 3 = 1.02) */
	$calls4 = array(); $pay4 = array(); $lines4 = array();
	for ($i = 0; $i < 3; $i++)
	{
		$calls4[400 + $i] = array('start' => $w46, 'invoice' => 5, 'booking' => 1, 'name' => 'r');
		$pay4[400 + $i]   = array('pay' => 0.335, 'charge' => 0.0, 'rows' => 1, 'null_calc' => 0);
		$lines4[]         = array('invoice' => 5, 'call' => 400 + $i, 'start' => $w46, 'revenue' => 1.0);
	}
	$r4 = goat_perf_cost_compute($win, $calls4, $pay4, $lines4, 50);
	tpc_eq($r4['weeks'][3]['pay'], round(3 * 0.335, 2), 'pay rounded once');

	/* ---- slice B: per customer ---- */

	/* Same fixture as the main run, with hours on the lines and invoices
	   mapped to customers. Invoice 4 (prior week) is deliberately left out of
	   the map, so its figures land on customer 0. */
	$linesB = $lines;
	for ($i = 0; $i < count($linesB); $i++)
		$linesB[$i]['hours'] = 10.0;
	$invCust = array(1 => 500, 2 => 600, 3 => 700);

	$rB = goat_perf_cost_compute($win, $calls, $pay, $linesB, 50, $invCust);
	tpc_eq(isset($rB['customers']), true, 'B: customers present when a map is given');
	tpc_eq(isset($r['customers']), false, 'B: customers absent without a map (5.104.0 shape)');
	tpc_eq($rB['weeks'], $r['weeks'], 'B: weeks unchanged by the customer split');
	tpc_eq($rB['totals'], $r['totals'], 'B: totals unchanged by the customer split');

	$byId = array();
	foreach ($rB['customers'] as $c)
		$byId[$c['id']] = $c;

	tpc_eq(array_keys($byId), array(0, 500, 600), 'B: customers 0, 500, 600 (700 is invoiced, unpaid: not matched)');
	tpc_eq($byId[500]['current']['revenue'], 1200.0, 'B: 500 revenue (incl. hand line)');
	tpc_eq($byId[500]['current']['pay'], 700.0, 'B: 500 pay');
	tpc_eq($byId[500]['current']['margin'], 500.0, 'B: 500 margin');
	tpc_eq($byId[500]['current']['margin_pct'], 41.7, 'B: 500 margin pct');
	tpc_eq($byId[500]['current']['hours'], 20.0, 'B: 500 hours (two lines x 10)');
	tpc_eq($byId[500]['current']['calls_matched'], 2, 'B: 500 calls matched');
	tpc_eq($byId[600]['current']['revenue'], 300.0, 'B: 600 revenue');
	tpc_eq($byId[600]['current']['pay'], 80.0, 'B: 600 pay');
	tpc_eq($byId[0]['prior']['revenue'], 540.0, 'B: unmapped invoice falls to customer 0, prior window');
	tpc_eq($byId[0]['prior']['margin'], -360.0, 'B: customer 0 prior margin (negative)');
	tpc_eq($byId[0]['current']['margin_pct'], null, 'B: no current revenue -> pct null');

	/* the reconciliation the card relies on: customers sum to the totals */
	foreach (array('current', 'prior') as $tw)
	{
		$sr = 0.0; $sp = 0.0; $sc = 0;
		foreach ($rB['customers'] as $c) { $sr += $c[$tw]['revenue']; $sp += $c[$tw]['pay']; $sc += $c[$tw]['calls_matched']; }
		tpc_eq(round($sr, 2), $rB['totals'][$tw]['revenue'], 'B: customers sum to totals.' . $tw . '.revenue');
		tpc_eq(round($sp, 2), $rB['totals'][$tw]['pay'], 'B: customers sum to totals.' . $tw . '.pay');
		tpc_eq($sc, $rB['totals'][$tw]['calls_matched'], 'B: customers sum to totals.' . $tw . '.calls_matched');
	}
	tpc_eq(json_encode($rB['customers'])[0], '[', 'B: customers encode as a JSON list');

	/* JSON shape survives encoding */
	tpc_eq(json_encode($r) !== false, true, 'json_encode ok');

	echo $pass . " passed, " . $fail . " failed\n";
	exit($fail > 0 ? 1 : 0);
