<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');
	include_once('supervision-graph.php');

	/*
	/* SMOKE TEST — THE LINK BUTTON (BRIEF-default-link-self-submit-off,
	/* Release 2, B7).
	/*
	/* FIXTURE — TEST BOX ONLY. NEVER DEPLOY TO PROD (listed in
	/* NEVER-DEPLOY-TO-PROD.md). It WRITES links and markers, then puts the
	/* booking's links and markers back as it found them.
	/*
	/* TWO LOCKS, as test-call-tiers.php: an admin session, and the connected
	/* database must be smartst_test.
	/*
	/* Open in a browser on the test box, signed in as an admin:
	/*
	/*   smoke-link-button.php?booking_id=N              read-only: checks 1, 6, 7
	/*   smoke-link-button.php?booking_id=N&run=1        all eight, then restore
	/*   ...&two_boss=<callID>&started=<callID>          name the calls for 6 / 7
	/*                                                   (else the first matching
	/*                                                   row is used, if any)
	/*   ...&keep=1                                      skip the restore
	/*
	/* It drives the REAL endpoints over HTTP with your session cookie —
	/* auto-link-supervision.php and call-supervision.php — so what passes here
	/* is what THE GOAT will get. The warning check calls
	/* goat_unlinked_boss_overlap() directly: get-booking.php reads the same
	/* function.
	/*
	/* WHAT THE BOOKING NEEDS for run=1: at least THREE calls the button offers
	/* (no link, one live Crew Boss call, not started), and at least one of them
	/* must be flagged by the amber warning — i.e. its Crew Boss call has
	/* somebody confirmed. Check 1 says what is missing if not.
	/*
	/* The calls it uses, all from the first dry run:
	/*   B  marked in the apply (check 3) — one the warning flags
	/*   A  linked BY HAND between the preview and the apply (check 8)
	/*   C  linked by the button, then unlinked (4) and relinked by hand (5)
	/*
	/* PHP 5.x — mysql_* / PDO, array(), no ??, no short array syntax.
	*/

	header('Content-Type: text/plain; charset=utf-8');

	if (goat_user_cohort() !== 'admin')
	{
		http_response_code(403);
		die("Admin only\n");
	}

	$dbres = mysql_query("SELECT DATABASE() AS d");
	$dbrow = ($dbres === false) ? false : mysql_fetch_object($dbres);

	if (!$dbrow || (string) $dbrow->d !== 'smartst_test')
	{
		http_response_code(403);
		die("smoke-link-button.php runs on smartst_test only\n");
	}

	$actor     = isset($_SESSION[SITE_KEY]['userID']) ? (int) $_SESSION[SITE_KEY]['userID'] : 0;
	$bookingID = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : 0;
	$run       = isset($_GET['run']) && $_GET['run'] === '1';
	$keep      = isset($_GET['keep']) && $_GET['keep'] === '1';
	$twoBoss   = isset($_GET['two_boss']) ? (int) $_GET['two_boss'] : 0;
	$startedID = isset($_GET['started']) ? (int) $_GET['started'] : 0;

	if ($bookingID <= 0)
	{
		die("booking_id=N is required\n");
	}

	/* The sub-requests below share this session. PHP's file sessions lock per
	/* request, so release it first or the first one waits forever. */

	if (session_id() !== '')
	{
		session_write_close();
	}

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		die("database unavailable (goat_pdo)\n");
	}

	$fails = 0;

	function say($s)
	{
		echo $s . "\n";
		@ob_flush();
		flush();
	}

	function check($label, $ok, $detail)
	{
		global $fails;

		if (!$ok)
		{
			$fails++;
		}

		say(($ok ? 'PASS  ' : 'FAIL  ') . $label . ($detail !== '' ? ('  — ' . $detail) : ''));

		return $ok;
	}

	/* POST JSON to a sibling endpoint as the signed-in admin. array(status, body|null). */

	function post_json($file, $body)
	{
		$https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
		$url    = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST']
		        . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/' . $file;
		$cookie = isset($_SERVER['HTTP_COOKIE']) ? $_SERVER['HTTP_COOKIE'] : '';

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Cookie: ' . $cookie));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);

		$raw    = curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err    = curl_error($ch);
		curl_close($ch);

		if ($raw === false || $raw === '')
		{
			say('      (' . $file . ': no body, HTTP ' . $status . ($err !== '' ? (', ' . $err) : '') . ')');
			return array($status, null);
		}

		$j = json_decode($raw, true);

		if (!is_array($j))
		{
			say('      (' . $file . ': not JSON, HTTP ' . $status . ': ' . substr($raw, 0, 200) . ')');
		}

		return array($status, is_array($j) ? $j : null);
	}

	function dry_run($bookingID)
	{
		return post_json('auto-link-supervision.php', array('mode' => 'dry_run', 'booking_id' => $bookingID));
	}

	function row_of($dry, $cid)
	{
		foreach ($dry['candidates'] as $r)
		{
			if ((int) $r['child_call'] === (int) $cid)
			{
				return $r;
			}
		}

		return null;
	}

	function sup_row($pdo, $cid)
	{
		$st = $pdo->prepare("SELECT * FROM `call_supervision` WHERE `child_call` = ?");
		$st->execute(array((int) $cid));
		$r = $st->fetch();
		return $r ? $r : null;
	}

	function is_marked($pdo, $cid)
	{
		$st = $pdo->prepare("SELECT `callID` FROM `call_no_crew_boss` WHERE `callID` = ?");
		$st->execute(array((int) $cid));
		return $st->fetch() ? true : false;
	}

	function warned($bookingID, $cid)
	{
		$w = goat_unlinked_boss_overlap(array($bookingID));
		return $w['ok'] ? isset($w['by_call'][(int) $cid]) : null;
	}

	say('smoke-link-button.php — booking ' . $bookingID . ' on ' . $dbrow->d . ', as user ' . $actor
	    . ', ' . date('Y-m-d H:i:s') . ($run ? ' — WRITE RUN' : ' — read-only'));

	if (!goat_sg_have_no_crew_boss() || !goat_sg_have_supervision_auto())
	{
		die("FAIL  MIGRATION-link-button.sql has not run here (call_no_crew_boss / call_supervision.auto missing)\n");
	}

	/* ---- the booking's calls, and a snapshot to restore ---- */

	$ids  = array();
	$cres = mysql_query("SELECT id FROM calls WHERE bookingID = " . $bookingID);

	while ($cres !== false && ($c = mysql_fetch_object($cres)))
	{
		$ids[] = (int) $c->id;
	}

	if (count($ids) === 0)
	{
		die("FAIL  booking " . $bookingID . " has no calls\n");
	}

	$in    = implode(',', $ids);
	$snapS = $pdo->query("SELECT * FROM `call_supervision` WHERE `child_call` IN (" . $in . ")")->fetchAll();
	$snapM = $pdo->query("SELECT * FROM `call_no_crew_boss` WHERE `callID` IN (" . $in . ")")->fetchAll();

	say('      snapshot: ' . count($snapS) . ' link(s), ' . count($snapM) . ' marker(s) on ' . count($ids) . ' call(s)');

	/* ---- 1. dry_run ---- */

	list($st1, $d1) = dry_run($bookingID);

	if (!check('1. dry_run answers', $st1 === 200 && $d1 !== null && !empty($d1['ok']), 'HTTP ' . $st1))
	{
		die("\nStopped.\n");
	}

	$offers = array();

	foreach ($d1['candidates'] as $r)
	{
		say(sprintf('      %-7s %-15s #%-6d %-28s %s %s → #%d%s', $r['outcome'], $r['reason'], $r['child_call'],
		            substr($r['call_name'], 0, 28), $r['start_date'], $r['start_time'], $r['boss_call'],
		            $r['note'] !== '' ? '  (' . $r['note'] . ')' : ''));

		if ($r['outcome'] === 'offer')
		{
			$offers[] = $r;
		}
	}

	check('1. dry_run lists rows (' . $d1['counts']['offer'] . ' offer, ' . $d1['counts']['relink'] . ' relink, '
	      . $d1['counts']['skip'] . ' skip, ' . $d1['counts']['marked'] . ' marked)',
	      count($d1['candidates']) > 0, count($d1['candidates']) === 0 ? 'nothing to inspect — pick a booking with calls overlapping a Crew Boss call' : '');

	/* ---- 6. two Crew Boss calls ---- */

	$r6 = null;

	foreach ($d1['candidates'] as $r)
	{
		if (($twoBoss > 0 && (int) $r['child_call'] === $twoBoss) || ($twoBoss === 0 && $r['reason'] === 'two_boss_calls'))
		{
			$r6 = $r;
			break;
		}
	}

	if ($r6 === null)
	{
		say('NOT TESTED  6. no call overlapping two Crew Boss calls' . ($twoBoss > 0 ? (' (call ' . $twoBoss . ' not listed)') : ' on this booking'));
	}
	else
	{
		check('6. #' . $r6['child_call'] . ' overlapping two Crew Boss calls is skip two_boss_calls',
		      $r6['outcome'] === 'skip' && $r6['reason'] === 'two_boss_calls', $r6['outcome'] . '/' . $r6['reason']);
	}

	/* ---- 7. a started call ---- */

	$r7 = null;

	foreach ($d1['candidates'] as $r)
	{
		if (($startedID > 0 && (int) $r['child_call'] === $startedID) || ($startedID === 0 && $r['reason'] === 'started'))
		{
			$r7 = $r;
			break;
		}
	}

	if ($r7 === null)
	{
		say('NOT TESTED  7. no started call' . ($startedID > 0 ? (' (call ' . $startedID . ' not listed)') : ' on this booking'));
	}
	else
	{
		check('7. #' . $r7['child_call'] . ' that has started is not offered',
		      $r7['outcome'] === 'skip' && $r7['reason'] === 'started', $r7['outcome'] . '/' . $r7['reason']);
	}

	if (!$run)
	{
		say("\nRead-only run. Add &run=1 for checks 2-5 and 8 (writes, then restores).");
		say($fails ? ($fails . ' FAILED') : 'No failures.');
		die();
	}

	/* ---- choose B, A, C ---- */

	$B = null;

	foreach ($offers as $r)
	{
		if (warned($bookingID, $r['child_call']) === true)
		{
			$B = $r;
			break;
		}
	}

	if (!check('setup: an offered call the warning flags (for 3)', $B !== null,
	           $B === null ? 'none — the Crew Boss call needs somebody confirmed' : ('#' . $B['child_call'])))
	{
		die("\nStopped before writing anything.\n");
	}

	$rest = array();

	foreach ($offers as $r)
	{
		if ((int) $r['child_call'] !== (int) $B['child_call'])
		{
			$rest[] = $r;
		}
	}

	if (!check('setup: two more offered calls (for 4, 5, 8)', count($rest) >= 2, count($rest) . ' available'))
	{
		die("\nStopped before writing anything.\n");
	}

	$A = $rest[0];
	$C = $rest[1];

	say('      B=#' . $B['child_call'] . ' (mark)  A=#' . $A['child_call'] . ' (hand-linked after preview)  C=#' . $C['child_call'] . ' (unlink / relink)');

	$restore = function () use ($pdo, $in, $snapS, $snapM, $keep)
	{
		if ($keep)
		{
			say("\nkeep=1: NOT restored.");
			return;
		}

		try
		{
			$pdo->exec("DELETE FROM `call_supervision` WHERE `child_call` IN (" . $in . ")");
			$pdo->exec("DELETE FROM `call_no_crew_boss` WHERE `callID` IN (" . $in . ")");

			foreach ($snapS as $r)
			{
				$cols = array_keys($r);
				$st   = $pdo->prepare("INSERT INTO `call_supervision` (`" . implode('`,`', $cols) . "`) VALUES ("
				                      . implode(',', array_fill(0, count($cols), '?')) . ")");
				$st->execute(array_values($r));
			}

			foreach ($snapM as $r)
			{
				$st = $pdo->prepare("INSERT INTO `call_no_crew_boss` (`callID`, `set_by`, `set_at`) VALUES (?, ?, ?)");
				$st->execute(array($r['callID'], $r['set_by'], $r['set_at']));
			}

			$nS = count($pdo->query("SELECT `id` FROM `call_supervision` WHERE `child_call` IN (" . $in . ")")->fetchAll());
			$nM = count($pdo->query("SELECT `callID` FROM `call_no_crew_boss` WHERE `callID` IN (" . $in . ")")->fetchAll());

			check('restore: links and markers back as found', $nS === count($snapS) && $nM === count($snapM),
			      $nS . ' link(s), ' . $nM . ' marker(s)');
		}
		catch (PDOException $e)
		{
			check('restore', false, $e->getMessage());
		}
	};

	/* ---- 8 (setup). A is linked by hand after the preview ---- */

	list($st, $j) = post_json('call-supervision.php', array('action' => 'set', 'boss_call' => (int) $A['boss_call'],
	                                                        'child_calls' => array((int) $A['child_call']), 'confirm' => true));

	if (!check('8. setup: #' . $A['child_call'] . ' linked by hand after the preview', $st === 200 && !empty($j['ok']), 'HTTP ' . $st))
	{
		$restore();
		die("\nStopped.\n");
	}

	/* ---- 2, 3, 8. apply: link every offer but B (A included), mark B ---- */

	$link = array();

	foreach ($offers as $r)
	{
		if ((int) $r['child_call'] !== (int) $B['child_call'])
		{
			$link[] = (int) $r['child_call'];
		}
	}

	list($st, $ap) = post_json('auto-link-supervision.php', array('mode' => 'apply', 'booking_id' => $bookingID,
	                                                              'link' => $link, 'mark' => array((int) $B['child_call'])));

	if (!check('2. apply answers', $st === 200 && $ap !== null && !empty($ap['ok']), 'HTTP ' . $st))
	{
		$restore();
		die("\nStopped.\n");
	}

	$res = array();

	foreach ($ap['results'] as $r)
	{
		$res[$r['action'] . ':' . $r['call_id']] = $r;
		say('      ' . $r['action'] . ' #' . $r['call_id'] . ' ' . $r['result'] . ($r['reason'] !== '' ? (' — ' . $r['reason']) : ''));
	}

	$ra = isset($res['link:' . $A['child_call']]) ? $res['link:' . $A['child_call']] : null;

	check('8. #' . $A['child_call'] . ' (linked by hand since the preview) is refused',
	      $ra !== null && $ra['result'] === 'refused', $ra === null ? 'no result' : ($ra['result'] . ': ' . $ra['reason']));

	$allDone = true;
	$detail  = array();

	foreach ($link as $cid)
	{
		if ($cid === (int) $A['child_call'])
		{
			continue;
		}

		$r  = isset($res['link:' . $cid]) ? $res['link:' . $cid] : null;
		$sr = sup_row($pdo, $cid);
		$ok = ($r !== null && $r['result'] === 'done' && $sr !== null && (int) $sr['auto'] === 1
		       && (int) $sr['created_by'] === $actor && (int) $sr['boss_call'] === (int) $r['boss_call']);

		if (!$ok)
		{
			$allDone  = false;
			$detail[] = '#' . $cid . ($sr === null ? ' no row' : (' auto=' . $sr['auto']));
		}
	}

	check('2. the others are linked, auto = 1, created_by = you (' . (count($link) - 1) . ' call(s))',
	      $allDone && count($link) > 1, implode(', ', $detail));

	$sa = sup_row($pdo, $A['child_call']);

	check('8. #' . $A['child_call'] . ' keeps the hand link, auto = 0', $sa !== null && (int) $sa['auto'] === 0,
	      $sa === null ? 'no row' : ('auto=' . $sa['auto']));

	list($st, $d2) = dry_run($bookingID);
	$left = array();

	foreach (($d2 !== null ? $d2['candidates'] : array()) as $r)
	{
		if ($r['outcome'] === 'offer')
		{
			$left[] = '#' . $r['child_call'];
		}
	}

	check('2. a second dry_run offers nothing', $d2 !== null && count($left) === 0, implode(', ', $left));

	$rb = isset($res['mark:' . $B['child_call']]) ? $res['mark:' . $B['child_call']] : null;

	check('3. #' . $B['child_call'] . ' marked No Crew Boss', $rb !== null && $rb['result'] === 'done' && is_marked($pdo, $B['child_call']),
	      $rb === null ? 'no result' : $rb['result']);
	check('3. the warning no longer flags #' . $B['child_call'] . ' (it did before)', warned($bookingID, $B['child_call']) === false, '');

	/* ---- 4. unlink C (a button link) through call-supervision.php clear ---- */

	list($st, $j4) = post_json('call-supervision.php', array('action' => 'clear', 'child_calls' => array((int) $C['child_call'])));

	check('4. clear #' . $C['child_call'] . ' answers', $st === 200 && !empty($j4['ok']), 'HTTP ' . $st);
	check('4. reply lists it in marked_no_crew_boss', $j4 !== null && isset($j4['marked_no_crew_boss'])
	      && in_array((int) $C['child_call'], array_map('intval', $j4['marked_no_crew_boss'])),
	      $j4 === null ? '' : json_encode(isset($j4['marked_no_crew_boss']) ? $j4['marked_no_crew_boss'] : null));
	check('4. marker row written', is_marked($pdo, $C['child_call']), '');

	list($st, $d4) = dry_run($bookingID);
	$r4 = ($d4 !== null) ? row_of($d4, $C['child_call']) : null;

	check('4. dry_run no longer offers it', $r4 !== null && $r4['outcome'] === 'marked', $r4 === null ? 'not listed' : $r4['outcome']);

	/* ---- 5. link C by hand ---- */

	list($st, $j5) = post_json('call-supervision.php', array('action' => 'set', 'boss_call' => (int) $C['boss_call'],
	                                                         'child_calls' => array((int) $C['child_call']), 'confirm' => true));
	$sc = sup_row($pdo, $C['child_call']);

	check('5. link #' . $C['child_call'] . ' by hand answers', $st === 200 && !empty($j5['ok']), 'HTTP ' . $st);
	check('5. marker gone', !is_marked($pdo, $C['child_call']), '');
	check('5. auto = 0', $sc !== null && (int) $sc['auto'] === 0, $sc === null ? 'no row' : ('auto=' . $sc['auto']));

	$restore();

	say("\n" . ($fails ? ($fails . ' FAILED') : 'ALL PASS'));

?>
