<?php

	/*
	/* CALL-OUT SWEEP — close call-outs on calls that have started, and on
	/* calls that are already full.
	/* DESIGN-backup-callout-v0_2 §4.6, brief §D6.
	/*
	/* Deviation from the design, per the brief: §4.6 named reminder-cron.php.
	/* That is a legacy SMS script at the site root that only runs inside an
	/* SMS connect block — the wrong home. This standalone script replaces it.
	/*
	/* CLI ONLY. Run from cPanel cron every 15 minutes:
	/*
	/*     php /home/smartst/public_html/ajax/crew/callout-sweep.php
	/*
	/* No cohort check: there is no session. The CLI guard is the gate.
	/*
	/* chdir FIRST. PHP resolves an include starting with ../ against the
	/* WORKING directory, not this file's directory. Under the web server the
	/* two are the same; under cron the working directory is the account's
	/* home, and '../../global.php' would not be found. Changing into this
	/* file's directory makes the include resolve exactly as it does for
	/* every other endpoint here, so the cron line needs no cd.
	/*
	/* A late sweep is harmless: respond-to-call.php already refuses an accept
	/* on a started call. Members simply sit at 1 until the sweep runs.
	/*
	/* Full calls: closed 'filled' as a catch-all. The fill check normally
	/* runs at the moment of the write that fills the call; see below.
	/*
	/* Idempotent: a closed call-out is never touched again, so a second run
	/* changes nothing.
	/*
	/* PHP 5.x — mysql_* / $db (brief §0.1), no ??, no short array syntax.
	*/

	if (php_sapi_name() !== 'cli')
	{
		header('HTTP/1.1 403 Forbidden');
		die('Forbidden');
	}

	chdir(dirname(__FILE__));

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include_once('callout.php');

	$now    = time();
	$closed = 0;
	$failed = 0;

	$res = mysql_query('SELECT id, callID FROM call_callout WHERE closed_at IS NULL');

	if ($res === false)
	{
		fwrite(STDERR, 'callout-sweep: query failed: ' . mysql_error() . "\n");
		exit(1);
	}

	$open = array();

	while ($row = mysql_fetch_object($res))
	{
		$open[] = $row;
	}

	foreach ($open as $co)
	{
		$startTs = goat_call_start_ts((int) $co->callID);
		$started = ($startTs !== false && $startTs <= $now);

		/* CATCH-ALL: a call that is already full closes 'filled'. Every
		/* write that can fill a call runs the fill check itself
		/* (respond-to-call.php, update-crew-status.php); this catches the
		/* ones that could not — the ops write whose lock was busy, and
		/* SmartStaff's own dash.php, which knows nothing of call-outs.
		/* Read without the lock first, so a quiet run takes no lock at all. */

		if (!$started && !goat_callout_call_full((int) $co->callID))
		{
			continue;
		}

		/* Same lock as an accept, so the close cannot interleave with one.
		/* Longer wait than the web paths: nobody is waiting on this, and if
		/* it still is not granted the next run picks the call-out up. */

		if (!goat_capacity_lock(10))
		{
			$failed++;
			continue;
		}

		if ($started)
		{
			$r = goat_callout_close((int) $co->id, 'started');
		}
		else if (goat_callout_call_full((int) $co->callID))   /* re-checked under the lock */
		{
			$r = goat_callout_close((int) $co->id, 'filled');
		}
		else
		{
			$r = array('changed' => false, 'reverted' => 0);
		}

		goat_capacity_unlock();

		if ($r === false)
		{
			$failed++;
		}
		else if ($r['changed'])
		{
			$closed++;
		}
	}

	/* Silent when there was nothing to do: cPanel mails any cron output, and
	/* this runs 96 times a day. */

	if ($closed || $failed)
	{
		echo 'callout-sweep: ' . count($open) . ' open, ' . $closed . ' closed, ' . $failed . " failed\n";
	}

	exit($failed ? 1 : 0);

?>
