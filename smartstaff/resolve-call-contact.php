<?php

	/*
	/* SHARED INCLUDE — not an endpoint. No output, no auth of its own; the
	/* including endpoint has already gated and scoped the request.
	/*
	/* Resolves "who does this crew member contact for this call?" — one ladder,
	/* walked top-down, skipping any rung whose answer IS the viewer:
	/*
	/*   1. in_call_boss — a confirmed is_call_boss=1 resource on the viewer's OWN call
	/*   2. boss_call    — confirmed resources on dedicated Crew Boss calls in the
	/*                     same booking whose time window OVERLAPS the viewer's call
	/*   3. onsite       — bookings.onsiteUserID, falling back to bookings.userID
	/*
	/* Rungs 1 and 3 return at most one contact. Rung 2 may return several: where
	/* two boss calls overlap (e.g. "Crew Boss A" / "Crew Boss B", split crews)
	/* NOTHING in the data says which crew the viewer is on, so we return both
	/* rather than silently guessing. Same for a boss call carrying two confirmed
	/* resources — ops policy is one per boss call; returning both makes a slip
	/* visible instead of coin-flipping.
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

		/* only meaningful for rung 2 — lets the portal disambiguate when two
		   boss calls overlap ("Emil (Crew Boss - Steel) / Joe (Crew Boss - Load Out)") */

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

		/* resolved once; every rung below skips a contact that IS the viewer,
		   whether by userID or by the same-human mobile match */

		$viewerMobile = goat_user_mobile($viewerUserID);

		/* ---- RUNG 1 — in-call boss ----
		   Skipped when the viewer is themselves on a dedicated boss call: the
		   ladder starts below them. add-call.php's makeboss handler clears
		   is_call_boss on every other row before setting it, so there is
		   structurally at most one. */

		if (!$viewerOnBossCall)
		{
			$r1 = mysql_query("SELECT u.firstname, u.lastname, u.mobile, u.phone
			                   FROM call_crew_map ccm
			                   LEFT JOIN users u ON u.id = ccm.userID
			                   WHERE ccm.callID       = " . $callID . "
			                     AND ccm.is_call_boss = 1
			                     AND ccm.status       = 5
			                     AND ccm.userID      <> " . $viewerUserID . "
			                   LIMIT 1");

			if ($r1 !== false)
			{
				$row = mysql_fetch_object($r1);

				if ($row && $row->firstname !== null
				    && !goat_same_human($viewerMobile, $row->mobile))
				{
					$out = array(goat_contact_from_user($row, 'in_call_boss', ''));
					$cache[$ck] = $out;
					return $out;
				}
			}
		}

		/* ---- RUNG 2 — overlapping dedicated boss calls in the same booking ----
		   SQL does a cheap prefilter; goat_is_boss_call_name() is authoritative. */

		if (!$viewerOnBossCall)
		{
			$w   = goat_call_window($call);
			$out = array();

			$bres = mysql_query("SELECT id, call_name, start_date, start_time, est_length
			                     FROM calls
			                     WHERE bookingID  = " . ((int) $call->bookingID) . "
			                       AND id        <> " . $callID . "
			                       AND call_name LIKE '%boss%'
			                     ORDER BY start_date ASC, start_time ASC");

			if ($bres !== false)
			{
				while ($bc = mysql_fetch_object($bres))
				{
					if (!goat_is_boss_call_name($bc->call_name)) continue;

					$bw = goat_call_window($bc);

					/* strict overlap — a boss call ending exactly as yours begins
					   does not count */

					if (!($bw['start'] < $w['end'] && $bw['end'] > $w['start'])) continue;

					$cres = mysql_query("SELECT u.firstname, u.lastname, u.mobile, u.phone
					                     FROM call_crew_map ccm
					                     LEFT JOIN users u ON u.id = ccm.userID
					                     WHERE ccm.callID  = " . ((int) $bc->id) . "
					                       AND ccm.status  = 5
					                       AND ccm.userID <> " . $viewerUserID . "
					                     ORDER BY u.lastname ASC, u.firstname ASC");

					if ($cres !== false)
					{
						while ($cr = mysql_fetch_object($cres))
						{
							if ($cr->firstname === null) continue;
							if (goat_same_human($viewerMobile, $cr->mobile)) continue;
							$out[] = goat_contact_from_user($cr, 'boss_call', $bc->call_name);
						}
					}
				}
			}

			if (count($out) > 0)
			{
				$cache[$ck] = $out;
				return $out;
			}
		}

		/* ---- RUNG 3 — on-site contact ----
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
