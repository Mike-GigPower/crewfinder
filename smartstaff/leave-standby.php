<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include_once('goat-db.php');
	include_once('leave-standby-lib.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SELF endpoint — the acting crew member takes themselves OFF STANDBY.
	/* Status 7 (Backup) -> 13 (Left standby).
	/*
	/* Contract: POST callID. No status argument: there is only one direction.
	/*
	/*   D1  13 is NOT Declined (6). They accepted the offer and landed on
	/*       standby; withdrawing from standby is not a refusal and must not
	/*       count against them. get-crew-offer-stats.php counts 13 as
	/*       accepted; get-performance.php excludes it from delivered work.
	/*   D2  the seed call AND every locked-upstream call they are on standby
	/*       for move together — goat_leave_standby_scope(). Confirmed rows are
	/*       never touched.
	/*   D3  ops are not alerted. No push, no SMS, no ack row.
	/*   D7  no time cut-off. A backup can leave right up to the start, the same
	/*       as Decline. There is deliberately no started/cancelled guard here.
	/*
	/* WHY A NEW FILE rather than a third mode in respond-to-call.php: that
	/* file's guards, package peel and capacity lock all exist to decide whether
	/* an accept may FILL a call. Leaving standby can never fill one, so it
	/* needs none of them, and threading a third mode through would put a
	/* no-capacity path inside the capacity logic.
	/*
	/* NO CALENDAR WRITES. A row that reached 7 by accepting a full call has no
	/* calendars row, and an existing one (from an ops 5 -> 7 demotion, which
	/* update-crew-status.php deliberately leaves in place) is LEFT ALONE —
	/* exactly as a 7 -> 6 decline leaves it today. removeFromCalendar is for
	/* giving up a held place, and a backup holds none.
	/*
	/* NO CAPACITY CHECK, NO CALL-OUT CLOSE: this endpoint writes no 5, so no
	/* call can become full because of it.
	/*
	/* BUSINESS NO-OPS ARE HTTP 200 WITH ok:false. That is Crew Hub's house
	/* rule (respond-to-call.php's clash path, and respond-to-change.php's
	/* comment on it): the body is read even on a 200, and a 200+ok:false tells
	/* the client to refresh rather than to show an error. Only auth and real
	/* failures use 4xx/5xx.
	/*
	/* ACCESSOR: this file's own queries are mysqli_* on a connection with the
	/* credentials goat-db.php resolves, with NO charset set — the same choice
	/* reoffer-declined.php makes and for the same reason: the database default
	/* is latin1 and every other call-name reader sets none, so `changed_names`
	/* comes back as the bytes get-booking.php and my-backups.php emit.
	/* goat_leave_standby_scope() is mysql_* on the legacy connection. No
	/* transaction spans the two (MyISAM has none anyway); the `status = 7`
	/* guard on the UPDATE is what keeps a row that moved since the scope read
	/* from being rewritten.
	/*
	/* PHP 5.x -- array(), no null-coalescing (??), no short array syntax, tabs.
	/* No new mysql_* in this file.
	*/

	/* ---- helpers ---- */

	function ls_send_status($code, $msg)
	{
		$proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($proto . ' ' . $code . ' ' . $msg);
	}

	function ls_fail($code, $msg, $body)
	{
		ls_send_status($code, $msg);
		$out = json_encode($body);

		if ($out === false)
		{
			$out = '{"ok":false,"error":"request failed"}';
		}

		die($out);
	}

	/*
	/* A mysqli link with the credentials goat_pdo() resolves, and no charset,
	/* per the ACCESSOR note above. goat_db_find_setting() is the shared
	/* resolver in goat-db.php; only the link type differs from goat_pdo().
	*/

	function ls_mysqli()
	{
		$host = goat_db_find_setting(array(
			'GOAT_EMERGENCY_DB_HOST','GOAT_DB_HOST','DB_HOST','DBHOST','MYSQL_HOST','SQL_HOST','HOST'));
		$user = goat_db_find_setting(array(
			'GOAT_EMERGENCY_DB_USER','GOAT_DB_USER','DB_USER','DBUSER','DB_USERNAME','MYSQL_USER','SQL_USER','USER'));
		$pass = goat_db_find_setting(array(
			'GOAT_EMERGENCY_DB_PASS','GOAT_DB_PASS','DB_PASS','DBPASS','DB_PASSWORD','DBPASSWORD','MYSQL_PASS','SQL_PASS','PASS','PASSWORD'));
		$name = goat_db_find_setting(array(
			'GOAT_EMERGENCY_DB_NAME','GOAT_DB_NAME','DB_NAME','DBNAME','MYSQL_DB','SQL_DB','DB_DATABASE','DATABASE'));

		if ($host === null) { $host = 'localhost'; }

		if ($user === null || $pass === null || $name === null)
		{
			error_log('leave-standby: could not resolve DB credentials');
			return null;
		}

		mysqli_report(MYSQLI_REPORT_OFF);
		$link = @mysqli_connect($host, $user, $pass, $name);

		if (!$link)
		{
			error_log('leave-standby: connect failed: ' . mysqli_connect_error());
			return null;
		}

		return $link;
	}

	function ls_has_table($link, $table)
	{
		$r = mysqli_query($link, "SHOW TABLES LIKE '" . mysqli_real_escape_string($link, $table) . "'");
		return ($r !== false && mysqli_num_rows($r) > 0);
	}

	/* ---- auth: the acting crew member, own rows only ---- */

	$userID = (int) goat_acting_user_id();

	if ($userID <= 0)
	{
		ls_fail(403, 'Forbidden', array('ok' => false, 'error' => 'not authorised'));
	}

	/* ---- input ---- */

	$callID = isset($_POST['callID']) ? (int) $_POST['callID'] : 0;

	if ($callID <= 0)
	{
		ls_fail(400, 'Bad Request', array('ok' => false, 'error' => 'callID required'));
	}

	$link = ls_mysqli();

	if ($link === null)
	{
		ls_fail(500, 'Internal Server Error', array('ok' => false, 'error' => 'database unavailable'));
	}

	/*
	/* ---- seed row check ----
	/*
	/* Three business outcomes, all HTTP 200:
	/*
	/*   missing   -> ok:false not_on_call. Nothing to leave.
	/*   already13 -> ok:true  changed:false. Idempotent: a double tap, or a
	/*                retry after a response the client never saw, must not
	/*                look like a failure.
	/*   not 7     -> ok:false not_on_standby + current_status. They were
	/*                promoted (5) or called out (1) between the card being
	/*                drawn and the tap; the client refreshes and shows the
	/*                real state.
	*/

	$sres = mysqli_query($link,
		'SELECT status FROM call_crew_map
		  WHERE userID = ' . $userID . ' AND callID = ' . $callID . '
		  LIMIT 1');

	if ($sres === false)
	{
		ls_fail(500, 'Internal Server Error',
			array('ok' => false, 'error' => 'seed read failed: ' . mysqli_error($link)));
	}

	$seed = mysqli_fetch_assoc($sres);

	if (!$seed)
	{
		echo json_encode(array(
			'ok'            => false,
			'error'         => 'not_on_call',
			'callID'        => $callID,
			'changed'       => false,
			'changed_calls' => array(),
			'changed_names' => array(),
			'statuses'      => array()
		));
		die();
	}

	$seedStatus = (int) $seed['status'];

	if ($seedStatus === 13)
	{
		echo json_encode(array(
			'ok'            => true,
			'callID'        => $callID,
			'changed'       => false,
			'changed_calls' => array(),
			'changed_names' => array(),
			'statuses'      => array((string) $callID => 13)
		));
		die();
	}

	if ($seedStatus !== 7)
	{
		echo json_encode(array(
			'ok'             => false,
			'error'          => 'not_on_standby',
			'current_status' => $seedStatus,
			'callID'         => $callID,
			'changed'        => false,
			'changed_calls'  => array(),
			'changed_names'  => array(),
			'statuses'       => array()
		));
		die();
	}

	/* ---- scope (D2) ---- */

	$scope = goat_leave_standby_scope($userID, $callID);

	/*
	/* false, not empty: the legacy connection the scope runs on failed (the test
	/* site's known 18 Nov fault). Nothing has been written at this point. A 500
	/* is the honest answer — answering 200 not_on_standby would tell the crew
	/* member they are already off a call they are still on.
	*/
	if ($scope === false)
	{
		ls_fail(500, 'Internal Server Error',
			array('ok' => false, 'error' => 'scope read failed'));
	}

	if (!count($scope))
	{
		/*
		/* The seed read above said 7 and the scope says otherwise, so the row
		/* moved in between — or the two connections disagree. Treated as the
		/* stale case rather than an error: the client refreshes and sees the
		/* truth. current_status is omitted because we no longer know it.
		*/
		echo json_encode(array(
			'ok'            => false,
			'error'         => 'not_on_standby',
			'callID'        => $callID,
			'changed'       => false,
			'changed_calls' => array(),
			'changed_names' => array(),
			'statuses'      => array()
		));
		die();
	}

	$idList = implode(',', array_map('intval', $scope));

	/*
	/* ---- the write ----
	/*
	/* `status = 7` is LOAD-BEARING, not belt-and-braces. The scope was read on
	/* the other connection and a row can be promoted between that read and
	/* this statement; without the guard an ops promotion to 5 would be
	/* silently rewritten to 13, taking a confirmed crew member off a call they
	/* now hold.
	/*
	/* Success is judged by the error function, not affected_rows: a repeat call
	/* that moves nothing is not a failure. Which rows actually moved is settled
	/* by the re-SELECT below, not guessed from the count.
	*/

	$upd = mysqli_query($link,
		'UPDATE call_crew_map SET status = 13
		  WHERE status = 7 AND userID = ' . $userID . '
		    AND callID IN (' . $idList . ')');

	if ($upd === false)
	{
		ls_fail(500, 'Internal Server Error',
			array('ok' => false, 'error' => 'leave update failed: ' . mysqli_error($link)));
	}

	/* ---- which rows are now at 13, of the ones we aimed at ---- */

	$moved = array();
	$mres  = mysqli_query($link,
		'SELECT callID FROM call_crew_map
		  WHERE userID = ' . $userID . ' AND status = 13
		    AND callID IN (' . $idList . ')');

	if ($mres === false)
	{
		ls_fail(500, 'Internal Server Error',
			array('ok' => false, 'error' => 'moved read failed: ' . mysqli_error($link)));
	}

	while ($mrow = mysqli_fetch_assoc($mres))
	{
		$moved[] = (int) $mrow['callID'];
	}

	/*
	/* ---- history: call_standby_leave ----
	/*
	/* src: 'crew' for the call they tapped, 'cascade' for the upstream rows D2
	/* took with it. The distinction is the whole reason for the column — a
	/* cascade row is not a decision they made about that call.
	/*
	/* MyISAM: no transaction. Order is UPDATE, then history. If the insert
	/* fails the withdrawal STANDS (they are not left on standby believing they
	/* are off it) and the response says history:false, so the gap is visible
	/* rather than silent — the same choice expire-offer.php makes for
	/* offer_history.
	/*
	/* Degrades if MIGRATION-leave-standby.sql has not run. A1 ships before
	/* this file, so that should never happen; reporting it beats 500ing after
	/* the status write already succeeded.
	*/

	$history = false;

	if (count($moved) && ls_has_table($link, 'call_standby_leave'))
	{
		$now  = (int) time();
		$vals = array();

		foreach ($moved as $m)
		{
			$src    = ((int) $m === $callID) ? 'crew' : 'cascade';
			$vals[] = '(' . (int) $m . ',' . $userID . ',' . $now . ",'" . $src . "')";
		}

		$hres = mysqli_query($link,
			'INSERT INTO call_standby_leave (callID, userID, left_at, src)
			 VALUES ' . implode(',', $vals));

		if ($hres === false)
		{
			error_log('leave-standby: history insert failed for user ' . $userID
				. ' calls ' . implode(',', $moved) . ': ' . mysqli_error($link));
		}
		else
		{
			$history = true;
		}
	}

	/*
	/* ---- clear the time-change acknowledgements ----
	/*
	/* They are no longer waiting to hear about this call's timing, so the ack
	/* row would otherwise keep the row on Crew Hub's "owes a response" list
	/* and in THE GOAT's change_pending reads. Deleted only for the calls that
	/* actually moved.
	/*
	/* After the history insert: an ack that survives a failed history write is
	/* recoverable, a deleted one is not.
	*/

	if (count($moved))
	{
		$ares = mysqli_query($link,
			'DELETE FROM call_change_ack
			  WHERE userID = ' . $userID . '
			    AND callID IN (' . implode(',', array_map('intval', $moved)) . ')');

		if ($ares === false)
		{
			error_log('leave-standby: ack clear failed for user ' . $userID
				. ': ' . mysqli_error($link));
		}
	}

	/* ---- call names for the toast ---- */

	$changedNames = array();

	if (count($moved))
	{
		$nres = mysqli_query($link,
			'SELECT id, call_name FROM calls
			  WHERE id IN (' . implode(',', array_map('intval', $moved)) . ')');

		if ($nres !== false)
		{
			while ($nrow = mysqli_fetch_assoc($nres))
			{
				$changedNames[(int) $nrow['id']] = $nrow['call_name'];
			}
		}
	}

	/*
	/* Shaped like respond-to-call.php so Crew Hub can reuse its toast: ok,
	/* changed, changed_calls, changed_names, statuses. Keys in `statuses` are
	/* cast to strings so json_encode always emits an object — call ids are
	/* never a 0..n-1 sequence today, but relying on that is how a client ends
	/* up parsing an array.
	*/

	$statusMap = array();

	foreach ($moved as $m)
	{
		$statusMap[(string) $m] = 13;
	}

	echo json_encode(array(
		'ok'            => true,
		'callID'        => $callID,
		'changed'       => count($moved) > 0 ? true : false,
		'changed_calls' => $moved,
		'changed_names' => $changedNames,
		'statuses'      => $statusMap,
		'history'       => $history
	));

?>
