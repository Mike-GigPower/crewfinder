<?php

	/*
	/* SHARED INCLUDE — not an endpoint. No output, no auth of its own; the
	/* including endpoint has already gated and scoped the request.
	/*
	/* Resolves "who does this crew member contact for this call?" — one ladder,
	/* walked top-down, skipping any rung whose answer IS the viewer.
	/*
	/* REORDERED IN SLICE B OF BRIEF-crew-self-times (Nov 2026, D18 and E):
	/* a Crew Boss now outranks a Team Lead, and the boss call is found by
	/* explicit link only.
	/*
	/*   1. in_call_boss      — a confirmed, non-promo-pending is_crew_boss=1
	/*      (role crew_boss)    resource on the viewer's OWN call (the Crew
	/*                          Boss pill)
	/*   2. boss_call         — the live Crew Boss call LINKED to the viewer's
	/*                          call through call_supervision: its ticked person
	/*                          (Crew Boss pill, else Team Lead tick), else
	/*                          everyone confirmed on it — promo-pending people
	/*                          excluded throughout
	/*   3. in_call_boss      — a confirmed, non-promo-pending is_call_boss=1
	/*      (role team_lead)    resource on the viewer's OWN call (the Team
	/*                          Lead tick). Reached
	/*                          ONLY when rungs 1-2 found nobody, and SKIPPED
	/*                          when the viewer holds the Crew Boss pill on the
	/*                          call — a Crew Boss is not pointed at a Team Lead
	/*   4. onsite            — bookings.onsiteUserID, falling back to
	/*                          bookings.userID
	/*
	/* THE SAME-BOOKING OVERLAP FALLBACK IS GONE (decision E / D22). It used to
	/* stand in for rung 2 wherever no link existed, and is how a crew member
	/* was handed the boss of a crew they were not on. A call with no link now
	/* goes straight from rung 1 to rung 3. Removing it was preceded by
	/* PROBE-crew-self-times-2026-11-04.sql P3, which lists the upcoming calls
	/* that depended on it.
	/*
	/* Rungs 1 and 3 may return several when the data is wrong (two pills, two
	/* ticks); returning all makes the slip visible instead of coin-flipping.
	/* Rung 4 returns at most one.
	/*
	/* WHEN THE VIEWER IS ON A CREW BOSS CALL, rungs 1-3 are skipped and the
	/* ladder starts at the on-site contact, as it always has.
	/*
	/* THE `source` VOCABULARY IS UNCHANGED (in_call_boss, boss_call, onsite),
	/* because Crew Hub renders from it and lives in another repo. Rungs 1 and
	/* 3 both report in_call_boss and are told apart by an ADDITIVE `role`
	/* field ('crew_boss' | 'team_lead'), present on in_call_boss contacts only.
	/*
	/* PRIVACY: returns ONLY the resolved contact(s) — name, mobile, phone. Never
	/* the roster. A crew member cannot enumerate colleagues through this.
	/*
	/* PHP 5.x — mysql_*, no null-coalescing (??), no short array syntax.
	*/

	/*
	/* Is this call a DEDICATED Crew Boss call?
	/*
	/* The only available signal is the call name — the paygrade was investigated
	/* and rejected (callpaygradeID reflects what a person is PAID as, on every
	/* call they work, not a per-call role).
	/*
	/* Exclusion-based, not an include-list. Live-data analysis (avg confirmed
	/* resources per call name) showed every "%boss%" name sitting at ~1.0
	/* confirmed EXCEPT two working calls, and showed names that a hand-written
	/* include-list had already missed ("Steel Boss", "Extra Crew Boss"). So:
	/* everything with "boss" in it counts, minus cancellations and minus the two
	/* known working calls.
	/*
	/* KEEP IN SYNC with goatIsBossCallName() in templates/index.html.
	*/

	/*
	/* THE 'cancel' TEST BELOW MUST NOT BE REMOVED when calls.cancelled_at
	/* arrives. It is not superseded by the column; the two cover different
	/* eras.
	/*
	/* Before the cancellation feature, ops cancelled a call by RENAMING it, and
	/* production carries years of calls marked that way and no other -- "Crew
	/* Boss Cancelled" is live data. The column catches cancellations made from
	/* now on; this string catches every one made before. Swap one for the other
	/* and every historical cancellation un-hides at once, each of them able to
	/* supervise again -- through a link, in the scope, tier and contact
	/* helpers -- and hand a crew member a contact who is not attending. (The
	/* overlap search this sentence first described was removed in slice B of
	/* BRIEF-crew-self-times; the rule holds for every reader that remains.)
	/*
	/* Nothing NEW may branch on this string -- the column is the machine-readable
	/* truth. This clause is here to keep faith with the old data, and it should
	/* outlive the feature.
	*/

	function goat_is_boss_call_name($name)
	{
		$n = strtolower(trim($name));

		if (strpos($n, 'boss') === false)   return false;   /* not a boss call at all */
		if (strpos($n, 'cancel') !== false) return false;   /* "Crew Boss Cancelled" */

		/* working calls that merely have "Boss" in the name — verified against
		   live data as carrying 4-5 confirmed resources, not one */

		$working = array('imag & boss', 'crew boss & pushers');

		if (in_array($n, $working)) return false;

		return true;
	}

	/*
	/* A call's absolute wall-clock window.
	/* start_date is a unix timestamp at LOCAL MIDNIGHT; start_time is a TIME;
	/* est_length is a DOUBLE in hours. Same arithmetic as my-call-offers.php.
	*/

	function goat_call_window($call)
	{
		$dateStr   = date('Y-m-d', (int) $call->start_date);
		$startUnix = strtotime($dateStr . ' ' . $call->start_time);
		$endUnix   = $startUnix + (int) round(((double) $call->est_length) * 3600);

		return array('start' => $startUnix, 'end' => $endUnix);
	}

	/*
	/* Is calls.cancelled_at present on this environment?
	/*
	/* GUARDED, AND FOR A SHARPER REASON THAN THE OTHER READS. This file has no
	/* error path: every query is wrapped in `if ($res !== false)` and a failure
	/* silently skips the rung. On an environment without the column both
	/* boss-call lookups below would fail, every crew member would fall through
	/* to rung 3, and each one would be handed the ON-SITE CONTACT instead of
	/* their boss -- with no error, no 500 and nothing to notice. The wrong name
	/* on every shift card is the exact failure this file exists to prevent.
	/*
	/* function_exists() because get-booking.php and get-calls-bulk.php declare
	/* the same helper, and supervision-graph.php includes this file.
	*/

	if (!function_exists('goat_calls_have_cancelled'))
	{
		function goat_calls_have_cancelled()
		{
			static $has = null;

			if ($has === null)
			{
				$r   = mysql_query("SHOW COLUMNS FROM calls LIKE 'cancelled_at'");
				$has = ($r !== false && mysql_num_rows($r) > 0);
			}

			return $has;
		}
	}

	/*
	/* Is call_crew_map.is_crew_boss present on this environment?
	/*
	/* The Crew Boss pill marker, added by slice A of BRIEF-crew-self-times.
	/* GUARDED FOR THE SAME REASON AS cancelled_at ABOVE: every read of it in
	/* this file and in supervision-graph.php would otherwise fail on an
	/* environment where the ALTER has not run, and most of those reads
	/* degrade silently (empty scope, on-site contact for everyone). Absent
	/* column reads as "nobody holds the pill", which is exactly the state
	/* before slice A.
	/*
	/* Lives here, not in supervision-graph.php, because this is the bottom of
	/* the one-way include chain and both files need it.
	*/

	if (!function_exists('goat_ccm_have_crew_boss'))
	{
		function goat_ccm_have_crew_boss()
		{
			static $has = null;

			if ($has === null)
			{
				$r   = mysql_query("SHOW COLUMNS FROM call_crew_map LIKE 'is_crew_boss'");
				$has = ($r !== false && mysql_num_rows($r) > 0);
			}

			return $has;
		}
	}

	/*
	/* users row -> contact array. Names are stored HTML-ENCODED in `users`, so
	/* decode on the way out (matches get-booking.php).
	*/

	function goat_contact_from_user($u, $source, $callName)
	{
		$first = html_entity_decode($u->firstname, ENT_QUOTES);
		$last  = html_entity_decode($u->lastname,  ENT_QUOTES);

		$c = array(
			'source' => $source,
			'name'   => trim($first . ' ' . $last),
			'mobile' => ($u->mobile === null ? '' : $u->mobile),
			'phone'  => ($u->phone  === null ? '' : $u->phone)
		);

		/* only meaningful for rung 2 — names the linked boss call the contact
		   comes from ("Emil (Crew Boss - Steel)"). Since slice B rung 2 has
		   exactly one boss call, but the field shape is unchanged. */

		if ($source === 'boss_call')
			$c['call_name'] = $callName;

		return $c;
	}

	/*
	/* SAME-HUMAN GUARD.
	/*
	/* The same person can hold TWO user rows — a crew record and a contact
	/* record (a crew manager who is also a booking's on-site contact is the
	/* live case: Monty is both). Skip-self by userID alone then hands them
	/* back to themselves under the other identity — "your contact: you".
	/*
	/* The mobile is the cheapest reliable cross-record identity. Compared on
	/* digits only, so "0466 600 031" and "0466600031" match.
	*/

	function goat_digits_only($s)
	{
		return preg_replace('/[^0-9]/', '', (string) $s);
	}

	function goat_user_mobile($userID)
	{
		static $mob = array();

		$userID = (int) $userID;

		if ($userID <= 0) return '';
		if (isset($mob[$userID])) return $mob[$userID];

		$mob[$userID] = '';

		$res = mysql_query("SELECT mobile FROM users WHERE id = " . $userID . " LIMIT 1");

		if ($res !== false)
		{
			$u = mysql_fetch_object($res);
			if ($u) $mob[$userID] = goat_digits_only($u->mobile);
		}

		return $mob[$userID];
	}

	/* A blank viewer mobile can never match — we do not want every
	   number-less crew member colliding with every other one. */

	function goat_same_human($viewerMobile, $otherMobile)
	{
		if ($viewerMobile === '') return false;

		return (goat_digits_only($otherMobile) === $viewerMobile);
	}

	/*
	/* THE RESOLVER. Returns an array of 0..n contacts (see above).
	/*
	/* Cached per (callID, viewerUserID) for the life of the request — a shift
	/* list can contain the same call twice and the viewer never changes mid-request.
	*/

	function goat_resolve_call_contact($callID, $viewerUserID)
	{
		static $cache = array();

		$callID       = (int) $callID;
		$viewerUserID = (int) $viewerUserID;

		if ($callID <= 0) return array();

		$ck = $callID . ':' . $viewerUserID;

		if (isset($cache[$ck])) return $cache[$ck];

		$cache[$ck] = array();   /* provisional — overwritten on each return path */

		/* ---- the viewer's own call ---- */

		$res  = mysql_query("SELECT id, bookingID, call_name, start_date, start_time, est_length
		                     FROM calls WHERE id = " . $callID . " LIMIT 1");
		$call = ($res === false) ? false : mysql_fetch_object($res);

		if (!$call) return array();

		$viewerOnBossCall = goat_is_boss_call_name($call->call_name);

		/* A CANCELLED CALL CANNOT SUPERVISE ANYTHING. Applied to the rung-2
		   lookup below, alongside -- never instead of -- the name test in
		   goat_is_boss_call_name(). See the note on that function. */

		$cancelSql = goat_calls_have_cancelled() ? ' AND cancelled_at IS NULL' : '';

		/* The Crew Boss pill, or 0 where slice A has not run — see
		   goat_ccm_have_crew_boss(). */

		$crewBossCol = goat_ccm_have_crew_boss() ? 'ccm.is_crew_boss' : '0';

		/* resolved once; every rung below skips a contact that IS the viewer,
		   whether by userID or by the same-human mobile match */

		$viewerMobile = goat_user_mobile($viewerUserID);

		/* Crew on a dedicated boss call skip rungs 1-3: the ladder starts
		   below them, at the on-site contact. Unchanged by slice B. */

		if (!$viewerOnBossCall)
		{
			/*
			/* ONE READ OF THE VIEWER'S OWN CALL serves rungs 1 and 3, which
			/* ask the same rows two different questions.
			/*
			/* is_call_boss is a binary(50) column — makeboss writes the STRING
			/* '1', stored as byte 0x31 null-padded to 50 bytes. "is_call_boss = 1"
			/* in SQL is unreliable across MySQL versions on that type, so we do
			/* NOT filter on it in SQL: we select it and cast in PHP, exactly as
			/* get-booking.php and the other endpoints do. (int) reads the leading
			/* byte and stops at the null, giving a clean 1 or 0.
			/*
			/* AN UNANSWERED PROMOTION IS NEVER A CONTACT, on any boss rung: a
			/* backup promoted to 5 who has not accepted is not known to be coming,
			/* and goat_call_tiers() does not count them as Crew Boss or Team Lead
			/* either. Rung 1 skips them, rung 2 since review R4, and rung 3 since
			/* review R12 (5 Nov 2026 — smoke test, call 37886: a promo-pending
			/* Team Lead was handed to crew as their contact).
			*/

			$r1 = mysql_query("SELECT ccm.userID AS user_id,
			                          ccm.is_call_boss AS is_call_boss,
			                          " . $crewBossCol . " AS is_crew_boss,
			                          (cpa.id IS NOT NULL) AS promo_pending,
			                          u.firstname, u.lastname, u.mobile, u.phone
			                   FROM call_crew_map ccm
			                   LEFT JOIN users u ON u.id = ccm.userID
			                   LEFT JOIN call_promo_ack cpa
			                          ON cpa.callID = ccm.callID
			                         AND cpa.userID = ccm.userID
			                         AND cpa.acked_at IS NULL
			                   WHERE ccm.callID  = " . $callID . "
			                     AND ccm.status  = 5
			                   ORDER BY u.lastname ASC, u.firstname ASC");

			$inCallCrewBoss = array();
			$inCallTeamLead = array();

			/*
			/* THE VIEWER'S OWN ROW is read too (it used to be excluded in SQL),
			/* for one fact only: do they hold the Crew Boss pill here? If so the
			/* Team Lead rung is skipped below (review R10) — a Crew Boss is not
			/* told to ring the Team Lead they outrank. Their row is never a
			/* contact; it is skipped before anything else is read from it.
			*/

			$viewerHoldsPill = false;

			if ($r1 !== false)
			{
				while ($row = mysql_fetch_object($r1))
				{
					if ((int) $row->user_id === $viewerUserID)
					{
						if ((int) $row->is_crew_boss === 1 && (int) $row->promo_pending !== 1)
						{
							$viewerHoldsPill = true;
						}

						continue;
					}

					if ($row->firstname === null) continue;
					if (goat_same_human($viewerMobile, $row->mobile)) continue;

					if ((int) $row->is_crew_boss === 1 && (int) $row->promo_pending !== 1)
					{
						$c = goat_contact_from_user($row, 'in_call_boss', '');
						$c['role'] = 'crew_boss';
						$inCallCrewBoss[] = $c;
					}

					if ((int) $row->is_call_boss === 1 && (int) $row->promo_pending !== 1)
					{
						$c = goat_contact_from_user($row, 'in_call_boss', '');
						$c['role'] = 'team_lead';
						$inCallTeamLead[] = $c;
					}
				}
			}

			/* ---- RUNG 1 — Crew Boss pill on the viewer's call ---- */

			if (count($inCallCrewBoss) > 0)
			{
				$cache[$ck] = $inCallCrewBoss;
				return $inCallCrewBoss;
			}

			/*
			/* ---- RUNG 2 — the Crew Boss call LINKED to this call ----
			/*
			/* EXPLICIT LINK ONLY (decision E). There is no overlap fallback any
			/* more: no link, or a link that resolves to nobody, falls to rung 3.
			/*
			/* GUARDED AT THE CALL SITE, AND THERE IS NO INCLUDE ABOVE.
			/* supervision-graph.php includes THIS file, for
			/* goat_is_boss_call_name(), so including it back would be circular —
			/* and this file declares at top level with no function_exists guard,
			/* so the redeclare would be fatal rather than a no-op. Wherever
			/* supervision-graph.php is not loaded, this rung finds nothing.
			/*
			/* THE NAME TEST NOW APPLIES (decision B / D20). Only Crew Boss calls
			/* supervise; a link from any other call is a mistake, which
			/* call-supervision.php now refuses to create. Until the existing
			/* ones are cleared (probe P1) they are ignored here, exactly as
			/* goat_call_tiers() ignores them.
			/*
			/* NOT CANCELLED (decision C), and filtered though it looks redundant:
			/* D12 permits re-adding a cancelled crew member, which can leave a
			/* cancelled boss call carrying a confirmed resource again.
			/*
			/* A call cannot boss itself. Slice B of call-supervision rejects it,
			/* so this should never fire — it costs one comparison.
			*/

			$supBoss = function_exists('goat_supervision_boss_call')
			         ? goat_supervision_boss_call($callID)
			         : 0;

			if ($supBoss > 0 && $supBoss !== $callID)
			{
				$sres = mysql_query("SELECT id, call_name FROM calls
				                     WHERE id = " . ((int) $supBoss) . $cancelSql . " LIMIT 1");

				$bc = ($sres === false) ? false : mysql_fetch_object($sres);

				if ($bc && goat_is_boss_call_name($bc->call_name))
				{
					/*
					/* UNANSWERED PROMOTIONS ARE NOT CONTACTS (review R4). A backup
					/* promoted onto the boss call who has not accepted is not known
					/* to be coming, and goat_call_tiers() does not count them —
					/* so the crew are not handed their number either.
					*/

					$cres = mysql_query("SELECT ccm.is_call_boss AS is_call_boss,
					                            " . $crewBossCol . " AS is_crew_boss,
					                            u.firstname, u.lastname, u.mobile, u.phone
					                     FROM call_crew_map ccm
					                     LEFT JOIN users u ON u.id = ccm.userID
					                     LEFT JOIN call_promo_ack cpa
					                            ON cpa.callID = ccm.callID
					                           AND cpa.userID = ccm.userID
					                           AND cpa.acked_at IS NULL
					                     WHERE ccm.callID  = " . ((int) $bc->id) . "
					                       AND ccm.status  = 5
					                       AND ccm.userID <> " . $viewerUserID . "
					                       AND cpa.id IS NULL
					                     ORDER BY u.lastname ASC, u.firstname ASC");

					/*
					/* POC nomination — "the boss of the bosses".
					/*
					/* A big call can name several crew bosses on one Crew Boss
					/* call. The point of contact is the TICKED person, by D's
					/* rule: the Crew Boss pill holder, else the Team Lead tick
					/* (makeboss enforces one per call), read with the (int) cast
					/* for the binary(50) reason above. If NOBODY is ticked we fall
					/* back to every confirmed boss — the multi-name card is itself
					/* the prompt to go nominate. One loop collects all three.
					*/

					$bossAll = array();
					$bossCB  = array();
					$bossTL  = array();

					if ($cres !== false)
					{
						while ($cr = mysql_fetch_object($cres))
						{
							if ($cr->firstname === null) continue;
							if (goat_same_human($viewerMobile, $cr->mobile)) continue;

							$contact = goat_contact_from_user($cr, 'boss_call', $bc->call_name);

							$bossAll[] = $contact;

							if ((int) $cr->is_crew_boss === 1) $bossCB[] = $contact;
							if ((int) $cr->is_call_boss === 1) $bossTL[] = $contact;
						}
					}

					if (count($bossCB) > 0)      $out = $bossCB;
					else if (count($bossTL) > 0) $out = $bossTL;
					else                         $out = $bossAll;

					if (count($out) > 0)
					{
						$cache[$ck] = $out;
						return $out;
					}
				}
			}

			/*
			/* ---- RUNG 3 — Team Lead tick on the viewer's call ----
			/*
			/* Only reached when nobody ELSE holds the Crew Boss pill on this call
			/* and no linked Crew Boss call produced a contact (D18). SKIPPED when
			/* the VIEWER holds the pill (review R10): they fall to the on-site
			/* contact rather than to a Team Lead below them. add-call.php's
			/* makeboss handler clears is_call_boss on every other row before
			/* setting it, so there is normally at most one.
			*/

			if (count($inCallTeamLead) > 0 && !$viewerHoldsPill)
			{
				$cache[$ck] = $inCallTeamLead;
				return $inCallTeamLead;
			}
		}

		/* ---- RUNG 4 — on-site contact ----
		   onsiteUserID falls back to userID (the booking contact) when blank —
		   the same fallback create-booking.php applies on write. */

		$bkres = mysql_query("SELECT onsiteUserID, userID FROM bookings
		                      WHERE id = " . ((int) $call->bookingID) . " LIMIT 1");
		$bk    = ($bkres === false) ? false : mysql_fetch_object($bkres);

		if ($bk)
		{
			$contactID = (int) $bk->onsiteUserID;

			if ($contactID <= 0) $contactID = (int) $bk->userID;

			if ($contactID > 0 && $contactID !== $viewerUserID)
			{
				$ures = mysql_query("SELECT firstname, lastname, mobile, phone
				                     FROM users WHERE id = " . $contactID . " LIMIT 1");

				if ($ures !== false)
				{
					$u = mysql_fetch_object($ures);

					if ($u && $u->firstname !== null
					    && !goat_same_human($viewerMobile, $u->mobile))
					{
						$out = array(goat_contact_from_user($u, 'onsite', ''));
						$cache[$ck] = $out;
						return $out;
					}
				}
			}
		}

		/* nothing resolvable — portal omits the contact block entirely */

		return array();
	}

?>
