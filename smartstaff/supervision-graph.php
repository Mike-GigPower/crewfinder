<?php

	/*
	/* Supervision graph helpers — traversal over `call_supervision`.
	/*
	/* An edge (boss_call -> child_call) means "whoever bosses `boss_call`
	/* oversees `child_call`".
	/*
	/* WHAT IT DOES NOT MEAN. A supervision edge grants VISIBILITY and
	/* AUTHORISATION and nothing else. It never books anyone onto anything,
	/* it never moves a crew row, and it does not cascade. This is the one
	/* sentence to read before assuming it behaves like a feed — it does not.
	/*
	/* THREE LINK CONCEPTS NOW COEXIST ON `calls` (A0 §2.4):
	/*
	/*   link_group       vestigial. Superseded by call_feeds; left in place
	/*                    as the rollback path for that migration.
	/*   call_feeds       LIVE and BEHAVIOURAL. source -> target commits crew
	/*                    booked on source to target. See call-graph.php.
	/*   call_supervision THIS TABLE. Authorisation only. No behaviour.
	/*
	/* WHY `uniq_child` IS SINGLE-COLUMN. call_feeds uses
	/* UNIQUE (source_call, target_call) because a call may be fed by many
	/* calls. call_supervision uses UNIQUE (child_call) ALONE because Q10
	/* decided one boss call per supervised call. Anyone diffing the two
	/* tables will read this as a copy-paste error. It is not.
	/*
	/* DEPENDENCY DIRECTION — ONE WAY ONLY.
	/*
	/*   supervision-graph.php  ──include_once──▶  resolve-call-contact.php
	/*
	/* This file depends on resolve-call-contact.php, for
	/* goat_is_boss_call_name(). NEVER the reverse. resolve-call-contact.php
	/* declares its functions at top level with no function_exists guard, so
	/* an include in the other direction is a fatal redeclare, not a no-op.
	/* Callers there guard at the CALL SITE instead:
	/*
	/*   $bossCallID = function_exists('goat_supervision_boss_call')
	/*               ? goat_supervision_boss_call($callID)
	/*               : 0;
	/*
	/* which makes the Q12 fallback structural: if this file is not loaded,
	/* resolve-call-contact.php behaves exactly as it does today. The
	/* fallback is the shape of the dependency, not a branch to remember.
	/*
	/* Every query INNER JOINs `calls`, so edges left dangling by a deleted
	/* call are invisible. sss::deleteCall does not know this table exists,
	/* exactly as it does not know about call_feeds.
	/*
	/* Every function returns array() or 0 on EVERY failure path — never
	/* false, never null. Callers count() and in_array() the result.
	/*
	/* PHP 5.x — mysql_*, no ??, no short array syntax.
	*/

	if (!function_exists('goat_supervision_boss_call'))
	{

		include_once('resolve-call-contact.php');

		/*
		/* The boss call supervising $childCall, or 0.
		/*
		/* LIMIT 1 is safe because of uniq_child. BOTH ends are INNER JOINed:
		/* a surviving row pointing at a deleted boss call OR a deleted child
		/* call resolves to 0, the same answer as "no edge" — deliberately
		/* indistinguishable. Joining only boss_call was the original bug:
		/* a dangling child still found its boss, contradicting the file
		/* header's claim that dangling edges are invisible.
		/*
		/* Cached per request: the contact resolver already caches per
		/* (callID, viewerUserID) and a shift list can carry the same call
		/* twice.
		*/

		function goat_supervision_boss_call($childCall)
		{
			static $cache = array();

			$childCall = (int) $childCall;

			if ($childCall <= 0)
			{
				return 0;
			}

			if (isset($cache[$childCall]))
			{
				return $cache[$childCall];
			}

			$cache[$childCall] = 0;

			$res = mysql_query("SELECT s.boss_call AS n
			                    FROM call_supervision s
			                    INNER JOIN calls cb ON cb.id = s.boss_call
			                    INNER JOIN calls cc ON cc.id = s.child_call
			                    WHERE s.child_call = " . $childCall . "
			                    LIMIT 1");

			if ($res !== false)
			{
				$row = mysql_fetch_object($res);

				if ($row)
				{
					$cache[$childCall] = (int) $row->n;
				}
			}

			return $cache[$childCall];
		}

		/*
		/* Every call supervised by $bossCall, ordered by call time so callers
		/* do not have to re-sort. Empty array on any failure.
		*/

		function goat_supervision_children($bossCall)
		{
			$bossCall = (int) $bossCall;
			$out      = array();

			if ($bossCall <= 0)
			{
				return $out;
			}

			$res = mysql_query("SELECT s.child_call AS n
			                    FROM call_supervision s
			                    INNER JOIN calls c ON c.id = s.child_call
			                    WHERE s.boss_call = " . $bossCall . "
			                    ORDER BY c.start_date ASC, c.start_time ASC");

			if ($res !== false)
			{
				while ($row = mysql_fetch_object($res))
				{
					$out[] = (int) $row->n;
				}
			}

			return $out;
		}

		/*
		/* The DEDICATED BOSS CALLS this user is confirmed (status 5) on.
		/* Slice E groups its response by these — they are containers, not
		/* act-on calls, so this is deliberately separate from
		/* goat_boss_scope().
		/*
		/* $userID is a SmartStaff `userID`, NEVER an EIN. Passing an EIN
		/* silently returns another person's calls — documented regression,
		/* v3.4.10 (EIN 5925 resolved against userID 9734).
		/*
		/* The name test runs in PHP, not SQL: goat_is_boss_call_name() is
		/* exclusion-based (everything with "boss" in it, minus cancellations,
		/* minus two known working calls) and cannot be expressed as a WHERE
		/* clause. Do not try.
		*/

		function goat_boss_calls_for_user($userID)
		{
			$userID = (int) $userID;
			$out    = array();

			if ($userID <= 0)
			{
				return $out;
			}

			$res = mysql_query("SELECT ccm.callID AS n, c.call_name AS call_name
			                    FROM call_crew_map ccm
			                    INNER JOIN calls c ON c.id = ccm.callID
			                    WHERE ccm.userID = " . $userID . "
			                      AND ccm.status = 5
			                    ORDER BY c.start_date ASC, c.start_time ASC");

			if ($res !== false)
			{
				while ($row = mysql_fetch_object($res))
				{
					if (!goat_is_boss_call_name($row->call_name))
					{
						continue;
					}

					$out[] = (int) $row->n;
				}
			}

			return $out;
		}

		/*
		/* THE SET OF CALL IDS THIS USER MAY ACT ON. Everything else is built
		/* on this. Two sources, unioned and deduplicated:
		/*
		/*   DIRECT      — a call they are confirmed on AND flagged
		/*                 is_call_boss=1 on.
		/*   SUPERVISORY — every child of a dedicated boss call they are
		/*                 confirmed on.
		/*
		/* $userID is a SmartStaff `userID`, NEVER an EIN — see
		/* goat_boss_calls_for_user() above for the v3.4.10 regression.
		/*
		/* NEVER FILTER is_call_boss IN SQL. It is binary(50) (A0 confirmed on
		/* both environments); makeboss writes the STRING '1', stored as byte
		/* 0x31 null-padded to 50 bytes, so "WHERE is_call_boss = 1" is
		/* unreliable across MySQL versions. Select it and cast with (int) in
		/* PHP — the leading byte is read and parsing stops at the null. Same
		/* discipline as resolve-call-contact.php and get-booking.php.
		/*
		/* This returns ACT-ON calls. A user's own boss call appears here only
		/* via the direct branch, if they happen to be flagged on it. Slice E
		/* fetches the boss calls separately, through
		/* goat_boss_calls_for_user(), as grouping containers.
		*/

		function goat_boss_scope($userID)
		{
			$userID = (int) $userID;

			if ($userID <= 0)
			{
				return array();
			}

			$res = mysql_query("SELECT ccm.callID AS n, ccm.is_call_boss AS is_call_boss, c.call_name AS call_name
			                    FROM call_crew_map ccm
			                    INNER JOIN calls c ON c.id = ccm.callID
			                    WHERE ccm.userID = " . $userID . "
			                      AND ccm.status = 5");

			if ($res === false)
			{
				return array();
			}

			/* keyed, so the union dedupes itself — no in_array() in a loop */

			$scope = array();

			while ($row = mysql_fetch_object($res))
			{
				$callID = (int) $row->n;

				if ($callID <= 0)
				{
					continue;
				}

				/* ---- DIRECT ---- */

				if ((int) $row->is_call_boss === 1)
				{
					$scope[$callID] = true;
				}

				/*
				/* ---- SUPERVISORY ----
				/*
				/* NOTE THE ASYMMETRY, IT IS NOT A BUG: this branch does NOT
				/* check is_call_boss. Q4 — every confirmed resource on a
				/* dedicated boss call gets scope, not only the nominated one.
				/* Nomination governs who the CREW RING (rung 2 of the contact
				/* ladder, slice C), not who SEES.
				*/

				if (goat_is_boss_call_name($row->call_name))
				{
					$kids = goat_supervision_children($callID);

					foreach ($kids as $kid)
					{
						$scope[$kid] = true;
					}
				}
			}

			return array_keys($scope);
		}

	}

?>
