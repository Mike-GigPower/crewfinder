<?php

	/*
	/* offer-clash.php — shared by respond-to-call.php and my-call-offers.php.
	/*
	/* "Would accepting these calls overlap a shift this crew member is already
	/* CONFIRMED (status 5) on?" One rule, two callers: the offer list uses it
	/* to lock the Accept button, respond-to-call.php uses it to refuse the
	/* write. They must never disagree, which is why it lives here.
	/*
	/* Times come from `calls` (start_date + start_time + est_length), NOT from
	/* `calendars`: calendars truncates fractional lengths (intval in
	/* addToCalendar) and keeps rows for declined shifts.
	/*
	/* Overlap is HALF-OPEN — back-to-back shifts do not clash (matches the
	/* 9 Sep addToCall amendment).
	/*
	/* mysql_* to match both including files. Adds to the PHP 8 backlog — convert
	/* with them.
	/*
	/* NAMING: every helper here is goat_clash_* / goat_*_clashes / goat_confirmed_*.
	/* v1 called its window helper goat_call_window, which resolve-call-contact.php
	/* ALSO declares (unguarded, returning array('start','end')). my-call-offers.php
	/* includes that file first, so the function_exists guard silently picked the
	/* other version, $w[0] was undefined and every offer read clashes_with:[]
	/* (found 19 Oct 2026). Do not reuse an existing goat_* name.
	*/

	if (!function_exists('goat_clash_window'))
	{
		/* wall-clock start/end in the same form my-call-offers.php emits */
		function goat_clash_window($row)
		{
			$dateStr = date('Y-m-d', (int) $row->start_date);
			$start   = strtotime($dateStr . ' ' . $row->start_time);
			$end     = $start + (int) round(((double) $row->est_length) * 3600);

			return array($start, $end);
		}
	}

	if (!function_exists('goat_confirmed_shifts'))
	{
		/*
		/* Every not-cancelled call this user is CONFIRMED on, from yesterday
		/* onward (yesterday so an overnight shift still running is caught).
		/* Returns false on a query failure — callers must not treat that as
		/* "no shifts".
		*/
		function goat_confirmed_shifts($userID)
		{
			$res = mysql_query("SELECT calls.id, calls.call_name,
			                           calls.start_date, calls.start_time, calls.est_length,
			                           bookings.name AS booking_name
			                    FROM call_crew_map
			                    INNER JOIN calls    ON calls.id    = call_crew_map.callID
			                    LEFT  JOIN bookings ON bookings.id = calls.bookingID
			                    WHERE call_crew_map.userID = " . (int) $userID . "
			                      AND call_crew_map.status = 5
			                      AND calls.cancelled_at IS NULL
			                      AND calls.start_date >= UNIX_TIMESTAMP(CURDATE()) - 86400");

			if ($res === false)
			{
				return false;
			}

			$out = array();

			while ($r = mysql_fetch_object($res))
			{
				$w = goat_clash_window($r);

				$out[(int) $r->id] = array(
					'call_id'      => (int) $r->id,
					'call_name'    => $r->call_name,
					'booking_name' => $r->booking_name,
					'startUnix'    => $w[0],
					'endUnix'      => $w[1]
				);
			}

			return $out;
		}
	}

	if (!function_exists('goat_offer_clashes'))
	{
		/*
		/* Confirmed shifts that overlap ANY call in $callIDs. Calls inside
		/* $callIDs are never compared with each other (a linked package is
		/* one unit) and a confirmed shift that is itself in $callIDs is
		/* skipped. Returns a list, each confirmed shift at most once.
		*/
		function goat_offer_clashes($callIDs, $confirmed)
		{
			$ids = array();

			foreach ($callIDs as $cid)
			{
				$ids[] = (int) $cid;
			}

			if (!count($ids) || !count($confirmed))
			{
				return array();
			}

			$res = mysql_query("SELECT id, start_date, start_time, est_length
			                    FROM calls
			                    WHERE id IN (" . implode(',', $ids) . ")");

			if ($res === false)
			{
				return array();
			}

			$hits = array();

			while ($c = mysql_fetch_object($res))
			{
				$w = goat_clash_window($c);

				foreach ($confirmed as $sid => $s)
				{
					if (in_array($sid, $ids))
					{
						continue;   /* same package — never a clash */
					}

					if ($w[0] < $s['endUnix'] && $s['startUnix'] < $w[1])
					{
						$hits[$sid] = $s;
					}
				}
			}

			return array_values($hits);
		}
	}

?>
