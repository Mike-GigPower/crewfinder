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

		/* One BFS hop. $ids is an array of ints, $direction is 'down' or 'up'. */

		function goat_feed_step($ids, $direction)
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

			if ($direction === 'down')
			{
				$sql = "SELECT DISTINCT f.target_call AS n
				        FROM call_feeds f
				        INNER JOIN calls c ON c.id = f.target_call
				        WHERE f.source_call IN (" . $list . ")";
			}
			else
			{
				$sql = "SELECT DISTINCT f.source_call AS n
				        FROM call_feeds f
				        INNER JOIN calls c ON c.id = f.source_call
				        WHERE f.target_call IN (" . $list . ")";
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

		function goat_calls_traverse($callID, $direction)
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
				$next     = goat_feed_step($frontier, $direction);
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

		function goat_calls_downstream($callID)
		{
			return goat_calls_traverse($callID, 'down');
		}

		function goat_calls_upstream($callID)
		{
			return goat_calls_traverse($callID, 'up');
		}

		/* Immediate feeders only — used by the reserved-slot maths, which must
		/* not walk transitively (a middle call's `required` already accounts
		/* for its own feeders). */

		function goat_call_immediate_feeders($callID)
		{
			return goat_feed_step(array((int) $callID), 'up');
		}

		function goat_call_immediate_targets($callID)
		{
			return goat_feed_step(array((int) $callID), 'down');
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
		*/

		function goat_maximal_feeders($feeders)
		{
			$keep = array();

			foreach ($feeders as $f)
			{
				$downF = goat_calls_downstream($f);
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

					$downG  = goat_calls_downstream($g);
					$mutual = in_array($f, $downG);

					if (!$mutual)
					{
						$drop = true;   /* f is strictly upstream of g */
						break;
					}

					if ($g < $f)
					{
						$drop = true;   /* same mutual group, keep the lowest id */
						break;
					}
				}

				if (!$drop)
				{
					$keep[] = $f;
				}
			}

			return $keep;
		}

		function goat_call_feed_counts($callID)
		{
			return goat_call_feed_counts_with($callID, 0);
		}

		/*
		/* As goat_call_feed_counts, but treats $extraSource as an ADDITIONAL
		/* immediate feeder that is not yet in the database. Used by
		/* call-feeds.php to answer "what would this edge do?" without writing
		/* it — MyISAM has no transactions, so insert-and-roll-back is not an
		/* option.
		/*
		/* Reachability is computed on the REAL graph, so the proposed edge is
		/* not itself traversable. This can under-detect in cyclic
		/* configurations (where the target feeds back into a feeder via a
		/* migrated symmetric link). Accepted: the check is advisory and
		/* overridable, and the persistent flag catches the result either way.
		/*
		/* free_to_fill may be negative — that is the over-subscription signal
		/* (DESIGN §3.6) and must not be clamped.
		*/

		function goat_call_feed_counts_with($callID, $extraSource)
		{
			$callID      = (int) $callID;
			$extraSource = (int) $extraSource;
			$required    = goat_call_required($callID);
			$committed   = goat_call_committed($callID);
			$feeders     = goat_call_immediate_feeders($callID);

			if ($extraSource > 0 && $extraSource !== $callID && !in_array($extraSource, $feeders))
			{
				$feeders[] = $extraSource;
			}

			$keep     = goat_maximal_feeders($feeders);
			$reserved = 0;

			foreach ($keep as $s)
			{
				$gap = goat_call_required($s) - goat_call_committed($s);

				if ($gap > 0)
				{
					$reserved += $gap;
				}
			}

			return array(
				'required'     => $required,
				'committed'    => $committed,
				'reserved'     => $reserved,
				'free_to_fill' => $required - $committed - $reserved
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

	}

?>
