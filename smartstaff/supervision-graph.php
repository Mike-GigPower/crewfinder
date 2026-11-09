<?php

	/*
	/* Supervision graph helpers — traversal over `call_supervision`, and
	/* (since slice B of BRIEF-crew-self-times, Nov 2026) the ONE answer to
	/* "which tier is this call, and who enters its times".
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
	/* ONLY LIVE CREW BOSS CALLS SUPERVISE (D20, decisions B and C). An edge
	/* whose boss call fails goat_is_boss_call_name(), or whose boss call is
	/* cancelled, counts for NOTHING — not scope, not the boss lookup, not the
	/* tier, not the contact ladder. goat_boss_call_is_live() is the single
	/* test and every traversal below goes through it. call-supervision.php
	/* refuses to create such an edge; the test here covers the ones that
	/* already exist.
	/*
	/* THE TWO MARKERS ON call_crew_map, and they are NOT the same role:
	/*
	/*   is_crew_boss  tinyint(1). The Crew Boss pill, written by THE GOAT
	/*                 through set-crew-boss.php (D19). Slice A adds it.
	/*   is_call_boss  binary(50). Despite its name, the TEAM LEAD tick.
	/*                 makeboss writes the STRING '1' (byte 0x31, null-padded),
	/*                 so it is selected and (int)-cast in PHP, NEVER compared
	/*                 with "= 1" in SQL.
	/*
	/* is_crew_boss is read through goat_ccm_have_crew_boss() and reads as 0
	/* where slice A has not run, so this file deploys safely before the
	/* ALTER: every call simply has no Crew Boss pill yet.
	/*
	/* DEPENDENCY DIRECTION — ONE WAY ONLY.
	/*
	/*   supervision-graph.php  ──include_once──▶  resolve-call-contact.php
	/*
	/* This file depends on resolve-call-contact.php, for
	/* goat_is_boss_call_name(), goat_calls_have_cancelled() and
	/* goat_ccm_have_crew_boss(). NEVER the reverse. resolve-call-contact.php
	/* declares its functions at top level with no function_exists guard, so
	/* an include in the other direction is a fatal redeclare, not a no-op.
	/* Callers there guard at the CALL SITE instead:
	/*
	/*   $bossCallID = function_exists('goat_supervision_boss_call')
	/*               ? goat_supervision_boss_call($callID)
	/*               : 0;
	/*
	/* which makes the fallback structural: if this file is not loaded, the
	/* contact ladder has no link rung at all.
	/*
	/* Every query INNER JOINs `calls`, so edges left dangling by a deleted
	/* call are invisible. sss::deleteCall does not know this table exists,
	/* exactly as it does not know about call_feeds.
	/*
	/* THE userID-KEYED HELPERS (scope, boss calls, scope detail) return
	/* array() or 0 on EVERY failure path — never false, never null. Callers
	/* count() and in_array() the result.
	/*
	/* THE CALL-KEYED HELPERS (goat_bosses_by_call, goat_call_tiers and its
	/* wrappers) return array('ok', 'error', 'by_call') instead, on purpose:
	/* for them an empty answer and a broken query are opposite findings, and
	/* every caller fails hard on ok === false. See goat_bosses_by_call().
	/*
	/* PHP 5.x — mysql_*, no ??, no short array syntax.
	*/

	if (!function_exists('goat_supervision_boss_call'))
	{

		include_once('resolve-call-contact.php');

		/*
		/* The boss call supervising $childCall, or 0.
		/*
		/* THE RAW EDGE. No name test and no cancelled test here — this answers
		/* "what does call_supervision say", and every consumer then applies
		/* goat_boss_call_is_live() to the call it names. Keeping the two
		/* questions apart is what lets call-supervision.php and an audit read
		/* see an edge the authority paths ignore.
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
		/*
		/* Raw, like goat_supervision_boss_call(): the caller has already
		/* decided whether $bossCall is a live Crew Boss call.
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
		/* MAY THIS CALL SUPERVISE? THE SINGLE TEST FOR DECISIONS B AND C.
		/*
		/* A dedicated Crew Boss call (goat_is_boss_call_name()) that has not
		/* been cancelled. $cancelledAt is the calls.cancelled_at value as
		/* selected through goat_sg_cancelled_sql() — NULL where the column is
		/* absent, which reads as "not cancelled", matching the resolver.
		*/

		function goat_boss_call_is_live($callName, $cancelledAt)
		{
			if (!goat_is_boss_call_name((string) $callName))
			{
				return false;
			}

			if ($cancelledAt !== null && (int) $cancelledAt > 0)
			{
				return false;
			}

			return true;
		}

		/*
		/* calls.cancelled_at as a SELECT expression, or NULL where the column
		/* does not exist on this environment. Guarded for the reason the
		/* resolver gives at goat_calls_have_cancelled(): an unguarded column
		/* would make every helper here fail on such a box, and the userID-keyed
		/* helpers fail SILENTLY to empty scope.
		*/

		function goat_sg_cancelled_sql($alias)
		{
			return goat_calls_have_cancelled() ? ($alias . '.cancelled_at') : 'NULL';
		}

		/* call_crew_map.is_crew_boss as a SELECT expression, or 0 before slice A. */

		function goat_sg_crew_boss_sql($alias)
		{
			return goat_ccm_have_crew_boss() ? ($alias . '.is_crew_boss') : '0';
		}

		/*
		/* The DEDICATED BOSS CALLS this user is confirmed (status 5) on.
		/* Slice E groups its response by these — they are containers, not
		/* act-on calls, so this is deliberately separate from
		/* goat_boss_scope().
		/*
		/* UNCHANGED BY SLICE B. It is a VIEWING helper (my-shifts can_see_crew,
		/* my-call-crew, Your Crew grouping), and the brief keeps viewing as it
		/* was: no promo-pending exclusion and no cancelled test are retrofitted
		/* here.
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
		/* Keep the stronger of two scope reasons for one call.
		*/

		function goat_scope_claim(&$detail, $callID, $why)
		{
			$rank = array('direct_crew_boss' => 3, 'direct_team_lead' => 2, 'supervisory' => 1);

			$callID = (int) $callID;

			if ($callID <= 0)
			{
				return;
			}

			if (!isset($detail[$callID]) || $rank[$why] > $rank[$detail[$callID]])
			{
				$detail[$callID] = $why;
			}
		}

		/*
		/* THE CALLS THIS USER MAY SEE AS A BOSS, AND WHY.
		/*
		/*   goat_boss_scope_detail_result($u) -> array('ok', 'error', 'detail')
		/*   goat_boss_scope_detail($u)        -> detail, or array() on failure
		/*
		/*   detail: callID => 'direct_crew_boss' | 'direct_team_lead' | 'supervisory'
		/*
		/* The _result form exists so the time-entry helpers can tell "no scope"
		/* from "the query broke" (review R1); viewing callers keep the plain
		/* form and its degrade-to-empty rule.
		/*
		/* Three sources, the strongest kept per call:
		/*
		/*   DIRECT CREW BOSS  confirmed and carrying the Crew Boss pill
		/*                     (is_crew_boss = 1) on the call. ADDED slice B.
		/*   DIRECT TEAM LEAD  confirmed and ticked is_call_boss on the call.
		/*   SUPERVISORY       every child of a LIVE dedicated boss call they
		/*                     are confirmed on (goat_boss_call_is_live() —
		/*                     the cancelled test is new in slice B).
		/*
		/* $userID is a SmartStaff `userID`, NEVER an EIN — see
		/* goat_boss_calls_for_user() above for the v3.4.10 regression.
		/*
		/* NOTE THE ASYMMETRY, IT IS NOT A BUG: the supervisory branch does NOT
		/* check either marker. Q4 — every confirmed resource on a dedicated boss
		/* call gets scope, not only the nominated one. Nomination governs who
		/* the crew RING and who ENTERS TIMES, not who SEES.
		/*
		/* "Confirmed" here is status = 5 ONLY, without the promo-pending
		/* exclusion the time-entry helpers apply. That is deliberate (brief §3,
		/* "do not retrofit it"): this is viewing, and viewing is unchanged.
		/* goat_time_entry_scope() applies the exclusion for entry.
		/*
		/* This returns SEE-AS-BOSS calls (who may ENTER is goat_time_entry_*).
		/* A user's own boss call appears here only
		/* via a direct branch, if they happen to carry a marker on it. Slice E
		/* fetches the boss calls separately, through goat_boss_calls_for_user(),
		/* as grouping containers.
		*/

		function goat_boss_scope_detail_result($userID)
		{
			$fail = array('ok' => false, 'error' => '', 'detail' => array());

			$userID = (int) $userID;

			if ($userID <= 0)
			{
				return array('ok' => true, 'error' => '', 'detail' => array());
			}

			$res = mysql_query("SELECT ccm.callID AS n,
			                           ccm.is_call_boss AS is_call_boss,
			                           " . goat_sg_crew_boss_sql('ccm') . " AS is_crew_boss,
			                           c.call_name AS call_name,
			                           " . goat_sg_cancelled_sql('c') . " AS cancelled_at
			                    FROM call_crew_map ccm
			                    INNER JOIN calls c ON c.id = ccm.callID
			                    WHERE ccm.userID = " . $userID . "
			                      AND ccm.status = 5");

			if ($res === false)
			{
				$fail['error'] = 'scope query failed: ' . mysql_error();
				return $fail;
			}

			$detail    = array();
			$liveBoss  = array();   /* boss calls whose children are in scope */

			while ($row = mysql_fetch_object($res))
			{
				$callID = (int) $row->n;

				if ($callID <= 0)
				{
					continue;
				}

				if ((int) $row->is_crew_boss === 1)
				{
					goat_scope_claim($detail, $callID, 'direct_crew_boss');
				}

				if ((int) $row->is_call_boss === 1)
				{
					goat_scope_claim($detail, $callID, 'direct_team_lead');
				}

				if (goat_boss_call_is_live($row->call_name, $row->cancelled_at))
				{
					$liveBoss[$callID] = true;
				}
			}

			/*
			/* SUPERVISORY — one query for every live boss call, not one per
			/* call, and it reports its own failure. Same join as
			/* goat_supervision_children(): the CHILD is INNER JOINed, so an edge
			/* to a deleted call is invisible.
			*/

			if (count($liveBoss) > 0)
			{
				$kres = mysql_query("SELECT s.child_call AS n
				                     FROM call_supervision s
				                     INNER JOIN calls c ON c.id = s.child_call
				                     WHERE s.boss_call IN (" . implode(',', array_keys($liveBoss)) . ")");

				if ($kres === false)
				{
					$fail['error'] = 'supervision query failed: ' . mysql_error();
					return $fail;
				}

				while ($krow = mysql_fetch_object($kres))
				{
					goat_scope_claim($detail, (int) $krow->n, 'supervisory');
				}
			}

			return array('ok' => true, 'error' => '', 'detail' => $detail);
		}

		/*
		/* The VIEWING form: the detail map, or array() on any failure — the
		/* userID-keyed helpers' rule (file header). Viewing degrades; entry
		/* does not, which is why goat_time_entry_* read the _result form.
		*/

		function goat_boss_scope_detail($userID)
		{
			$r = goat_boss_scope_detail_result($userID);

			return $r['ok'] ? $r['detail'] : array();
		}

		/*
		/* THE SET OF CALL IDS THIS USER MAY SEE AS A BOSS (crew list, contacts,
		/* emergency card). The keys of goat_boss_scope_detail(), so there is ONE
		/* implementation of the branches.
		/*
		/* It no longer means "may enter times". Since slice B that is
		/* goat_time_entry_scope(), which is narrower: a Team Lead on a call with
		/* a Crew Boss keeps their view here (D18) and loses entry there.
		*/

		function goat_boss_scope($userID)
		{
			return array_keys(goat_boss_scope_detail($userID));
		}

		/*
		/* THE INVERSION — GIVEN CALLS, WHO IS THE BOSS OF EACH.
		/*
		/* Every userID-keyed helper above answers a person asking about
		/* themselves. This answers the opposite question.
		/*
		/* ITS READER SINCE SLICE B IS emergency-scope.php, which uses `how` to
		/* label the access it granted. calls-awaiting-times.php and
		/* ops-times-outstanding.php read goat_time_entry_people_by_call()
		/* instead, because "who bosses this call" and "who enters its times"
		/* stopped being the same answer when Team Leads lost entry on Crew
		/* Boss calls. Kept as it was (decision A) so the emergency log's
		/* vocabulary does not move under it.
		/*
		/* FOUR BRANCHES:
		/*
		/*   DIRECT_CREW_BOSS  the row carries is_crew_boss on the call itself.
		/*                     ADDED slice B, so the access goat_boss_scope()
		/*                     now grants a Crew Boss can be attributed.
		/*   DIRECT            the row is flagged is_call_boss on the call itself.
		/*   CONTAINER         the CALL is a live dedicated boss call and the row
		/*                     is anyone confirmed on it (Q4).
		/*   SUPERVISORY       the call is the child of a LIVE boss call, and the
		/*                     row is anyone confirmed on THAT call. No marker
		/*                     test: the same asymmetry goat_boss_scope() carries.
		/*
		/* "Live" — boss-named and not cancelled — is new in slice B for both
		/* the container and the supervisory branch (decisions B, C).
		/*
		/* PRECEDENCE direct_crew_boss > direct > container > supervisory. A
		/* boss reachable two ways is ONE entry carrying the most specific
		/* reason, because `how` exists to answer "why is this person
		/* responsible" and the weaker answer is the less useful one.
		/*
		/* THE RETURN SHAPE DEPARTS FROM THE userID HELPERS' array()-ON-FAILURE
		/* RULE, ON PURPOSE:
		/*
		/*   array('ok' => bool, 'error' => string,
		/*         'by_call' => array(callID => array(userID => how)))
		/*
		/* because the two states that rule conflates are opposite answers
		/* here. An empty entry for a call that EXISTS is a real finding; a
		/* broken query is not that, and callers must fail hard rather than
		/* report a call as bossless. Still an array on every path, so no caller
		/* is handed false or null.
		/*
		/* Every call id that exists in `calls` comes back as a key, so
		/* "bossless" is readable without a second existence check. Ids that
		/* are not in `calls` are absent, matching every other traversal here.
		*/

		function goat_boss_claim(&$byCall, $callID, $userID, $how)
		{
			$rank = array('direct_crew_boss' => 4, 'direct' => 3, 'container' => 2, 'supervisory' => 1);

			$callID = (int) $callID;
			$userID = (int) $userID;

			if ($userID <= 0 || !isset($byCall[$callID]))
			{
				return;   /* not a call we were asked about */
			}

			if (!isset($byCall[$callID][$userID])
			    || $rank[$how] > $rank[$byCall[$callID][$userID]])
			{
				$byCall[$callID][$userID] = $how;
			}
		}

		function goat_bosses_by_call($callIDs)
		{
			$fail = array('ok' => false, 'error' => '', 'by_call' => array());
			$none = array('ok' => true,  'error' => '', 'by_call' => array());

			$ids = goat_sg_clean_ids($callIDs);

			if (count($ids) === 0)
			{
				return $none;
			}

			$idList = implode(',', $ids);

			/*
			/* ---- 1 of 3: THE CALLS THEMSELVES ----
			/*
			/* The name test runs in PHP because goat_is_boss_call_name() is
			/* exclusion-based and cannot be expressed as a WHERE clause. This
			/* query is also the existence check: a caller may hold an id for a
			/* call that has since been deleted, and it must not come back as
			/* bossless.
			*/

			$nameRes = mysql_query("SELECT c.id AS id, c.call_name AS call_name,
			                               " . goat_sg_cancelled_sql('c') . " AS cancelled_at
			                        FROM calls c WHERE c.id IN (" . $idList . ")");

			if ($nameRes === false)
			{
				$fail['error'] = 'call name lookup failed: ' . mysql_error();
				return $fail;
			}

			$byCall     = array();
			$isBossCall = array();

			while ($row = mysql_fetch_object($nameRes))
			{
				$cid = (int) $row->id;

				if ($cid <= 0)
				{
					continue;
				}

				$byCall[$cid]     = array();
				$isBossCall[$cid] = goat_boss_call_is_live($row->call_name, $row->cancelled_at);
			}

			if (count($byCall) === 0)
			{
				return $none;
			}

			$liveList = implode(',', array_map('intval', array_keys($byCall)));

			/*
			/* ---- 2 of 3: DIRECT AND CONTAINER ----
			/*
			/* One query serves all three on-the-call branches, because they ask
			/* the same thing of the same table — every confirmed row on the
			/* calls — and differ only in what makes the row count.
			*/

			$crewRes = mysql_query("SELECT ccm.callID AS call_id, ccm.userID AS user_id,
			                               ccm.is_call_boss AS is_call_boss,
			                               " . goat_sg_crew_boss_sql('ccm') . " AS is_crew_boss
			                        FROM call_crew_map ccm
			                        WHERE ccm.callID IN (" . $liveList . ")
			                          AND ccm.status = 5");

			if ($crewRes === false)
			{
				$fail['error'] = 'crew query failed: ' . mysql_error();
				return $fail;
			}

			while ($crow = mysql_fetch_object($crewRes))
			{
				$cid = (int) $crow->call_id;
				$uid = (int) $crow->user_id;

				if ((int) $crow->is_crew_boss === 1)
				{
					goat_boss_claim($byCall, $cid, $uid, 'direct_crew_boss');
				}

				if ((int) $crow->is_call_boss === 1)
				{
					goat_boss_claim($byCall, $cid, $uid, 'direct');
				}

				if (isset($isBossCall[$cid]) && $isBossCall[$cid])
				{
					goat_boss_claim($byCall, $cid, $uid, 'container');
				}
			}

			/*
			/* ---- 3 of 3: SUPERVISORY ----
			/*
			/* Two queries, never one per call. First every supervision edge
			/* whose CHILD is one of ours, then the confirmed roster of the
			/* boss calls those edges point at — which are usually OUTSIDE the
			/* set we were asked about (a Crew Boss call runs in the morning
			/* and the load-out it supervises finishes at midnight), so they
			/* are looked up by id rather than found among the inputs.
			/*
			/* INNER JOIN calls on the boss call, matching every other
			/* traversal of this table: an edge left dangling by a deleted call
			/* resolves to nothing rather than to a boss who does not exist. An
			/* edge from a call that is not a live Crew Boss call is skipped
			/* (decisions B, C).
			*/

			$edgeRes = mysql_query("SELECT s.boss_call AS boss_call, s.child_call AS child_call,
			                               cb.call_name AS boss_call_name,
			                               " . goat_sg_cancelled_sql('cb') . " AS boss_cancelled_at
			                        FROM call_supervision s
			                        INNER JOIN calls cb ON cb.id = s.boss_call
			                        WHERE s.child_call IN (" . $liveList . ")");

			if ($edgeRes === false)
			{
				$fail['error'] = 'supervision query failed: ' . mysql_error();
				return $fail;
			}

			$childrenOf = array();   /* bossCallID -> [childCallID] */

			while ($erow = mysql_fetch_object($edgeRes))
			{
				$bc = (int) $erow->boss_call;
				$cc = (int) $erow->child_call;

				if ($bc <= 0 || !isset($byCall[$cc]))
				{
					continue;
				}

				if (!goat_boss_call_is_live($erow->boss_call_name, $erow->boss_cancelled_at))
				{
					continue;
				}

				if (!isset($childrenOf[$bc]))
				{
					$childrenOf[$bc] = array();
				}

				$childrenOf[$bc][] = $cc;
			}

			if (count($childrenOf) > 0)
			{
				$bossList = implode(',', array_map('intval', array_keys($childrenOf)));

				$bossCrewRes = mysql_query("SELECT ccm.callID AS call_id, ccm.userID AS user_id
				                            FROM call_crew_map ccm
				                            WHERE ccm.callID IN (" . $bossList . ")
				                              AND ccm.status = 5");

				if ($bossCrewRes === false)
				{
					$fail['error'] = 'boss roster query failed: ' . mysql_error();
					return $fail;
				}

				while ($brow = mysql_fetch_object($bossCrewRes))
				{
					$bc  = (int) $brow->call_id;
					$uid = (int) $brow->user_id;

					if (!isset($childrenOf[$bc]))
					{
						continue;
					}

					foreach ($childrenOf[$bc] as $cc)
					{
						goat_boss_claim($byCall, $cc, $uid, 'supervisory');
					}
				}
			}

			return array('ok' => true, 'error' => '', 'by_call' => $byCall);
		}

		/*
		/* ================================================================
		/* TIERS AND TIME ENTRY — slice B of BRIEF-crew-self-times (Nov 2026)
		/* ================================================================
		/*
		/* "CONFIRMED" IN EVERYTHING BELOW = status 5 AND no call_promo_ack row
		/* with acked_at IS NULL. A backup promoted to 5 who has not accepted is
		/* not known to be working, so they can neither make a call a Crew Boss
		/* call nor enter anyone's times. The helpers ABOVE keep plain status 5,
		/* because they gate viewing and viewing is unchanged.
		*/

		/* dedupe and sanitise; every id is cast, no string reaches SQL */

		function goat_sg_clean_ids($callIDs)
		{
			$ids = array();

			if (!is_array($callIDs))
			{
				return array();
			}

			foreach ($callIDs as $cid)
			{
				$cid = (int) $cid;

				if ($cid > 0)
				{
					$ids[$cid] = true;
				}
			}

			return array_keys($ids);
		}

		/*
		/* The confirmed (non-promo-pending) roster of each call:
		/*
		/*   array('ok', 'error', 'by_call' => callID => userID =>
		/*         array('crew_boss' => 0|1, 'team_lead' => 0|1))
		/*
		/* Calls with nobody confirmed are absent.
		*/

		function goat_sg_confirmed_roster($callIDs)
		{
			$ids = goat_sg_clean_ids($callIDs);

			if (count($ids) === 0)
			{
				return array('ok' => true, 'error' => '', 'by_call' => array());
			}

			$res = mysql_query("SELECT ccm.callID AS call_id, ccm.userID AS user_id,
			                           ccm.is_call_boss AS is_call_boss,
			                           " . goat_sg_crew_boss_sql('ccm') . " AS is_crew_boss
			                    FROM call_crew_map ccm
			                    LEFT JOIN call_promo_ack cpa
			                           ON cpa.callID = ccm.callID
			                          AND cpa.userID = ccm.userID
			                          AND cpa.acked_at IS NULL
			                    WHERE ccm.callID IN (" . implode(',', $ids) . ")
			                      AND ccm.status = 5
			                      AND cpa.id IS NULL");

			if ($res === false)
			{
				return array('ok' => false, 'error' => 'roster query failed: ' . mysql_error(), 'by_call' => array());
			}

			$out = array();

			while ($row = mysql_fetch_object($res))
			{
				$cid = (int) $row->call_id;
				$uid = (int) $row->user_id;

				if ($cid <= 0 || $uid <= 0)
				{
					continue;
				}

				if (!isset($out[$cid]))
				{
					$out[$cid] = array();
				}

				/* MyISAM enforces no uniqueness: OR the markers if a pair repeats */

				$cb = ((int) $row->is_crew_boss === 1) ? 1 : 0;
				$tl = ((int) $row->is_call_boss === 1) ? 1 : 0;

				if (isset($out[$cid][$uid]))
				{
					$cb = max($cb, $out[$cid][$uid]['crew_boss']);
					$tl = max($tl, $out[$cid][$uid]['team_lead']);
				}

				$out[$cid][$uid] = array('crew_boss' => $cb, 'team_lead' => $tl);
			}

			return array('ok' => true, 'error' => '', 'by_call' => $out);
		}

		/* userIDs on a roster carrying the given marker, ascending */

		function goat_sg_marked($members, $marker)
		{
			$out = array();

			foreach ($members as $uid => $m)
			{
				if ($m[$marker] === 1)
				{
					$out[] = (int) $uid;
				}
			}

			sort($out);

			return $out;
		}

		/*
		/* D's rule for who is "ticked" on a call: the Crew Boss pill holders,
		/* else the Team Lead tick holders, else nobody.
		*/

		function goat_sg_ticked($members)
		{
			$cb = goat_sg_marked($members, 'crew_boss');

			if (count($cb) > 0)
			{
				return $cb;
			}

			return goat_sg_marked($members, 'team_lead');
		}

		/* keep the stronger `how` for one person; same ranks as goat_boss_claim() */

		function goat_sg_entry_claim(&$entry, $userID, $how)
		{
			$rank = array('direct_crew_boss' => 4, 'direct' => 3, 'container' => 2, 'supervisory' => 1);

			$userID = (int) $userID;

			if ($userID <= 0)
			{
				return;
			}

			if (!isset($entry[$userID]) || $rank[$how] > $rank[$entry[$userID]])
			{
				$entry[$userID] = $how;
			}
		}

		/*
		/* WHICH TIER IS EACH CALL, AND WHO ENTERS ITS TIMES.
		/*
		/* THE ONE IMPLEMENTATION (design §4.2). Boss-form access, crew
		/* self-submission, reminder pushes and the Ops lane's "who to ring" all
		/* read this, directly or through the wrappers below.
		/*
		/*   array('ok' => bool, 'error' => string, 'by_call' => callID => array(
		/*     'tier'               'crew_boss' | 'team_lead' | 'self' | 'ops',
		/*     'entry_user_ids'     who may enter times for the whole call,
		/*     'entry_how'          userID => how, for the same people, in the
		/*                          goat_bosses_by_call() vocabulary,
		/*     'crew_boss_user_ids' Crew Boss pill holders on the call, plus the
		/*                          ticked person(s) on its supervising call,
		/*     'team_lead_user_ids' Team Lead ticks on the call,
		/*     'multi_team_lead'    1 when tier is team_lead with 2+ Team Leads,
		/*     'is_boss_call'       1 when the call is itself a Crew Boss call,
		/*     'supervised_by'      the live supervising Crew Boss call that
		/*                          counts (≥1 confirmed on it), else 0,
		/*     'flags'              'multi_team_lead',
		/*                          'crew_boss_call_nobody_ticked'
		/*   ))
		/*
		/* RULES, FIRST MATCH WINS (brief §3.1):
		/*
		/*   1  The call IS a Crew Boss call (D21). Entry = its Crew Boss pill
		/*      holders, else its Team Lead tick. Tier crew_boss. Nobody ticked:
		/*      tier `ops`, nobody enters, flagged. Never `self`.
		/*   2  A Crew Boss pill on the call, or a link from a LIVE Crew Boss call
		/*      with ≥1 confirmed person: tier crew_boss. Entry = the pill holders
		/*      plus the supervising call's ticked person — or, when nobody there
		/*      is ticked, EVERYONE confirmed on it (today's Q4 behaviour), flagged.
		/*   3  Team Lead tick(s): tier team_lead, entry = all of them. Two or
		/*      more breaks D17 and is flagged; every one may still enter (§4.3).
		/*   4  Otherwise `self`: entry is empty, each person enters their own.
		/*
		/* A cancelled call is not special-cased: every row on it is status 9,
		/* so it has no roster and falls to `ops` (boss call) or `self`. The
		/* self-submit endpoint refuses it on its confirmed-row gate.
		/*
		/* Batch: four queries for any number of calls, never one per call.
		/* Ids not in `calls` are absent from by_call.
		*/

		function goat_call_tiers($callIDs)
		{
			$fail = array('ok' => false, 'error' => '', 'by_call' => array());
			$none = array('ok' => true,  'error' => '', 'by_call' => array());

			$ids = goat_sg_clean_ids($callIDs);

			if (count($ids) === 0)
			{
				return $none;
			}

			/* ---- 1 of 4: the calls themselves (also the existence check) ---- */

			$cres = mysql_query("SELECT c.id AS id, c.call_name AS call_name
			                     FROM calls c
			                     WHERE c.id IN (" . implode(',', $ids) . ")");

			if ($cres === false)
			{
				$fail['error'] = 'call lookup failed: ' . mysql_error();
				return $fail;
			}

			$isBoss = array();

			while ($row = mysql_fetch_object($cres))
			{
				$cid = (int) $row->id;

				if ($cid > 0)
				{
					$isBoss[$cid] = goat_is_boss_call_name((string) $row->call_name);
				}
			}

			if (count($isBoss) === 0)
			{
				return $none;
			}

			$live = array_keys($isBoss);

			/* ---- 2 of 4: confirmed roster on the calls ---- */

			$roster = goat_sg_confirmed_roster($live);

			if (!$roster['ok'])
			{
				$fail['error'] = $roster['error'];
				return $fail;
			}

			$rosterOf = $roster['by_call'];

			/*
			/* ---- 3 of 4: the supervising edges, live Crew Boss calls only ----
			/*
			/* Boss calls are not asked about here — rule 1 decides them from
			/* their own roster — so their own edges (a boss call supervising a
			/* boss call is permitted) are not consulted for their tier.
			*/

			$supOf = array();   /* childCallID -> bossCallID */

			$eres = mysql_query("SELECT s.child_call AS child_call, s.boss_call AS boss_call,
			                            cb.call_name AS boss_call_name,
			                            " . goat_sg_cancelled_sql('cb') . " AS boss_cancelled_at
			                     FROM call_supervision s
			                     INNER JOIN calls cb ON cb.id = s.boss_call
			                     WHERE s.child_call IN (" . implode(',', $live) . ")");

			if ($eres === false)
			{
				$fail['error'] = 'supervision query failed: ' . mysql_error();
				return $fail;
			}

			while ($erow = mysql_fetch_object($eres))
			{
				$cc = (int) $erow->child_call;
				$bc = (int) $erow->boss_call;

				if ($bc <= 0 || $bc === $cc || !isset($isBoss[$cc]))
				{
					continue;   /* a call cannot boss itself */
				}

				if (!goat_boss_call_is_live($erow->boss_call_name, $erow->boss_cancelled_at))
				{
					continue;   /* decisions B and C */
				}

				$supOf[$cc] = $bc;
			}

			/* ---- 4 of 4: confirmed roster on the supervising calls ---- */

			$need = array();

			foreach ($supOf as $cc => $bc)
			{
				if (!isset($rosterOf[$bc]))
				{
					$need[$bc] = true;
				}
			}

			if (count($need) > 0)
			{
				$sup = goat_sg_confirmed_roster(array_keys($need));

				if (!$sup['ok'])
				{
					$fail['error'] = $sup['error'];
					return $fail;
				}

				foreach ($sup['by_call'] as $bc => $members)
				{
					$rosterOf[$bc] = $members;
				}
			}

			/* ---- assemble ---- */

			$byCall = array();

			foreach ($isBoss as $cid => $callIsBoss)
			{
				$members  = isset($rosterOf[$cid]) ? $rosterOf[$cid] : array();
				$cbOnCall = goat_sg_marked($members, 'crew_boss');
				$tlOnCall = goat_sg_marked($members, 'team_lead');

				$tier         = 'self';
				$entry        = array();
				$crewBossIDs  = $cbOnCall;
				$flags        = array();
				$multi        = 0;
				$supervisedBy = 0;

				if ($callIsBoss)
				{
					/* RULE 1 */

					if (count($cbOnCall) > 0)
					{
						$tier = 'crew_boss';

						foreach ($cbOnCall as $uid)
						{
							goat_sg_entry_claim($entry, $uid, 'direct_crew_boss');
						}
					}
					else if (count($tlOnCall) > 0)
					{
						$tier = 'crew_boss';

						foreach ($tlOnCall as $uid)
						{
							goat_sg_entry_claim($entry, $uid, 'direct');
						}
					}
					else
					{
						$tier    = 'ops';
						$flags[] = 'crew_boss_call_nobody_ticked';
					}
				}
				else
				{
					$sb        = isset($supOf[$cid]) ? (int) $supOf[$cid] : 0;
					$sbMembers = ($sb > 0 && isset($rosterOf[$sb])) ? $rosterOf[$sb] : array();

					if (count($sbMembers) === 0)
					{
						$sb = 0;   /* a Crew Boss call with nobody confirmed does not count */
					}

					if (count($cbOnCall) > 0 || $sb > 0)
					{
						/* RULE 2 */

						$tier = 'crew_boss';

						foreach ($cbOnCall as $uid)
						{
							goat_sg_entry_claim($entry, $uid, 'direct_crew_boss');
						}

						if ($sb > 0)
						{
							$supervisedBy = $sb;
							$ticked       = goat_sg_ticked($sbMembers);

							if (count($ticked) > 0)
							{
								foreach ($ticked as $uid)
								{
									goat_sg_entry_claim($entry, $uid, 'supervisory');
									$crewBossIDs[] = $uid;
								}
							}
							else
							{
								/* nobody ticked up there: everyone on it, as today (Q4) */

								foreach ($sbMembers as $uid => $ignored)
								{
									goat_sg_entry_claim($entry, $uid, 'supervisory');
								}

								$flags[] = 'crew_boss_call_nobody_ticked';
							}
						}
					}
					else if (count($tlOnCall) > 0)
					{
						/* RULE 3 */

						$tier = 'team_lead';

						foreach ($tlOnCall as $uid)
						{
							goat_sg_entry_claim($entry, $uid, 'direct');
						}

						if (count($tlOnCall) > 1)
						{
							$multi   = 1;
							$flags[] = 'multi_team_lead';
						}
					}

					/* RULE 4 — tier stays 'self', entry stays empty */
				}

				$crewBossIDs = array_values(array_unique(array_map('intval', $crewBossIDs)));
				sort($crewBossIDs);

				ksort($entry);

				$byCall[$cid] = array(
					'tier'               => $tier,
					'entry_user_ids'     => array_map('intval', array_keys($entry)),
					'entry_how'          => $entry,
					'crew_boss_user_ids' => $crewBossIDs,
					'team_lead_user_ids' => $tlOnCall,
					'multi_team_lead'    => $multi,
					'is_boss_call'       => $callIsBoss ? 1 : 0,
					'supervised_by'      => $supervisedBy,
					'flags'              => $flags
				);
			}

			return array('ok' => true, 'error' => '', 'by_call' => $byCall);
		}

		/*
		/* One call. 'tier' is the per-call array above, or null when the call
		/* does not exist. ok === false is a failed query — never read it as a
		/* tier.
		*/

		function goat_call_tier($callID)
		{
			$callID = (int) $callID;
			$r      = goat_call_tiers(array($callID));

			return array(
				'ok'    => $r['ok'],
				'error' => $r['error'],
				'tier'  => isset($r['by_call'][$callID]) ? $r['by_call'][$callID] : null
			);
		}

		/*
		/* GIVEN CALLS, WHO ENTERS EACH ONE'S TIMES — the time-entry inversion,
		/* read by calls-awaiting-times.php (the push cron) and
		/* ops-times-outstanding.php (who Rich rings).
		/*
		/*   by_call => callID => array('tier', 'entry_user_ids',
		/*                              'how' => userID => how, 'flags',
		/*                              'multi_team_lead', 'supervised_by')
		/*
		/* For tier `self` it returns the tier and NO PEOPLE. Who on a self call
		/* still owes times is time-submission-graph.php's question
		/* (goat_outstanding_people_by_call()), asked at the caller, so this file
		/* does not take on that include. For tier `ops` nobody enters; the Ops
		/* lane flags it.
		*/

		function goat_time_entry_people_by_call($callIDs)
		{
			$t = goat_call_tiers($callIDs);

			if (!$t['ok'])
			{
				return $t;
			}

			$out = array();

			foreach ($t['by_call'] as $cid => $info)
			{
				$out[$cid] = array(
					'tier'            => $info['tier'],
					'entry_user_ids'  => $info['entry_user_ids'],
					'how'             => $info['entry_how'],
					'flags'           => $info['flags'],
					'multi_team_lead' => $info['multi_team_lead'],
					'supervised_by'   => $info['supervised_by']
				);
			}

			return array('ok' => true, 'error' => '', 'by_call' => $out);
		}

		/*
		/* THE CALLS THIS USER MAY ENTER TIMES FOR.
		/*
		/*   array('ok' => bool, 'error' => string, 'call_ids' => list of int)
		/*
		/* ok === false IS A FAILED QUERY AND EVERY CALLER RETURNS A 500 ON IT
		/* (review R1, 5 Nov 2026). It used to return array() on failure, which
		/* every caller turned into a 403 — so a broken query told a Crew Boss
		/* "you are not the person entering times for this call", which is
		/* false, alarming, and indistinguishable from the real refusal.
		/*
		/* Candidates are the user's scope detail — every route into entry (a
		/* pill, a tick, or confirmed on a supervising call) is already a route
		/* into scope — narrowed to those whose entry_user_ids contain the
		/* user, minus calls where the user is promo-pending.
		/*
		/* THREE ENTRY POINTS over one implementation (review R2):
		/*
		/*   goat_time_entry_scope($u)            every call, unwindowed
		/*   goat_time_entry_scope_for($u, $ids)  only the calls asked about —
		/*                                        tiers those and nothing else;
		/*                                        what a per-call gate wants
		/*   goat_time_entry_calls($u, $detail,   the core, for a caller that
		/*                         $ids)          already holds the user's scope
		/*                                        detail (my-boss-calls.php), so
		/*                                        it is computed once per request
		/*
		/* $onlyCallIDs null means "no restriction"; an array restricts.
		*/

		function goat_time_entry_calls($userID, $detail, $onlyCallIDs)
		{
			$ok   = array('ok' => true,  'error' => '', 'call_ids' => array());
			$fail = array('ok' => false, 'error' => '', 'call_ids' => array());

			$userID = (int) $userID;

			if ($userID <= 0 || !is_array($detail) || count($detail) === 0)
			{
				return $ok;
			}

			$cand = array();

			if ($onlyCallIDs === null)
			{
				foreach ($detail as $cid => $why)
				{
					$cand[(int) $cid] = true;
				}
			}
			else
			{
				foreach (goat_sg_clean_ids($onlyCallIDs) as $cid)
				{
					if (isset($detail[$cid]))
					{
						$cand[$cid] = true;
					}
				}
			}

			if (count($cand) === 0)
			{
				return $ok;
			}

			$candIDs = array_keys($cand);

			$t = goat_call_tiers($candIDs);

			if (!$t['ok'])
			{
				$fail['error'] = $t['error'];
				return $fail;
			}

			$pending = array();

			$pres = mysql_query("SELECT callID AS n FROM call_promo_ack
			                     WHERE userID = " . $userID . "
			                       AND acked_at IS NULL
			                       AND callID IN (" . implode(',', $candIDs) . ")");

			if ($pres === false)
			{
				$fail['error'] = 'promotion lookup failed: ' . mysql_error();
				return $fail;
			}

			while ($prow = mysql_fetch_object($pres))
			{
				$pending[(int) $prow->n] = true;
			}

			$out = array();

			foreach ($t['by_call'] as $cid => $info)
			{
				if (isset($pending[$cid]))
				{
					continue;
				}

				if (isset($info['entry_how'][$userID]))
				{
					$out[] = (int) $cid;
				}
			}

			sort($out);

			return array('ok' => true, 'error' => '', 'call_ids' => $out);
		}

		function goat_time_entry_scope($userID)
		{
			$d = goat_boss_scope_detail_result($userID);

			if (!$d['ok'])
			{
				return array('ok' => false, 'error' => $d['error'], 'call_ids' => array());
			}

			return goat_time_entry_calls($userID, $d['detail'], null);
		}

		function goat_time_entry_scope_for($userID, $callIDs)
		{
			$d = goat_boss_scope_detail_result($userID);

			if (!$d['ok'])
			{
				return array('ok' => false, 'error' => $d['error'], 'call_ids' => array());
			}

			return goat_time_entry_calls($userID, $d['detail'], is_array($callIDs) ? $callIDs : array());
		}


		/*
		/* ================================================================
		/* SLICE G HELPERS — read by THE GOAT through call-tiers.php,
		/* call-supervision.php, set-team-lead.php, get-booking.php,
		/* get-bookings-bulk.php and ops-times-outstanding.php (Nov 2026).
		/* ================================================================
		*/

		/*
		/* userID => "First Last", HTML-decoded (users stores names encoded, some
		/* rows and not others). array() on failure — names are display only.
		*/

		function goat_sg_user_names($userIDs)
		{
			$ids = goat_sg_clean_ids($userIDs);
			$out = array();

			if (count($ids) === 0)
			{
				return $out;
			}

			$res = mysql_query("SELECT id, firstname, lastname FROM users
			                    WHERE id IN (" . implode(',', $ids) . ")");

			if ($res === false)
			{
				return $out;
			}

			while ($u = mysql_fetch_object($res))
			{
				$out[(int) $u->id] = trim(trim(html_entity_decode((string) $u->firstname, ENT_QUOTES))
				                        . ' ' . trim(html_entity_decode((string) $u->lastname, ENT_QUOTES)));
			}

			return $out;
		}

		/*
		/* DOES THIS CALL HAVE A CREW BOSS? (review R14) — the D17 question, and
		/* NOT the same as "is its tier crew_boss". Tier rule 1 makes a dedicated
		/* Crew Boss call tier crew_boss on a Team Lead tick alone; for D17 that
		/* call has no Crew Boss, so it may still have only one Team Lead.
		/*
		/* A Crew Boss = a confirmed, non-promo-pending Crew Boss pill ON THE
		/* CALL, or a supervision link that COUNTS (supervised_by > 0). From a
		/* goat_call_tiers() entry: with no counting link, crew_boss_user_ids is
		/* exactly the call's own pills, so either test reads straight off it.
		/*
		/* Used by set-team-lead.php (adding a second Team Lead), set-crew-boss.php
		/* (clearing a pill) and goat_d17_if_unlinked() (losing a link), and
		/* mirrored by THE GOAT's setCallBoss().
		*/

		function goat_tier_has_crew_boss($info)
		{
			if (!is_array($info))
			{
				return false;
			}

			return ((int) $info['supervised_by'] > 0) || (count($info['crew_boss_user_ids']) > 0);
		}

		/*
		/* THE TEAM LEAD COUNT FOR D17 (review R13b, 5 Nov 2026) — ONE
		/* implementation, read by set-team-lead.php (adding a second),
		/* set-crew-boss.php (clearing a pill) and goat_d17_if_unlinked()
		/* (losing a link, call-supervision.php).
		/*
		/*   array('ok', 'error', 'by_call' => callID => [userIDs], ascending)
		/*
		/* EVERY TICK ON A STATUS-5 ROW, PROMOTION-PENDING INCLUDED — deliberately
		/* wider than the tiers. A D17 check is about what the call WILL have:
		/* if A is ticked with an unanswered promotion and B is added, the call
		/* has two Team Leads the moment A accepts, with nothing left to stop it.
		/* So for D17 an unanswered promotion's tick counts.
		/*
		/* EVERYWHERE ELSE IT DOES NOT: tiers, entry people, contacts, the
		/* multi_team_lead flag and set-team-lead.php's returned team_leads
		/* are confirmed-and-accepted only (goat_sg_confirmed_roster()). The
		/* Crew Boss side of D17 (goat_tier_has_crew_boss()) stays
		/* confirmed-and-accepted too: a pill on an unanswered promotion is not
		/* a Crew Boss yet, so it cannot license a second Team Lead.
		/*
		/* is_call_boss is binary(50): selected and (int)-cast in PHP, never
		/* "= 1" in SQL. Calls with no tick are absent.
		*/

		function goat_d17_team_leads($callIDs)
		{
			$ids = goat_sg_clean_ids($callIDs);

			if (count($ids) === 0)
			{
				return array('ok' => true, 'error' => '', 'by_call' => array());
			}

			$res = mysql_query("SELECT callID AS call_id, userID AS user_id, is_call_boss AS is_call_boss
			                    FROM call_crew_map
			                    WHERE callID IN (" . implode(',', $ids) . ")
			                      AND status = 5");

			if ($res === false)
			{
				return array('ok' => false, 'error' => 'Team Lead lookup failed: ' . mysql_error(), 'by_call' => array());
			}

			$out = array();

			while ($row = mysql_fetch_object($res))
			{
				$cid = (int) $row->call_id;
				$uid = (int) $row->user_id;

				if ($cid <= 0 || $uid <= 0 || (int) $row->is_call_boss !== 1)
				{
					continue;
				}

				if (!isset($out[$cid]))
				{
					$out[$cid] = array();
				}

				$out[$cid][$uid] = true;
			}

			foreach ($out as $cid => $set)
			{
				$list = array_keys($set);
				sort($list);
				$out[$cid] = $list;
			}

			return array('ok' => true, 'error' => '', 'by_call' => $out);
		}

		/*
		/* D17 FOR A LINK THAT IS ABOUT TO GO.
		/*
		/* Of $childIDs, the calls that would be left with NO Crew Boss and TWO
		/* OR MORE Team Leads if their supervision link stopped counting — the
		/* state D17 forbids. call-supervision.php refuses a `clear`, or a `set`
		/* onto a boss call with nobody confirmed, that would cause it.
		/*
		/*   array('ok', 'error', 'by_call' => childCallID => [team lead userIDs])
		/*
		/* Only children whose link COUNTS today (goat_call_tiers() supervised_by
		/* > 0) can lose a Crew Boss by losing it; a child with its own Crew Boss
		/* pill keeps one without the link. A child that is itself a Crew Boss
		/* call is NOT skipped (review R14): D17 asks whether a call has a Crew
		/* Boss, and a Team Lead tick on a boss call is not one. Pills read as
		/* confirmed-and-accepted (status 5, not promo-pending) like the tiers;
		/* Team Leads are counted by goat_d17_team_leads() — every status-5
		/* tick, promotion-pending included (review R13b).
		*/

		function goat_d17_if_unlinked($childIDs)
		{
			$ids = goat_sg_clean_ids($childIDs);

			if (count($ids) === 0)
			{
				return array('ok' => true, 'error' => '', 'by_call' => array());
			}

			$t = goat_call_tiers($ids);

			if (!$t['ok'])
			{
				return array('ok' => false, 'error' => $t['error'], 'by_call' => array());
			}

			$r = goat_sg_confirmed_roster($ids);

			if (!$r['ok'])
			{
				return array('ok' => false, 'error' => $r['error'], 'by_call' => array());
			}

			$tl = goat_d17_team_leads($ids);

			if (!$tl['ok'])
			{
				return array('ok' => false, 'error' => $tl['error'], 'by_call' => array());
			}

			$out = array();

			foreach ($t['by_call'] as $cid => $info)
			{
				if ((int) $info['supervised_by'] <= 0)
				{
					continue;
				}

				$members = isset($r['by_call'][$cid]) ? $r['by_call'][$cid] : array();

				if (count(goat_sg_marked($members, 'crew_boss')) > 0)
				{
					continue;
				}

				$leads = isset($tl['by_call'][$cid]) ? $tl['by_call'][$cid] : array();

				if (count($leads) >= 2)
				{
					$out[(int) $cid] = $leads;
				}
			}

			return array('ok' => true, 'error' => '', 'by_call' => $out);
		}

		/*
		/* LINK BUTTON SCHEMA (BRIEF-default-link-self-submit-off, Release 2,
		/* B1). Both reads are GUARDED, for the reason the resolver gives at
		/* goat_calls_have_cancelled(): this file is included by nearly every
		/* crew endpoint, and an unguarded table or column would make every
		/* helper here fail on a box where MIGRATION-link-button.sql has not
		/* run. Absent reads as "nothing marked" and "every link made by hand",
		/* which is exactly the state before the migration.
		*/

		function goat_sg_have_no_crew_boss()
		{
			static $has = null;

			if ($has === null)
			{
				$r   = mysql_query("SHOW TABLES LIKE 'call_no_crew_boss'");
				$has = ($r !== false && mysql_num_rows($r) > 0);
			}

			return $has;
		}

		function goat_sg_have_supervision_auto()
		{
			static $has = null;

			if ($has === null)
			{
				$r   = mysql_query("SHOW COLUMNS FROM call_supervision LIKE 'auto'");
				$has = ($r !== false && mysql_num_rows($r) > 0);
			}

			return $has;
		}

		/*
		/* THE "NO CREW BOSS" MARKERS on these calls (J7, design v0.6 §4.3):
		/*
		/*   array('ok', 'error', 'by_call' => callID => array('set_by', 'set_at'))
		/*
		/* Unmarked calls are absent. ok with nothing marked before the migration.
		*/

		function goat_sg_no_crew_boss_marks($callIDs)
		{
			$ids = goat_sg_clean_ids($callIDs);

			if (count($ids) === 0 || !goat_sg_have_no_crew_boss())
			{
				return array('ok' => true, 'error' => '', 'by_call' => array());
			}

			$res = mysql_query("SELECT callID AS call_id, set_by, set_at
			                    FROM call_no_crew_boss
			                    WHERE callID IN (" . implode(',', $ids) . ")");

			if ($res === false)
			{
				return array('ok' => false, 'error' => 'No Crew Boss marker lookup failed: ' . mysql_error(), 'by_call' => array());
			}

			$out = array();

			while ($row = mysql_fetch_object($res))
			{
				$out[(int) $row->call_id] = array('set_by' => (int) $row->set_by, 'set_at' => (int) $row->set_at);
			}

			return array('ok' => true, 'error' => '', 'by_call' => $out);
		}

		/*
		/* STRICT OVERLAP — THE ONE TEST, shared by the "Not linked" warning
		/* (goat_unlinked_boss_overlap()) and the Link button
		/* (goat_auto_link_candidates()), so the two cannot drift apart (P1).
		/* $a and $b carry 'start' / 'end' from goat_call_window(): start plus
		/* est_length hours, so an overnight call simply runs past midnight and
		/* there is no end-before-start case. Ending exactly as the other begins
		/* does not count. Probe P3 applies the same inequalities in SQL.
		*/

		function goat_sg_strict_overlap($a, $b)
		{
			return ($a['start'] < $b['end'] && $a['end'] > $b['start']);
		}

		/*
		/* A call's start instant in Melbourne wall-clock time — the SAME
		/* computation as goat_call_start_ts() in callout.php, which
		/* callout-sweep.php uses for "has the call started". Taken from a row
		/* already fetched rather than re-reading `calls` per call. false if the
		/* time will not parse.
		*/

		function goat_sg_call_start_melb($startDate, $startTime)
		{
			try {
				$dt = new DateTime(date('Y-m-d', (int) $startDate) . ' ' . $startTime,
				                   new DateTimeZone('Australia/Melbourne'));
				return $dt->getTimestamp();
			} catch (Exception $e) {
				return false;
			}
		}

		/*
		/* "NOT LINKED TO ITS CREW BOSS CALL" (D24, gate G2) — THE ONE
		/* IMPLEMENTATION. THE GOAT shows it in the booking dialog, All Bookings
		/* and the Times outstanding lane, and each reads it from here so the
		/* three cannot disagree.
		/*
		/* A call is UNLINKED-OVERLAPPING when:
		/*   - it is not cancelled and not itself a Crew Boss call;
		/*   - no link that COUNTS supervises it — goat_call_tiers() supervised_by
		/*     (review R16): a live, boss-named Crew Boss call with at least one
		/*     confirmed person. A link to a cancelled, empty or non-boss-named
		/*     call supervises nothing, so it does not hide the warning either —
		/*     unlike probe P3, which treated any link as a link;
		/*   - another call on the SAME booking is a live Crew Boss call
		/*     (goat_boss_call_is_live()) with at least one confirmed,
		/*     non-promo-pending person, and STRICTLY overlaps it in time
		/*     (goat_call_window(), so DST is handled; ending exactly as the
		/*     other begins does not count) — probe P3's rule;
		/*   - it is NOT marked "No Crew Boss" (call_no_crew_boss, J7 / P1 of
		/*     BRIEF-default-link-self-submit-off). Excluded HERE, once, so the
		/*     booking dialog, All Bookings and the Ops lane all stop showing it.
		/*
		/*   array('ok', 'error',
		/*         'by_call'          => callID => the overlapping Crew Boss call
		/*                               (earliest start; lowest id on a tie),
		/*         'count_by_booking' => bookingID => number of such calls)
		/*
		/* Bookings with none are absent from count_by_booking. Callers are
		/* displays and degrade on ok === false.
		/*
		/* A handful of batch queries for any number of bookings, never per call.
		*/

		function goat_unlinked_boss_overlap($bookingIDs)
		{
			$fail = array('ok' => false, 'error' => '', 'by_call' => array(), 'count_by_booking' => array());
			$none = array('ok' => true,  'error' => '', 'by_call' => array(), 'count_by_booking' => array());

			$bids = goat_sg_clean_ids($bookingIDs);

			if (count($bids) === 0)
			{
				return $none;
			}

			/* ---- 1. every call on the bookings ---- */

			$cres = mysql_query("SELECT c.id AS id, c.bookingID AS booking_id, c.call_name AS call_name,
			                            c.start_date AS start_date, c.start_time AS start_time,
			                            c.est_length AS est_length,
			                            " . goat_sg_cancelled_sql('c') . " AS cancelled_at
			                     FROM calls c
			                     WHERE c.bookingID IN (" . implode(',', $bids) . ")");

			if ($cres === false)
			{
				$fail['error'] = 'call lookup failed: ' . mysql_error();
				return $fail;
			}

			$calls  = array();   /* id -> info */
			$bossBy = array();   /* booking -> [live Crew Boss call ids] */

			while ($row = mysql_fetch_object($cres))
			{
				$id = (int) $row->id;

				if ($id <= 0 || (int) $row->start_date <= 0)
				{
					continue;   /* no date, no window: it cannot overlap anything */
				}

				$win = goat_call_window($row);

				if ($win['start'] === false)
				{
					continue;
				}

				$cancelled = ($row->cancelled_at !== null && (int) $row->cancelled_at > 0);
				$isBoss    = goat_is_boss_call_name((string) $row->call_name);

				$calls[$id] = array(
					'booking'   => (int) $row->booking_id,
					'start'     => (int) $win['start'],
					'end'       => (int) $win['end'],
					'cancelled' => $cancelled,
					'is_boss'   => $isBoss,
					'live_boss' => goat_boss_call_is_live($row->call_name, $row->cancelled_at)
				);

				if ($calls[$id]['live_boss'])
				{
					$b = (int) $row->booking_id;

					if (!isset($bossBy[$b]))
					{
						$bossBy[$b] = array();
					}

					$bossBy[$b][] = $id;
				}
			}

			if (count($bossBy) === 0)
			{
				return $none;
			}

			/* ---- 2. who is confirmed on the live Crew Boss calls ---- */

			$allBoss = array();

			foreach ($bossBy as $b => $list)
			{
				foreach ($list as $id)
				{
					$allBoss[] = $id;
				}
			}

			$roster = goat_sg_confirmed_roster($allBoss);

			if (!$roster['ok'])
			{
				$fail['error'] = $roster['error'];
				return $fail;
			}

			/*
			/* ---- 3. which calls already have a link that COUNTS (review R16) ----
			/*
			/* Asked of goat_call_tiers(), not re-derived: a link counts exactly
			/* when the tier says so — supervised_by > 0, i.e. a live, boss-named
			/* Crew Boss call with at least one confirmed, non-promo-pending
			/* person. A link to an empty, cancelled or non-boss-named call
			/* supervises nothing, so it does not hide this warning either.
			/* Only the candidate calls are tiered: live, not themselves boss
			/* calls, on a booking that has a live boss call.
			*/

			$cand = array();

			foreach ($calls as $id => $c)
			{
				if (!$c['cancelled'] && !$c['is_boss'] && isset($bossBy[$c['booking']]))
				{
					$cand[] = $id;
				}
			}

			$linked = array();

			if (count($cand) > 0)
			{
				$t = goat_call_tiers($cand);

				if (!$t['ok'])
				{
					$fail['error'] = $t['error'];
					return $fail;
				}

				foreach ($t['by_call'] as $cid => $info)
				{
					if ((int) $info['supervised_by'] > 0)
					{
						$linked[(int) $cid] = true;
					}
				}
			}

			/* ---- 3b. calls marked "No Crew Boss" (J7) — never warned about ---- */

			$marks = goat_sg_no_crew_boss_marks($cand);

			if (!$marks['ok'])
			{
				$fail['error'] = $marks['error'];
				return $fail;
			}

			/* ---- 4. the test ---- */

			$byCall = array();
			$count  = array();

			foreach ($calls as $id => $c)
			{
				if ($c['cancelled'] || $c['is_boss'] || isset($linked[$id]) || !isset($bossBy[$c['booking']])
				    || isset($marks['by_call'][$id]))
				{
					continue;
				}

				$best = 0;

				foreach ($bossBy[$c['booking']] as $bid)
				{
					if ($bid === $id || !isset($roster['by_call'][$bid]) || count($roster['by_call'][$bid]) === 0)
					{
						continue;
					}

					$bc = $calls[$bid];

					if (!goat_sg_strict_overlap($bc, $c))
					{
						continue;   /* strict overlap */
					}

					if ($best === 0
					    || $bc['start'] < $calls[$best]['start']
					    || ($bc['start'] === $calls[$best]['start'] && $bid < $best))
					{
						$best = $bid;
					}
				}

				if ($best > 0)
				{
					$byCall[$id] = $best;

					if (!isset($count[$c['booking']]))
					{
						$count[$c['booking']] = 0;
					}

					$count[$c['booking']]++;
				}
			}

			return array('ok' => true, 'error' => '', 'by_call' => $byCall, 'count_by_booking' => $count);
		}

		/* The same, for a set of CALLS (the Ops lane's rows): their bookings, filtered back. */

		function goat_unlinked_boss_overlap_for_calls($callIDs)
		{
			$ids = goat_sg_clean_ids($callIDs);

			if (count($ids) === 0)
			{
				return array('ok' => true, 'error' => '', 'by_call' => array(), 'count_by_booking' => array());
			}

			$res = mysql_query("SELECT DISTINCT bookingID AS b FROM calls WHERE id IN (" . implode(',', $ids) . ")");

			if ($res === false)
			{
				return array('ok' => false, 'error' => 'booking lookup failed: ' . mysql_error(),
				             'by_call' => array(), 'count_by_booking' => array());
			}

			$bids = array();

			while ($r = mysql_fetch_object($res))
			{
				$bids[] = (int) $r->b;
			}

			$all = goat_unlinked_boss_overlap($bids);

			if (!$all['ok'])
			{
				return $all;
			}

			$want = array_flip($ids);
			$out  = array();

			foreach ($all['by_call'] as $cid => $boss)
			{
				if (isset($want[$cid]))
				{
					$out[$cid] = $boss;
				}
			}

			return array('ok' => true, 'error' => '', 'by_call' => $out, 'count_by_booking' => $all['count_by_booking']);
		}

		/*
		/* THE LINK BUTTON'S RULE — ONE IMPLEMENTATION (BRIEF-default-link-self-
		/* submit-off, Release 2, B2; DESIGN v0.6 §4.1; pre-check decisions
		/* P1-P6, J16-J18). Read by auto-link-supervision.php (dry_run, and the
		/* re-validation inside apply), no-crew-boss.php (may this call be
		/* marked?) and call-supervision.php `clear` (J7: write the marker when
		/* the button would offer the call just unlinked).
		/*
		/* Every live, not-boss-named call on the booking that STRICTLY overlaps
		/* at least one live Crew Boss call on the same booking gets a row.
		/* Calls overlapping none are absent: there is nothing to say about them.
		/*
		/*   array('ok', 'error', 'booking_id',
		/*         'boss_call_count' => live Crew Boss calls on the booking,
		/*         'rows'    => [row, ...] in call-time order,
		/*         'by_call' => child callID => row)
		/*
		/*   row = array('child_call', 'call_name', 'start_date' (Y-m-d),
		/*               'start_time' (H:i), 'boss_call' | 0, 'boss_call_name',
		/*               'boss_calls' => [array('call_id', 'call_name')],
		/*               'from_boss_call' | 0, 'outcome', 'reason',
		/*               'boss_has_confirmed' => 0|1, 'team_leads' => [userIDs],
		/*               'linked_to' => the boss call of ANY existing link | 0,
		/*                              whatever the outcome)
		/*
		/*   outcome  offer   no link; overlaps exactly one live Crew Boss call;
		/*                    not started; set would accept it.
		/*            relink  as offer, but its link points at a CANCELLED boss
		/*                    call (J17); from_boss_call names it.
		/*            marked  marked "No Crew Boss" (rule 5). Listed so the
		/*                    preview can offer Undo.
		/*            skip    with reason:
		/*                      already_linked  a link to a boss call that is not
		/*                                      cancelled — staffed or not, it is
		/*                                      left alone (rule 3, J17)
		/*                      started         rule 6
		/*                      two_boss_calls  rule 4
		/*                      team_lead_rule  rule 7: D17 (see below)
		/*
		/* Precedence, first match wins: marked, already_linked, started,
		/* two_boss_calls, team_lead_rule.
		/*
		/* THE OVERLAP is goat_sg_strict_overlap() over goat_call_window(), the
		/* test the "Not linked" warning uses (P1). Unlike the warning, the boss
		/* call needs NOBODY confirmed (J8): boss_has_confirmed = 0 rows are still
		/* offered, and the preview says the link takes effect once someone is
		/* confirmed (J16). "Confirmed" is goat_sg_confirmed_roster() —
		/* non-promo-pending — the definition under which a link COUNTS.
		/*
		/* A LINK is a call_supervision row whose boss end exists (INNER JOIN, as
		/* goat_supervision_boss_call()): a dangling or self row is no link, and
		/* the upsert simply replaces it.
		/*
		/* "HAS STARTED" is goat_sg_call_start_melb() <= $nowMelb: Melbourne
		/* wall-clock, as callout-sweep.php. $nowMelb is a unix timestamp
		/* (time()); the timezone lives in the call's start, not in "now".
		/*
		/* RULE 7 — WOULD call-supervision.php `set` REFUSE IT? Its refusals: not
		/* admin (the caller's gate), calls not found or on different bookings
		/* (impossible here: every id comes from this booking), a boss call that
		/* is not boss-named (impossible: B passes goat_boss_call_is_live()), and
		/* D17 when the boss call is cancelled or has nobody confirmed. D17 is
		/* re-run here exactly as set runs it — goat_d17_if_unlinked() on the
		/* call, only when the boss call has nobody confirmed. It cannot fire
		/* today: that helper only reports calls whose link COUNTS, and an offer
		/* has no link while a relink's link is to a cancelled call (P2). It
		/* stays as the safety re-check the brief asks for, so a future change
		/* to D17 refuses here rather than at the write.
		/*
		/* $ignoreMarks: treat marked calls as unmarked (rule 5 off). Used by
		/* no-crew-boss.php's checks, which ask whether the WARNING applies, not
		/* whether the button would offer.
		/*
		/* A failed lookup is ok === false: every caller either writes on this
		/* answer or refuses on it, so a broken query must never read as
		/* "nothing to link".
		*/

		function goat_auto_link_candidates($bookingID, $nowMelb, $ignoreMarks = false)
		{
			$bid  = (int) $bookingID;
			$now  = (int) $nowMelb;
			$fail = array('ok' => false, 'error' => '', 'booking_id' => $bid,
			              'boss_call_count' => 0, 'rows' => array(), 'by_call' => array());
			$out  = array('ok' => true, 'error' => '', 'booking_id' => $bid,
			              'boss_call_count' => 0, 'rows' => array(), 'by_call' => array());

			if ($bid <= 0)
			{
				$fail['error'] = 'booking_id required';
				return $fail;
			}

			/* ---- 1. every call on the booking ---- */

			$cres = mysql_query("SELECT c.id AS id, c.call_name AS call_name,
			                            c.start_date AS start_date, c.start_time AS start_time,
			                            c.est_length AS est_length,
			                            " . goat_sg_cancelled_sql('c') . " AS cancelled_at
			                     FROM calls c
			                     WHERE c.bookingID = " . $bid . "
			                     ORDER BY c.start_date ASC, c.start_time ASC, c.id ASC");

			if ($cres === false)
			{
				$fail['error'] = 'call lookup failed: ' . mysql_error();
				return $fail;
			}

			$calls   = array();   /* id -> info, call-time order */
			$bossIDs = array();   /* live Crew Boss calls with a window */

			while ($row = mysql_fetch_object($cres))
			{
				$id = (int) $row->id;

				if ($id <= 0 || (int) $row->start_date <= 0)
				{
					continue;   /* no date, no window: it cannot overlap anything */
				}

				$win = goat_call_window($row);

				if ($win['start'] === false)
				{
					continue;
				}

				$calls[$id] = array(
					'name'       => (string) $row->call_name,
					'start_date' => date('Y-m-d', (int) $row->start_date),
					'start_time' => substr((string) $row->start_time, 0, 5),
					'start'      => (int) $win['start'],
					'end'        => (int) $win['end'],
					'start_melb' => goat_sg_call_start_melb($row->start_date, $row->start_time),
					'cancelled'  => ($row->cancelled_at !== null && (int) $row->cancelled_at > 0),
					'is_boss'    => goat_is_boss_call_name((string) $row->call_name),
					'live_boss'  => goat_boss_call_is_live($row->call_name, $row->cancelled_at)
				);

				if ($calls[$id]['live_boss'])
				{
					$bossIDs[] = $id;
				}
			}

			$out['boss_call_count'] = count($bossIDs);

			if (count($bossIDs) === 0)
			{
				return $out;
			}

			/* ---- 2. which calls overlap which live Crew Boss calls ---- */

			$overlaps = array();   /* child -> [boss ids], call-time order */

			foreach ($calls as $id => $c)
			{
				if ($c['cancelled'] || $c['is_boss'])
				{
					continue;
				}

				foreach ($bossIDs as $b)
				{
					if ($b !== $id && goat_sg_strict_overlap($calls[$b], $c))
					{
						if (!isset($overlaps[$id]))
						{
							$overlaps[$id] = array();
						}

						$overlaps[$id][] = $b;
					}
				}
			}

			if (count($overlaps) === 0)
			{
				return $out;
			}

			$childIDs = array_keys($overlaps);

			/* ---- 3. existing links: boss end must exist; self rows are no link ---- */

			$links = array();   /* child -> array('boss', 'boss_cancelled') */

			$lres = mysql_query("SELECT s.child_call AS child_call, s.boss_call AS boss_call,
			                            " . goat_sg_cancelled_sql('cb') . " AS boss_cancelled_at
			                     FROM call_supervision s
			                     INNER JOIN calls cb ON cb.id = s.boss_call
			                     WHERE s.child_call IN (" . implode(',', $childIDs) . ")
			                       AND s.boss_call <> s.child_call");

			if ($lres === false)
			{
				$fail['error'] = 'supervision lookup failed: ' . mysql_error();
				return $fail;
			}

			while ($lrow = mysql_fetch_object($lres))
			{
				$links[(int) $lrow->child_call] = array(
					'boss'           => (int) $lrow->boss_call,
					'boss_cancelled' => ($lrow->boss_cancelled_at !== null && (int) $lrow->boss_cancelled_at > 0)
				);
			}

			/* ---- 4. markers, and who is confirmed on the boss calls ---- */

			$marks = array('ok' => true, 'error' => '', 'by_call' => array());

			if (!$ignoreMarks)
			{
				$marks = goat_sg_no_crew_boss_marks($childIDs);

				if (!$marks['ok'])
				{
					$fail['error'] = $marks['error'];
					return $fail;
				}
			}

			$roster = goat_sg_confirmed_roster($bossIDs);

			if (!$roster['ok'])
			{
				$fail['error'] = $roster['error'];
				return $fail;
			}

			/* ---- 5. rules 3-6 ---- */

			$d17Ask = array();   /* rule 7 is batched: children of unstaffed boss calls */

			foreach ($overlaps as $id => $bosses)
			{
				$c    = $calls[$id];
				$list = array();

				foreach ($bosses as $b)
				{
					$list[] = array('call_id' => $b, 'call_name' => $calls[$b]['name']);
				}

				$row = array(
					'child_call'         => $id,
					'call_name'          => $c['name'],
					'start_date'         => $c['start_date'],
					'start_time'         => $c['start_time'],
					'boss_call'          => 0,
					'boss_call_name'     => '',
					'boss_calls'         => $list,
					'from_boss_call'     => 0,
					'outcome'            => 'skip',
					'reason'             => '',
					'boss_has_confirmed' => 0,
					'team_leads'         => array()
				);

				$link    = isset($links[$id]) ? $links[$id] : null;
				$started = ($c['start_melb'] !== false && $c['start_melb'] <= $now);

				$row['linked_to'] = ($link !== null) ? $link['boss'] : 0;

				if (isset($marks['by_call'][$id]))
				{
					$row['outcome'] = 'marked';
				}
				else if ($link !== null && !$link['boss_cancelled'])
				{
					$row['reason']         = 'already_linked';
					$row['boss_call']      = $link['boss'];
					$row['boss_call_name'] = isset($calls[$link['boss']]) ? $calls[$link['boss']]['name'] : '';
				}
				else if ($started)
				{
					$row['reason'] = 'started';
				}
				else if (count($bosses) > 1)
				{
					$row['reason'] = 'two_boss_calls';
				}
				else
				{
					$b = $bosses[0];

					$row['outcome']            = ($link !== null) ? 'relink' : 'offer';
					$row['from_boss_call']     = ($link !== null) ? $link['boss'] : 0;
					$row['boss_call']          = $b;
					$row['boss_call_name']     = $calls[$b]['name'];
					$row['boss_has_confirmed'] = (isset($roster['by_call'][$b]) && count($roster['by_call'][$b]) > 0) ? 1 : 0;

					if ($row['boss_has_confirmed'] === 0)
					{
						$d17Ask[] = $id;
					}
				}

				$out['by_call'][$id] = $row;
			}

			/* ---- 6. rule 7: D17, exactly as call-supervision.php set asks it ---- */

			if (count($d17Ask) > 0)
			{
				$d = goat_d17_if_unlinked($d17Ask);

				if (!$d['ok'])
				{
					$fail['error'] = $d['error'];
					return $fail;
				}

				foreach ($d['by_call'] as $cid => $leads)
				{
					$cid = (int) $cid;

					if (isset($out['by_call'][$cid]))
					{
						$out['by_call'][$cid]['outcome']    = 'skip';
						$out['by_call'][$cid]['reason']     = 'team_lead_rule';
						$out['by_call'][$cid]['team_leads'] = $leads;
					}
				}
			}

			foreach ($calls as $id => $c)
			{
				if (isset($out['by_call'][$id]))
				{
					$out['rows'][] = $out['by_call'][$id];
				}
			}

			return $out;
		}

		/*
		/* MAY THESE CALLS BE MARKED "NO CREW BOSS"? (B4 `set`, and B3's `mark`
		/* list.) A call may be marked only when the "Not linked" warning could
		/* apply to it:
		/*
		/*   - it has no call_supervision link ("Unlink it first.") — a link to
		/*     a cancelled boss call is still a link here;
		/*   - it is on a booking, not cancelled, not itself a Crew Boss call,
		/*     and strictly overlaps a live Crew Boss call on that booking.
		/*
		/* Started calls MAY be marked: the marker records a fact about the
		/* call, and the warning shows on past calls too. An already-marked call
		/* passes (set replaces the row).
		/*
		/*   array('ok', 'error', 'by_call' => callID => '' (may) | refusal text)
		*/

		function goat_no_crew_boss_refusals($callIDs, $nowMelb)
		{
			$ids  = goat_sg_clean_ids($callIDs);
			$fail = array('ok' => false, 'error' => '', 'by_call' => array());
			$out  = array('ok' => true, 'error' => '', 'by_call' => array());

			if (count($ids) === 0)
			{
				return $out;
			}

			$res = mysql_query("SELECT id, bookingID AS booking_id FROM calls WHERE id IN (" . implode(',', $ids) . ")");

			if ($res === false)
			{
				$fail['error'] = 'call lookup failed: ' . mysql_error();
				return $fail;
			}

			$bookingOf = array();

			while ($row = mysql_fetch_object($res))
			{
				$bookingOf[(int) $row->id] = (int) $row->booking_id;
			}

			$byBooking = array();

			foreach ($ids as $cid)
			{
				if (!isset($bookingOf[$cid]) || $bookingOf[$cid] <= 0)
				{
					$out['by_call'][$cid] = 'Call not found.';
					continue;
				}

				$byBooking[$bookingOf[$cid]] = true;
			}

			foreach (array_keys($byBooking) as $bid)
			{
				$cand = goat_auto_link_candidates($bid, $nowMelb, true);

				if (!$cand['ok'])
				{
					$fail['error'] = $cand['error'];
					return $fail;
				}

				foreach ($ids as $cid)
				{
					if (!isset($bookingOf[$cid]) || $bookingOf[$cid] !== $bid)
					{
						continue;
					}

					if (!isset($cand['by_call'][$cid]))
					{
						$out['by_call'][$cid] = 'It doesn\'t overlap a Crew Boss call on its booking, '
						                      . 'or it is cancelled, or it is a Crew Boss call itself.';
						continue;
					}

					$row = $cand['by_call'][$cid];

					if ($row['linked_to'] > 0)
					{
						$out['by_call'][$cid] = 'It is linked to a Crew Boss call. Unlink it first.';
						continue;
					}

					$out['by_call'][$cid] = '';
				}
			}

			return $out;
		}

		/*
		/* Plain words for a candidate's reason, for refusals and the preview
		/* (BRIEF C2). One place, so the endpoint and THE GOAT say the same thing.
		*/

		function goat_auto_link_reason_text($row)
		{
			$r = isset($row['reason']) ? $row['reason'] : '';

			if (isset($row['outcome']) && $row['outcome'] === 'marked')
			{
				return 'Marked No Crew Boss.';
			}

			if ($r === 'already_linked')
			{
				return 'Already linked to Crew Boss call #' . (int) $row['boss_call'] . '.';
			}

			if ($r === 'started')
			{
				return 'It has already started.';
			}

			if ($r === 'two_boss_calls')
			{
				return 'Overlaps two Crew Boss calls — choose one in the editor below.';
			}

			if ($r === 'team_lead_rule')
			{
				return 'Has ' . count($row['team_leads']) . ' Team Leads and no Crew Boss.';
			}

			return $r === '' ? '' : ('Can\'t be linked (' . $r . ').');
		}

	}

?>
