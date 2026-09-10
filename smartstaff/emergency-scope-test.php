<?php

	/*
	/* TEST HARNESS for goat_emergency_scope(). Read-only: it discovers its own
	/* fixtures from live data and WRITES NOTHING.
	/*
	/* Run in a browser on the TEST box:
	/*     .../ajax/crew/emergency-scope-test.php?w=wide
	/*     .../ajax/crew/emergency-scope-test.php?w=tight
	/*
	/* TWO LOADS ON PURPOSE. The window bounds are constants, so a single
	/* request cannot exercise both a window that includes the fixture call and
	/* one that excludes it. `wide` proves the boss intersection GRANTS;
	/* `tight` proves the window REFUSES the same pair. A window test that only
	/* ever runs wide is not a window test.
	/*
	/* It forces GOAT_EMERGENCY_BOSS_SCOPE ON. That is the whole point: rule 4
	/* ships dark, and this is what keeps it from being untested code that
	/* somebody has to debug under pressure the day it is switched on.
	/*
	/* DELETE FROM THE DOCROOT AFTER RUNNING. It is committed to the repo, not
	/* left on a webserver.
	*/

	$mode = (isset($_GET['w']) && $_GET['w'] === 'tight') ? 'tight' : 'wide';

	/* Constants must be defined BEFORE the include -- emergency-scope.php
	/* guards each with if (!defined()) precisely so a harness can do this. */

	define('GOAT_EMERGENCY_BOSS_SCOPE', true);

	if ($mode === 'tight')
	{
		define('GOAT_EMERGENCY_LEAD_DAYS', 0);
		define('GOAT_EMERGENCY_TAIL_DAYS', 0);
	}
	else
	{
		define('GOAT_EMERGENCY_LEAD_DAYS', 40000);   /* ~110 years either side */
		define('GOAT_EMERGENCY_TAIL_DAYS', 40000);
	}

	include('../../global.php');
	include('emergency-scope.php');

	header('Content-Type: text/plain');

	/*
	/* ADMIN ONLY. This is a test file, but it sits on a webserver and it
	/* prints user ids and call ids. A diagnostic without a gate is how a
	/* diagnostic quietly becomes an endpoint. Requires a logged-in admin
	/* session on the box you are running it against.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		header('HTTP/1.1 403 Forbidden');
		die("Admin only.\n");
	}

	$pass = 0; $fail = 0; $skip = 0;

	function t($label, $got, $wantAllowed, $wantReason)
	{
		global $pass, $fail;

		$ok = ($got['allowed'] === $wantAllowed);

		if ($ok && $wantReason !== null && $got['reason'] !== $wantReason)
		{
			$ok = false;
		}

		if ($ok) { $pass++; $tag = 'PASS'; } else { $fail++; $tag = 'FAIL'; }

		echo str_pad($tag, 6) . str_pad($label, 46)
		   . 'allowed=' . var_export($got['allowed'], true)
		   . ' reason=' . $got['reason']
		   . ' call=' . var_export($got['call_id'], true) . "\n";

		if (!$ok)
		{
			echo '      ^ wanted allowed=' . var_export($wantAllowed, true)
			   . ($wantReason === null ? '' : ' reason=' . $wantReason) . "\n";
		}
	}

	/*
	/* Like t(), but asserts the reason STARTS WITH $wantPrefix. Exists because
	/* the first version of test 9 printed a complaint that the reason was
	/* wrong and then reported PASS anyway -- which is how a test that has
	/* never once exercised its subject can look green for an afternoon.
	*/

	function tpre($label, $got, $wantPrefix)
	{
		global $pass, $fail;

		$ok = ($got['allowed'] === true)
		      && (strpos($got['reason'], $wantPrefix) === 0);

		if ($ok) { $pass++; $tag = 'PASS'; } else { $fail++; $tag = 'FAIL'; }

		echo str_pad($tag, 6) . str_pad($label, 46)
		   . 'allowed=' . var_export($got['allowed'], true)
		   . ' reason=' . $got['reason']
		   . ' call=' . var_export($got['call_id'], true) . "\n";

		if (!$ok)
		{
			echo '      ^ wanted allowed=true and reason starting "'
			   . $wantPrefix . '"' . "\n";
		}
	}

	function skipt($label, $why)
	{
		global $skip;
		$skip++;
		echo str_pad('SKIP', 6) . str_pad($label, 46) . $why . "\n";
	}

	function one_id($sql)
	{
		$r = mysql_query($sql);
		if ($r === false || mysql_num_rows($r) == 0) return 0;
		$row = mysql_fetch_object($r);
		return (int) $row->id;
	}

	echo "goat_emergency_scope() — window mode: " . $mode
	   . "  (lead " . GOAT_EMERGENCY_LEAD_DAYS
	   . "d / tail " . GOAT_EMERGENCY_TAIL_DAYS . "d)\n";
	echo str_repeat('-', 100) . "\n";

	/* ---------- fixtures ---------- */

	$admin = one_id("SELECT id FROM users WHERE usergroupID = 1 AND active = 1 ORDER BY id LIMIT 1");
	$ops   = one_id("SELECT id FROM users WHERE usergroupID <> 1 AND LOWER(TRIM(cohort)) = 'operations' ORDER BY id LIMIT 1");
	$lead  = one_id("SELECT id FROM users WHERE usergroupID <> 1 AND LOWER(TRIM(cohort)) = 'leadership' ORDER BY id LIMIT 1");
	$crew  = one_id("SELECT id FROM users WHERE usergroupID <> 1 AND (cohort IS NULL OR LOWER(TRIM(cohort)) NOT IN ('leadership','operations')) ORDER BY id LIMIT 1");
	$crew2 = one_id("SELECT id FROM users WHERE usergroupID <> 1 AND (cohort IS NULL OR LOWER(TRIM(cohort)) NOT IN ('leadership','operations')) ORDER BY id DESC LIMIT 1");

	echo "fixtures: admin=$admin ops=$ops leadership=$lead crew=$crew crew2=$crew2\n";

	/*
	/* Boss fixture. is_call_boss IS NOT FILTERED IN SQL -- it is binary(50)
	/* and the house rule is select-and-cast-in-PHP. Scan recent confirmed
	/* rows, cast, then find a second confirmed person on the same call.
	*/

	$bossUser = 0; $bossCall = 0; $bossSubject = 0;

	$r = mysql_query("SELECT callID, userID, is_call_boss FROM call_crew_map
	                  WHERE status = 5 ORDER BY callID DESC LIMIT 8000");

	if ($r !== false)
	{
		while ($row = mysql_fetch_object($r))
		{
			if ((int) $row->is_call_boss !== 1) continue;

			$cid = (int) $row->callID;
			$uid = (int) $row->userID;

			/*
			/* THE BOSS FIXTURE MUST NOT BE admin OR operations. Rules 2 and 3
			/* match on cohort and return before rule 4 is ever reached, so a
			/* boss who is also an ops user tests the cohort branch and calls
			/* it a boss test. That is exactly what happened on the first run:
			/* the scan picked userID 9734, who resolves to `operations` on
			/* test, and test 9 reported reason=cohort_operations in BOTH
			/* window modes. leadership and crew are both fine -- neither
			/* matches an earlier rule, so both fall through to rule 4.
			*/

			$bc = goat_cohort_for_user($uid);
			if ($bc === 'admin' || $bc === 'operations') continue;

			$r2 = mysql_query("SELECT userID FROM call_crew_map
			                   WHERE callID = $cid AND status = 5
			                     AND userID <> $uid LIMIT 1");

			if ($r2 !== false && mysql_num_rows($r2) > 0)
			{
				$row2        = mysql_fetch_object($r2);
				$bossUser    = $uid;
				$bossCall    = $cid;
				$bossSubject = (int) $row2->userID;
				break;
			}
		}
	}

	echo "boss fixture: boss=$bossUser (" . ($bossUser > 0 ? goat_cohort_for_user($bossUser) : '-')
	   . ") subject=$bossSubject call=$bossCall\n";
	echo str_repeat('-', 100) . "\n";

	/* ---------- rules 1-3 and the negatives ---------- */

	if ($crew > 0)  t('1  self',                     goat_emergency_scope($crew, $crew),   true,  'self');
	else            skipt('1  self', 'no crew user found');

	if ($admin > 0 && $crew > 0)
	                t('2  admin sees crew',          goat_emergency_scope($admin, $crew),  true,  'cohort_admin');
	else            skipt('2  admin sees crew', 'no admin or crew user');

	if ($ops > 0 && $crew > 0)
	                t('3  operations sees crew',     goat_emergency_scope($ops, $crew),    true,  'cohort_operations');
	else            skipt('3  operations sees crew', 'no operations user on this box');

	if ($lead > 0 && $crew > 0)
	                t('4  LEADERSHIP IS REFUSED',    goat_emergency_scope($lead, $crew),   false, 'denied');
	else            skipt('4  LEADERSHIP IS REFUSED', 'no leadership user on this box');

	if ($crew > 0 && $crew2 > 0 && $crew !== $crew2)
	                t('5  crew cannot see crew',     goat_emergency_scope($crew, $crew2),  false, 'denied');
	else            skipt('5  crew cannot see crew', 'need two distinct crew users');

	t('6  zero ids',        goat_emergency_scope(0, 0),          false, 'denied');
	t('7  negative ids',    goat_emergency_scope(-1, -1),        false, 'denied');
	t('8  nonexistent user', goat_emergency_scope(99999999, 99999998), false, 'denied');

	/* ---------- rule 4 ---------- */

	if ($bossUser > 0 && $bossSubject > 0)
	{
		$got = goat_emergency_scope($bossUser, $bossSubject);

		if ($mode === 'wide')
		{
			tpre('9  boss sees own crew (wide window)', $got, 'boss_');

			if ($got['allowed'] && $got['reason'] === 'boss_unattributed')
			{
				echo "      ^ NOTE boss_unattributed: goat_boss_scope() and\n"
				   . "        goat_bosses_by_call() disagree on call " . $bossCall . ".\n"
				   . "        Should be unreachable — worth investigating.\n";
			}
		}
		else
		{
			t('9  window REFUSES the same pair',     $got, false, 'denied');
		}
	}
	else
	{
		skipt('9  rule 4', 'no call found with a flagged boss AND a second confirmed crew member');
	}

	echo str_repeat('-', 100) . "\n";
	echo "pass=$pass fail=$fail skip=$skip\n";
	echo ($fail === 0 ? "OK\n" : "FAILURES PRESENT\n");
