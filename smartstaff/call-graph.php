<?php

	/*
	/* Call graph helpers — traversal and capacity counting over `call_feeds`.
	/*
	/* An edge (source_call -> target_call) means "crew booked on source are
	/* also booked on target". Direction matters:
	/*
	/*   downstream(X) = everything X commits you to
	/*   upstream(X)   = everything that commits you to X
	/*
	/* A symmetric pair (A->B and B->A) is a cycle by design, so every
	/* traversal uses a visited set. There is no cycle rejection at write time.
	/*
	/* Every traversal INNER JOINs `calls` so that edges left dangling by a
	/* deleted call are invisible (sss::deleteCall does not know about
	/* call_feeds — see DESIGN §11.3).
	/*
	/* PHP 5.x — no ??, no short array syntax.
	*/

	if (!function_exists('goat_feed_step'))
	{

		/*
		/* Does call_feeds have the `mode` column yet?
		/*
		/* Checked once per request and cached in a static. If the migration
		/* has not run, every mode-filtered query would fail and return empty —
		/* which does NOT merely disable the new feature, it collapses
		/* goat_user_package to a single call and silently stops migrated
		/* linked calls from cascading. Falling back to "no filter" instead
		/* means every edge behaves as locked, which is exactly v4.11.0.
		/*
		/* This also makes the migration's rollback (DROP COLUMN mode) safe on
		/* its own, without a matching PHP rollback.
		*/

		function goat_feeds_have_mode()
		{
			static $has = null;

			if ($has !== null)
			{
				return $has;
			}

			$has = false;
			$res = mysql_query("SHOW COLUMNS FROM `call_feeds` LIKE 'mode'");

			if ($res !== false && mysql_num_rows($res) > 0)
			{
				$has = true;
			}

			return $has;
		}

		/* One BFS hop. $ids is an array of ints, $direction is 'down' or 'up',
		/* $mode is 'locked', 'recommended' or 'any'.
		/*
		/* $mode DEFAULTS TO 'locked'. Any caller not explicitly updated keeps
		/* locked-only semantics, which is the safe failure: a missed 'any'
		/* leaves the recommended feature incomplete, whereas a missed 'locked'
		/* would let recommended edges cascade and commit crew to calls they
		/* never agreed to.
		*/

		function goat_feed_step($ids, $direction, $mode = 'locked')
		{
			if (!is_array($ids) || !count($ids))
			{
				return array();
			}

			$clean = array();

			foreach ($ids as $v)
			{
				$n = (int) $v;
				if ($n > 0)
				{
					$clean[] = $n;
				}
			}

			if (!count($clean))
			{
				return array();
			}

			$list = implode(',', $clean);

			$modeSql = '';

			if (goat_feeds_have_mode())
			{
				if ($mode === 'locked')
				{
					$modeSql = " AND f.mode = 'locked'";
				}
				else if ($mode === 'recommended')
				{
					$modeSql = " AND f.mode = 'recommended'";
				}
				/* 'any' adds nothing */
			}
			/* No column: no filter. Every edge reads as locked — v4.11.0
			/* behaviour — rather than every query returning nothing. */

			if ($direction === 'down')
			{
				$sql = "SELECT DISTINCT f.target_call AS n
				        FROM call_feeds f
				        INNER JOIN calls c ON c.id = f.target_call
				        WHERE f.source_call IN (" . $list . ")" . $modeSql;
			}
			else
			{
				$sql = "SELECT DISTINCT f.source_call AS n
				        FROM call_feeds f
				        INNER JOIN calls c ON c.id = f.source_call
				        WHERE f.target_call IN (" . $list . ")" . $modeSql;
			}

			$res = mysql_query($sql);
			$out = array();

			if ($res !== false)
			{
				while ($row = mysql_fetch_object($res))
				{
					$out[] = (int) $row->n;
				}
			}

			return $out;
		}

		/* Transitive closure in one direction. Excludes the starting call.
		/* Depth capped at 10 as a runaway guard — a legitimate feed chain is
		/* two or three deep. */

		function goat_calls_traverse($callID, $direction, $mode = 'locked')
		{
			$callID = (int) $callID;

			if ($callID <= 0)
			{
				return array();
			}

			$seen     = array($callID => true);
			$frontier = array($callID);
			$depth    = 0;

			while (count($frontier) && $depth < 10)
			{
				$next     = goat_feed_step($frontier, $direction, $mode);
				$frontier = array();

				foreach ($next as $n)
				{
					if (!isset($seen[$n]))
					{
						$seen[$n]   = true;
						$frontier[] = $n;
					}
				}

				$depth++;
			}

			unset($seen[$callID]);

			return array_keys($seen);
		}

		function goat_calls_downstream($callID, $mode = 'locked')
		{
			return goat_calls_traverse($callID, 'down', $mode);
		}

		function goat_calls_upstream($callID, $mode = 'locked')
		{
			return goat_calls_traverse($callID, 'up', $mode);
		}

		/* Immediate targets of $callID.
		/*
		/* NOTE THE DEFAULT: locked only. Callers wanting the recommended
		/* targets (the Finder ranking set) must pass 'recommended'
		/* explicitly, and callers wanting everything must pass 'any'.
		/* Getting this wrong yields a quietly short list, not an error. */

		function goat_call_immediate_targets($callID, $mode = 'locked')
		{
			return goat_feed_step(array((int) $callID), 'down', $mode);
		}

		/* Immediate feeders of $callID with the mode of each edge INTO this
		/* call. Returns array(call_id => 'locked'|'recommended').
		/*
		/* The counting maths needs both the id and the mode, and needs every
		/* feeder regardless of mode — see goat_call_feed_counts_with. */

		function goat_call_feeders_modes($callID)
		{
			$callID = (int) $callID;
			$out    = array();

			if ($callID <= 0)
			{
				return $out;
			}

			$sel = goat_feeds_have_mode() ? 'f.mode' : "'locked'";

			$res = mysql_query("SELECT f.source_call AS n, " . $sel . " AS m
			                    FROM call_feeds f
			                    INNER JOIN calls c ON c.id = f.source_call
			                    WHERE f.target_call = " . $callID);

			if ($res !== false)
			{
				while ($row = mysql_fetch_object($res))
				{
					$out[(int) $row->n] = $row->m;
				}
			}

			return $out;
		}

		/*
		/* The crew member's PACKAGE: the connected component (edges treated as
		/* undirected) over calls this user currently holds an OFFERED row on
		/* (status <= 1). Always includes $callID itself, even if unheld, so
		/* callers can rely on a non-empty result.
		*/

		function goat_user_package($userID, $callID)
		{
			$userID = (int) $userID;
			$callID = (int) $callID;

			if ($userID <= 0 || $callID <= 0)
			{
				return array($callID);
			}

			/* calls this user holds an offered row on */

			$held = array();
			$res  = mysql_query("SELECT callID FROM call_crew_map
			                     WHERE userID = " . $userID . " AND status <= 1");

			if ($res !== false)
			{
				while ($row = mysql_fetch_object($res))
				{
					$held[(int) $row->callID] = true;
				}
			}

			$seen     = array($callID => true);
			$frontier = array($callID);
			$depth    = 0;

			while (count($frontier) && $depth < 10)
			{
				/* LOCKED ONLY (the default). A recommended edge does not
				/* commit the crew member to anything, so its calls are
				/* answered independently and must never join a package. */
				$next = array_merge(
					goat_feed_step($frontier, 'down'),
					goat_feed_step($frontier, 'up')
				);

				$frontier = array();

				foreach ($next as $n)
				{
					if (!isset($seen[$n]) && isset($held[$n]))
					{
						$seen[$n]   = true;
						$frontier[] = $n;
					}
				}

				$depth++;
			}

			return array_keys($seen);
		}

		/*
		/* Capacity. `calls.booked` is NOT maintained (addToCall updates
		/* `ordered`, which counts declined rows too), so committed is a live
		/* count. Statuses 0/1/2/5 = every crew member whose downstream rows
		/* were created at offer time. 6 (declined) and 7 (backup) excluded.
		/*
		/* Index KEY idx_ccm_call_status (callID, status) serves this directly.
		*/

		function goat_call_committed($callID)
		{
			$res = mysql_query("SELECT COUNT(*) AS n FROM call_crew_map
			                    WHERE callID = " . ((int) $callID) . "
			                      AND status IN (0,1,2,5)");

			if ($res === false)
			{
				return 0;
			}

			$row = mysql_fetch_object($res);

			return $row ? (int) $row->n : 0;
		}

		function goat_call_required($callID)
		{
			$res = mysql_query("SELECT required FROM calls WHERE id = " . ((int) $callID));

			if ($res === false)
			{
				return 0;
			}

			$row = mysql_fetch_object($res);

			return $row ? (int) $row->required : 0;
		}

		/*
		/* Reduce a feeder list to its MAXIMAL members — those not upstream of
		/* another feeder in the list.
		/*
		/* Crew sets nest along edges (S -> S' means crew(S) is a subset of
		/* crew(S')), so a feeder that reaches another feeder contributes no
		/* additional bodies. Mutually-reachable feeders share one crew set —
		/* which is what every migrated symmetric link looks like — so exactly
		/* one representative is kept, the lowest call id. Dropping both would
		/* report reserved 0 for every migrated group of three or more.
		/*
		/* Reachability runs at 'any': a RECOMMENDED edge still creates rows, so
		/* crew sets nest regardless of mode. Reducing each mode separately
		/* would double-count the same bodies.
		/*
		/* Returns array('keep' => [ids], 'lostTo' => array(droppedId => keptId))
		/* so callers can apply the mixed-nest rule (DESIGN §5.2).
		*/

		function goat_maximal_feeders($feeders)
		{
			$keep   = array();
			$lostTo = array();

			foreach ($feeders as $f)
			{
				$downF = goat_calls_downstream($f, 'any');
				$drop  = false;

				foreach ($feeders as $g)
				{
					if ($f === $g)
					{
						continue;
					}

					if (!in_array($g, $downF))
					{
						continue;   /* f does not reach g */
					}

					$downG  = goat_calls_downstream($g, 'any');
					$mutual = in_array($f, $downG);

					if (!$mutual)
					{
						$drop        = true;   /* f is strictly upstream of g */
						$lostTo[$f]  = $g;
						break;
					}

					if ($g < $f)
					{
						$drop        = true;   /* same mutual group, keep lowest id */
						$lostTo[$f]  = $g;
						break;
					}
				}

				if (!$drop)
				{
					$keep[] = $f;
				}
			}

			return array('keep' => $keep, 'lostTo' => $lostTo);
		}

		function goat_call_feed_counts($callID)
		{
			return goat_call_feed_counts_with($callID, 0);
		}

		/*
		/* Capacity, split by firmness:
		/*
		/*   reserved(T) = unfilled slots on maximal LOCKED feeders
		/*   likely(T)   = unfilled slots on maximal RECOMMENDED feeders
		/*   free_to_fill = required - committed - reserved - likely
		/*
		/* likely IS subtracted, so the parts sum to required and free_to_fill
		/* is the number ops can safely book. The split tells them how much of
		/* what is held is soft, so they can over-book deliberately.
		/*
		/* MIXED NESTS (DESIGN §5.2): when the reduction drops a feeder, the
		/* survivor inherits the FIRMER mode. If any dropped feeder's own edge
		/* into this call was locked, the survivor counts as reserved. Example:
		/* A and B both feed T, A -> B exists, A -> T is locked and B -> T is
		/* recommended. A is strictly upstream of B so A drops — without this
		/* rule A's genuinely committed crew would be reported as merely likely.
		/* Over-stating reserved is the safe direction: it is the number ops
		/* read when deciding whether to book more.
		/*
		/* $extraSource / $extraMode splice in a not-yet-written edge so
		/* call-feeds.php can answer "what would this edge do?" without writing
		/* it — MyISAM has no transactions, so insert-and-roll-back is not an
		/* option.
		/*
		/* Reachability is computed on the REAL graph, so the proposed edge is
		/* not itself traversable. This can under-detect in cyclic
		/* configurations (where the target feeds back into a feeder via a
		/* migrated symmetric link). Accepted: the check is advisory and
		/* overridable, and the persistent flag catches the result either way.
		/*
		/* free_to_fill may be negative: that is the over-subscription signal
		/* (DESIGN §3.6) and must not be clamped.
		*/

		function goat_call_feed_counts_with($callID, $extraSource, $extraMode = 'locked')
		{
			$callID      = (int) $callID;
			$extraSource = (int) $extraSource;
			$required    = goat_call_required($callID);
			$committed   = goat_call_committed($callID);
			$modeOf      = goat_call_feeders_modes($callID);

			if ($extraSource > 0 && $extraSource !== $callID && !isset($modeOf[$extraSource]))
			{
				$modeOf[$extraSource] = ($extraMode === 'recommended') ? 'recommended' : 'locked';
			}

			$feeders = array_keys($modeOf);
			$red     = goat_maximal_feeders($feeders);
			$keep    = $red['keep'];
			$lostTo  = $red['lostTo'];

			/* mixed-nest rule: a survivor that absorbed a locked feeder counts
			/* as locked, whatever its own edge mode */

			$firm = array();

			foreach ($lostTo as $dropped => $survivor)
			{
				if (isset($modeOf[$dropped]) && $modeOf[$dropped] === 'locked')
				{
					$firm[$survivor] = true;
				}
			}

			$reserved = 0;
			$likely   = 0;

			foreach ($keep as $s)
			{
				$gap = goat_call_required($s) - goat_call_committed($s);

				if ($gap <= 0)
				{
					continue;
				}

				$isLocked = (isset($modeOf[$s]) && $modeOf[$s] === 'locked') || isset($firm[$s]);

				if ($isLocked)
				{
					$reserved += $gap;
				}
				else
				{
					$likely += $gap;
				}
			}

			return array(
				'required'     => $required,
				'committed'    => $committed,
				'reserved'     => $reserved,
				'likely'       => $likely,
				'free_to_fill' => $required - $committed - $reserved - $likely
			);
		}

		/* 'HH:MM:SS' -> seconds since midnight. calls.start_time is a TIME
		/* column, so this is a plain parse, not a timezone conversion. */

		function goat_time_to_secs($t)
		{
			$parts = explode(':', (string) $t);
			$h = isset($parts[0]) ? (int) $parts[0] : 0;
			$m = isset($parts[1]) ? (int) $parts[1] : 0;
			$s = isset($parts[2]) ? (int) $parts[2] : 0;

			return ($h * 3600) + ($m * 60) + $s;
		}

		/* Stable, derived package identity: the lowest call id in the package.
		/* Not stored — recomputed per read, which is correct because package
		/* composition changes as rows are answered. */

		function goat_package_id($pkg)
		{
			if (!is_array($pkg) || !count($pkg))
			{
				return null;
			}

			$ids = array();

			foreach ($pkg as $p)
			{
				$ids[] = (int) $p;
			}

			sort($ids);

			return 'pkg_' . $ids[0];
		}

		/* What accepting this call commits the crew member to — the downstream
		/* closure, with enough detail for the Hub to write the sentence. */

		function goat_commits_to($callID)
		{
			$down = goat_calls_downstream($callID);

			if (!count($down))
			{
				return array();
			}

			$res = mysql_query("SELECT id, call_name, start_date, start_time
			                    FROM calls WHERE id IN (" . implode(',', $down) . ")
			                    ORDER BY start_date ASC, start_time ASC");

			$out = array();

			if ($res !== false)
			{
				while ($row = mysql_fetch_object($res))
				{
					$dateStr = date('Y-m-d', (int) $row->start_date);

					$out[] = array(
						'call_id'   => (int) $row->id,
						'call_name' => $row->call_name,
						'start'     => date('Y-m-d\TH:i:s', strtotime($dateStr . ' ' . $row->start_time))
					);
				}
			}

			return $out;
		}

		/*
		/* What declining $callID will actually touch for this crew member.
		/*
		/* Mirrors respond-to-call.php's decline resolution exactly — and is
		/* now the single source of it, so the Crew Hub prompt and the endpoint
		/* cannot disagree. A warning that no longer matches what the endpoint
		/* does is worse than no warning.
		/*
		/* Scope:
		/*   - the PACKAGE (locked, offered rows the crew member holds)
		/*   - PLUS every transitively-upstream call they hold a row on,
		/*     including CONFIRMED ones, whose commitment the decline breaks
		/*
		/* Downstream rows held independently are NOT included: being on the
		/* load out never required being on the load-in.
		/*
		/* Returns array(call_id => status_int) for every call that would move,
		/* INCLUDING the seed call. Callers that want "what else" should unset
		/* the seed.
		*/

		function goat_decline_scope($userID, $callID)
		{
			$userID = (int) $userID;
			$callID = (int) $callID;

			if ($userID <= 0 || $callID <= 0)
			{
				return array();
			}

			/* every row this crew member holds, with status */

			$heldStatus = array();
			$hres = mysql_query("SELECT callID, status FROM call_crew_map
			                     WHERE userID = " . $userID);

			if ($hres !== false)
			{
				while ($hrow = mysql_fetch_object($hres))
				{
					$heldStatus[(int) $hrow->callID] = (int) $hrow->status;
				}
			}

			$package = goat_user_package($userID, $callID);
			$scope   = array();

			foreach ($package as $pc)
			{
				$scope[$pc] = isset($heldStatus[$pc]) ? $heldStatus[$pc] : 0;
			}

			/* upstream holders — their commitment is broken by the decline */

			foreach ($package as $pc)
			{
				$ups = goat_calls_upstream($pc);   /* locked only, by default */

				foreach ($ups as $u)
				{
					if (!isset($heldStatus[$u]))
					{
						continue;   /* not their row */
					}

					if (isset($scope[$u]))
					{
						continue;   /* already in the set */
					}

					$scope[$u] = $heldStatus[$u];
				}
			}

			return $scope;
		}

		/*
		/* What the crew member loses if they decline this call — the decline
		/* scope minus the call itself, with enough detail for the Hub to name
		/* each one and flag which were already accepted.
		/*
		/* `confirmed` is the important flag: withdrawing an OFFERED row is
		/* unremarkable, but withdrawing a CONFIRMED one takes back a shift they
		/* had already planned around, and the prompt should say so plainly.
		*/

		function goat_declining_withdraws($userID, $callID)
		{
			$scope = goat_decline_scope($userID, $callID);

			unset($scope[(int) $callID]);

			if (!count($scope))
			{
				return array();
			}

			$ids = array_keys($scope);

			$res = mysql_query("SELECT id, call_name, start_date, start_time
			                    FROM calls WHERE id IN (" . implode(',', $ids) . ")
			                    ORDER BY start_date ASC, start_time ASC");

			$out = array();

			if ($res !== false)
			{
				while ($row = mysql_fetch_object($res))
				{
					$cid     = (int) $row->id;
					$dateStr = date('Y-m-d', (int) $row->start_date);

					$out[] = array(
						'call_id'   => $cid,
						'call_name' => $row->call_name,
						'start'     => date('Y-m-d\TH:i:s', strtotime($dateStr . ' ' . $row->start_time)),
						'confirmed' => (isset($scope[$cid]) && ($scope[$cid] == 5 || $scope[$cid] == 7)) ? true : false
					);
				}
			}

			return $out;
		}

	}

?>
