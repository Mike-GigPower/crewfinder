<?php

	/*
	/* Leave-standby scope — the one definition of "which rows come off standby
	/* when this crew member taps Take me off standby".
	/*
	/* D2: the seed call, plus every call UPSTREAM of it on the locked feed
	/* chain, keeping only calls where THIS user's row is status 7 (Backup).
	/* Upstream, because a standby on the load-in that feeds a load out could
	/* never be promoted once they are off the load out. Rows at any other
	/* status are left alone, and CONFIRMED rows are never touched.
	/*
	/* WHY A SHARED FILE. my-backups.php shows the warning ("this also takes you
	/* off standby for the load-in") and leave-standby.php performs the write.
	/* If those two computed the scope separately they could disagree, and the
	/* crew member would be warned about one call and lose another. Same reason
	/* goat_decline_scope is shared between respond-to-call.php and
	/* goat_declining_withdraws(). respond-to-change.php uses it too (S5), so a
	/* backup's "No longer available" drops exactly the same set.
	/*
	/* ACCESSOR: mysql_* on the legacy connection, the same as the
	/* call-graph.php helpers this builds on. my-backups.php and
	/* respond-to-change.php are mysql_* too, so for them this is same-accessor
	/* and same-connection. leave-standby.php is mysqli and therefore calls
	/* across accessors — the pattern reoffer-declined.php documents at its
	/* ACCESSOR note: no transaction spans the two, and the `status = 7` guard
	/* on that endpoint's UPDATE is what protects a row that moved between this
	/* read and the write.
	/*
	/* PHP 5.x -- mysql_*, array(), no null-coalescing (??), no short array
	/* syntax, tabs.
	*/

	include_once('call-graph.php');

	if (!function_exists('goat_leave_standby_scope'))
	{

		/*
		/* Call ids this user is on standby for, reachable from $callID by the
		/* locked feed chain going UP, plus $callID itself. The seed is first.
		/*
		/* THREE RETURNS, and the difference matters:
		/*
		/*   false    the status read FAILED. Not "they hold nothing" — we do not
		/*            know what they hold. Callers must refuse, not carry on.
		/*   array()  the read worked and they hold no status-7 row on the seed
		/*            call (missing row, or another status). The caller has
		/*            normally classified the seed already (A2 sends not_on_call
		/*            / not_on_standby); this is a second line of defence.
		/*   array(…) the scope, seed first.
		/*
		/* The false case is not hypothetical. On the test site the legacy
		/* mysql_* connection has been seen to drop after the cohort check (18
		/* Nov, cause unknown). Folding that into array() would make a dead
		/* connection indistinguishable from "you are not on standby" — the
		/* endpoint would answer 200 not_on_standby about a row sitting at 7, and
		/* respond-to-change.php would clear a crew member's ack while leaving
		/* them on standby. Both are worse than an honest 500.
		/*
		/* NOT goat_user_package: that walk treats locked edges as undirected
		/* and would reach calls DOWNSTREAM of the seed as well. Leaving standby
		/* on a load-in says nothing about the load out it feeds — they may
		/* still want that one, and it can still be promoted.
		*/

		function goat_leave_standby_scope($userID, $callID)
		{
			$userID = (int) $userID;
			$callID = (int) $callID;

			if ($userID <= 0 || $callID <= 0)
			{
				return array();
			}

			/* every call this user is on standby for */

			$res = mysql_query("SELECT callID FROM call_crew_map
			                    WHERE userID = " . $userID . " AND status = 7");

			if ($res === false)
			{
				error_log('goat_leave_standby_scope: standby read failed for user '
					. $userID . ': ' . mysql_error());
				return false;
			}

			$standby = array();

			while ($row = mysql_fetch_object($res))
			{
				$standby[(int) $row->callID] = true;
			}

			if (!isset($standby[$callID]))
			{
				return array();
			}

			$scope = array($callID);

			/*
			/* goat_calls_upstream walks the whole locked chain from the seed,
			/* not just the immediate feeders, and carries its own visited set
			/* — so a symmetric A->B/B->A pair cannot loop here. Default mode is
			/* 'locked'; a recommended edge never joins a package.
			*/

			$ups = goat_calls_upstream($callID);

			foreach ($ups as $u)
			{
				$u = (int) $u;

				if ($u === $callID)
				{
					continue;
				}

				if (isset($standby[$u]))
				{
					$scope[] = $u;
				}
			}

			return $scope;
		}

		/*
		/* The same scope minus the seed, with call names — what my-backups.php
		/* emits as `also_leaves` so the Crew Hub confirm sheet can name each
		/* call it is about to drop.
		/*
		/* Built ON goat_leave_standby_scope rather than beside it, so the
		/* warning and the write cannot drift.
		/*
		/* Names come off the legacy connection with no charset set, which is
		/* how get-booking.php and my-backups.php's own SELECT read them — so
		/* the bytes match the rest of the payload.
		*/

		function goat_leave_standby_also($userID, $callID)
		{
			$scope = goat_leave_standby_scope($userID, $callID);
			$also  = array();

			/*
			/* A failed read degrades to "nothing else comes with it" HERE, unlike
			/* the write paths, which refuse. This is a preview on a page whose own
			/* roster query runs on the same connection a moment earlier — so if
			/* that connection were dead my-backups.php would already have 500ed
			/* and this line would never run. An empty preview is also the safe
			/* direction for a warning: the endpoint recomputes the scope before
			/* writing anything, so a missing warning cannot widen what is written.
			*/
			if ($scope === false || count($scope) < 2)
			{
				return $also;
			}

			$others = array();

			foreach ($scope as $s)
			{
				if ((int) $s !== (int) $callID)
				{
					$others[] = (int) $s;
				}
			}

			if (!count($others))
			{
				return $also;
			}

			$res = mysql_query("SELECT id, call_name FROM calls
			                    WHERE id IN (" . implode(',', $others) . ")
			                    ORDER BY start_date ASC, start_time ASC");

			if ($res === false)
			{
				return $also;
			}

			while ($row = mysql_fetch_object($res))
			{
				$also[] = array(
					'call_id'   => (int) $row->id,
					'call_name' => $row->call_name
				);
			}

			return $also;
		}

	}

?>
