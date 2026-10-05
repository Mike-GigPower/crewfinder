<?php

	/*
	/* Backup call-out helpers — DESIGN-backup-callout-v0_2 §4, §5.
	/*
	/* Shared by respond-to-call.php, open-callout.php, close-callout.php and
	/* callout-sweep.php:
	/*
	/*   - the capacity lock (name, take, release)
	/*   - a call's start instant, Melbourne wall-clock
	/*   - goat_callout_close(), the one close used by all three close paths
	/*
	/* Requires $db (global.php). mysql_* / $db throughout, like the capacity
	/* path it shares a connection with (brief §0.1, decided 5 Oct).
	/*
	/* PHP 5.x — no ??, no short array syntax.
	*/

	if (!function_exists('goat_capacity_lock_name'))
	{

		/*
		/* THE CAPACITY LOCK — one MariaDB named lock serialising every write
		/* that can fill a call: accepts (respond-to-call.php), opening a
		/* call-out, and closing one.
		/*
		/* One name for all calls, not one per call: a package spans several
		/* calls, and per-call locks would have to be taken in a fixed order to
		/* avoid deadlock. These writes are short and infrequent, so a single
		/* name costs nothing at this volume.
		/*
		/* Test and prod share one MariaDB server, and named locks are
		/* server-wide — so the name carries the database, or a test accept
		/* could make a real crew member's accept wait.
		*/

		function goat_capacity_lock_name()
		{
			static $name = null;

			if ($name === null)
			{
				$res  = mysql_query('SELECT DATABASE()');
				$name = 'goat_capacity_' . preg_replace('/[^a-z0-9_]/i', '', $res !== false ? (string) mysql_result($res, 0) : '');
			}

			return $name;
		}

		/*
		/* Take the lock, waiting at most $timeout seconds. True only if
		/* GET_LOCK returned exactly 1 — 0 (timed out) and NULL (error) are
		/* both a refusal, and the caller must not proceed.
		/*
		/* 3 s is the default because Crew Hub gives up on a request at 10 s:
		/* an accept that got the lock after that would confirm someone who had
		/* already been shown an error. Holding it takes well under a second.
		/*
		/* On success a shutdown function is registered, so the lock is released
		/* on every exit — die, exit or a fatal — not only the normal path. The
		/* lock is tied to the connection as well, so it cannot outlive the
		/* request on a normal connection.
		*/

		function goat_capacity_lock($timeout = 3)
		{
			$res = mysql_query("SELECT GET_LOCK('" . mysql_real_escape_string(goat_capacity_lock_name()) . "', " . (int) $timeout . ")");
			$got = $res !== false ? mysql_result($res, 0) : null;

			if ((string) $got !== '1')
			{
				return false;
			}

			if (empty($GLOBALS['goat_capacity_lock_shutdown']))
			{
				register_shutdown_function('goat_capacity_unlock');
				$GLOBALS['goat_capacity_lock_shutdown'] = true;
			}

			$GLOBALS['goat_capacity_lock_held'] = true;

			return true;
		}

		/* Release if held. Safe to call any number of times. */

		function goat_capacity_unlock()
		{
			if (!empty($GLOBALS['goat_capacity_lock_held']))
			{
				mysql_query("SELECT RELEASE_LOCK('" . mysql_real_escape_string(goat_capacity_lock_name()) . "')");
				$GLOBALS['goat_capacity_lock_held'] = false;
			}
		}

		/*
		/* A call's start instant: start_date + start_time resolved in
		/* Australia/Melbourne, so correct whatever timezone the server runs in.
		/* Same computation as respond-to-call.php's time guard. false if the
		/* call is missing or the time will not parse.
		*/

		function goat_call_start_ts($callID)
		{
			global $db;

			$cRow = $db->selectFirst('start_date, start_time', 'calls', 'id=' . $db->sc((int) $callID));

			if (!$cRow)
			{
				return false;
			}

			try {
				$dt = new DateTime(date('Y-m-d', (int) $cRow->start_date) . ' ' . $cRow->start_time,
				                   new DateTimeZone('Australia/Melbourne'));
				return $dt->getTimestamp();
			} catch (Exception $e) {
				return false;
			}
		}

		/* The open call-out on a call, or null. */

		function goat_callout_open_for_call($callID)
		{
			global $db;

			$row = $db->selectFirst(
				'id, callID, opened_at, places',
				'call_callout',
				'callID=' . $db->sc((int) $callID) . ' AND closed_at IS NULL'
			);

			return $row ? $row : null;
		}

		/*
		/* CLOSE a call-out — the one function behind all three close paths
		/* (DESIGN §4.6):
		/*
		/*   'filled'  : the call filled — respond-to-call.php (an accept),
		/*               update-crew-status.php (ops), callout-sweep.php (catch-all)
		/*   'ops'     : close-callout.php, Close call-out in THE GOAT
		/*   'started' : callout-sweep.php, the call has started
		/*
		/* 1. Revert every member row still at 1 back to 7. Only rows with a
		/*    member row are touched: every row a call-out reset has one, and
		/*    nothing else does. A row no longer at 1 has been answered (or
		/*    changed by ops) and is left alone. No calendar rows are involved —
		/*    status 1 and 7 have none.
		/* 2. Stamp outcome 'reverted' on unanswered member rows now at 7.
		/* 3. Stamp closed_at and close_reason.
		/*
		/* In that order: if the request dies between steps, the call-out is
		/* still open and the next close finishes the job. Once closed it is
		/* never touched again, so running it twice changes nothing the second
		/* time — and a row ops later set to 1 by hand is never reverted by a
		/* stale close.
		/*
		/* Callers hold the capacity lock, so a close cannot interleave with an
		/* accept on the same rows. This function NEVER takes it itself: the
		/* fill-close in respond-to-call.php (and the 'empty' close in
		/* open-callout.php) already hold it, and MariaDB named locks nest per
		/* connection — a second GET_LOCK here would need a second release, and
		/* the caller's single unlock would leave it held.
		/*
		/* Returns array('changed' => bool, 'reverted' => n), or false on a
	/* database error (the call-out is then still open; retry is safe).
		*/

		function goat_callout_close($calloutID, $reason)
		{
			$calloutID = (int) $calloutID;
			$out       = array('changed' => false, 'reverted' => 0);

			$res = mysql_query('SELECT id FROM call_callout WHERE id=' . $calloutID . ' AND closed_at IS NULL');

			if ($res === false || !mysql_num_rows($res))
			{
				return $out;   /* unknown or already closed */
			}

			mysql_query('UPDATE call_crew_map m
			             JOIN call_callout_member cm ON cm.callID = m.callID AND cm.userID = m.userID
			             SET  m.status = 7
			             WHERE cm.callout_id = ' . $calloutID . ' AND m.status = 1');

			if (mysql_error())
			{
				return false;
			}

			$out['reverted'] = (int) mysql_affected_rows();

			mysql_query("UPDATE call_callout_member cm
			             JOIN call_crew_map m ON m.callID = cm.callID AND m.userID = cm.userID
			             SET  cm.outcome = 'reverted'
			             WHERE cm.callout_id = " . $calloutID . "
			               AND cm.outcome IS NULL
			               AND m.status = 7");

			if (mysql_error())
			{
				return false;
			}

			mysql_query("UPDATE call_callout
			             SET closed_at = " . time() . ", close_reason = '" . mysql_real_escape_string($reason) . "'
			             WHERE id = " . $calloutID . " AND closed_at IS NULL");

			if (mysql_error())
			{
				return false;
			}

			$out['changed'] = true;

			return $out;
		}

		/*
		/* Is this call at or over its number, counting CONFIRMED rows only?
		/*
		/* The SAME predicate as respond-to-call.php's goat_rtc_root_is_full —
		/* `>= required`, and required=0 reads as full — so every path that
		/* closes a call-out 'filled' agrees with the accept that decides who
		/* gets a place. If one changes, both must. Not memoised: callers ask
		/* once per call-out, after their own writes.
		*/

		function goat_callout_call_full($callID)
		{
			global $db;

			$callRow  = $db->selectFirst('required', 'calls', 'id=' . $db->sc((int) $callID));
			$required = $callRow ? (int) $callRow->required : 0;

			$res = mysql_query('SELECT COUNT(*) FROM call_crew_map WHERE status = 5 AND callID = ' . (int) $callID);
			$confirmed = $res !== false ? (int) mysql_result($res, 0) : 0;

			return $confirmed >= $required;
		}

		/*
		/* FILL CHECK on calls just written to 5 — close every OPEN call-out on
		/* any of them that is now full, reason 'filled'.
		/*
		/* This is what makes the close independent of WHO filled the call: a
		/* member accepting, a non-member accepting an ordinary offer, or ops
		/* setting someone to Confirmed in THE GOAT. Without it a call filled
		/* the second or third way kept its call-out open, and members sat at
		/* offered — with Crew Hub still showing them a call-out card — until
		/* ops closed it or the call started.
		/*
		/* Caller holds the capacity lock (see goat_callout_close). One query
		/* on idx_call_open, empty when no call-out is running; a failed query
		/* (tables missing, PHP ahead of the migration) closes nothing.
		/*
		/* Returns the ids of the call-outs it closed.
		*/

		function goat_callout_close_if_full($callIDs)
		{
			$closed = array();
			$ids    = array();

			foreach ((array) $callIDs as $c)
			{
				if ((int) $c > 0)
				{
					$ids[(int) $c] = true;
				}
			}

			if (!count($ids))
			{
				return $closed;
			}

			$res = mysql_query('SELECT id, callID FROM call_callout
			                    WHERE closed_at IS NULL AND callID IN (' . implode(',', array_keys($ids)) . ')');

			if ($res === false)
			{
				return $closed;
			}

			$open = array();

			while ($row = mysql_fetch_object($res))
			{
				$open[] = $row;
			}

			foreach ($open as $co)
			{
				if (goat_callout_call_full((int) $co->callID))
				{
					$r = goat_callout_close((int) $co->id, 'filled');

					if ($r !== false && $r['changed'])
					{
						$closed[] = (int) $co->id;
					}
				}
			}

			return $closed;
		}

		/*
		/* Did this answer come from a CALL-OUT — the `callout` field Crew Hub
		/* reads with backup:true to say "That place was filled just before
		/* you. You're still on standby." (brief Part F).
		/*
		/* True when the user has a member row on one of $callIDs (the calls
		/* just answered) in the MOST RECENT call-out on that call-out's own
		/* call, and either:
		/*   - that call-out is still open, or
		/*   - it closed 'filled' and this member's outcome is 'reverted'.
		/*
		/* The second case is the race loser, and it is the usual one. The
		/* fill-close reverts every unanswered member to 7 the moment the call
		/* fills, so a member who taps Accept a moment later — or in the same
		/* instant, queued on the lock — finds the call-out already closed.
		/* Matching open call-outs only would tell them nothing.
		/*
		/* Most recent only, so a member reverted by an old call-out is not
		/* told they lost a later one they were never part of.
		/*
		/* One query, on idx_user_call, with the "newer call-out?" probe on
		/* idx_call_open. Empty — false — when no call-out has ever involved
		/* this user on these calls, and when the tables are missing.
		*/

		function goat_callout_answer_flag($userID, $callIDs)
		{
			$ids = array();

			foreach ((array) $callIDs as $c)
			{
				if ((int) $c > 0)
				{
					$ids[(int) $c] = true;
				}
			}

			if ((int) $userID <= 0 || !count($ids))
			{
				return false;
			}

			$res = mysql_query("SELECT cc.closed_at, cc.close_reason, cm.outcome
			                    FROM call_callout_member cm
			                    JOIN call_callout cc ON cc.id = cm.callout_id
			                    WHERE cm.userID = " . (int) $userID . "
			                      AND cm.callID IN (" . implode(',', array_keys($ids)) . ")
			                      AND NOT EXISTS (SELECT 1 FROM call_callout n
			                                      WHERE n.callID = cc.callID AND n.id > cc.id)");

			if ($res === false)
			{
				return false;
			}

			while ($row = mysql_fetch_object($res))
			{
				if ($row->closed_at === null)
				{
					return true;
				}

				if ($row->close_reason === 'filled' && $row->outcome === 'reverted')
				{
					return true;
				}
			}

			return false;
		}

	}

?>
