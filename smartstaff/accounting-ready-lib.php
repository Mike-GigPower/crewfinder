<?php

	/*
	/* READY TO INVOICE -- the one place a booking's invoiceable state is
	/* worked out (BRIEF-accounting-slice-b.md §3.1, §3.2 step 2).
	/*
	/* Two endpoints need the answer and they MUST agree exactly:
	/*   list-ready-to-invoice.php  lists the bookings and their fingerprints
	/*   generate-invoice.php       re-reads the SAME state at the moment of
	/*                              the write, and refuses `changed` when the
	/*                              fingerprint it recomputes differs
	/* A copy in each would only have to drift once for Generate to refuse
	/* every booking the list offered, or -- worse -- to pass a money check it
	/* should have failed. So both include this file. (Same reasoning, and the
	/* same shape, as crewlist-sent-lib.php.)
	/*
	/* NO global.php HERE. The endpoints include it; this file is pure
	/* functions over a mysqli link they hand in, and it NEVER writes.
	/*
	/* EVERY CONDITION BELOW IS COPIED FROM generateAccountingData() in
	/* classes/class.accounting.php (l. 352-626) and the line is named, because
	/* this file's whole job is to predict what that function will do:
	/*
	/*   call_list  (l. 366)  calls WHERE bookingID = X AND call_locked = 1
	/*                        AND invoiceID = 0        -- NOT invoice_generated
	/*   the lines  (l. 406)  accounting rows WHERE calls.invoice_generated = 0
	/*                        AND calls.call_locked = 1 AND the crew status
	/*                        condition below -- and NOT filtered on invoiceID
	/*   qty        (l. 408)  COUNT(*) of the rows grouped by callID, on, off,
	/*                        CONCAT(on, off, break, break_night), day_hours,
	/*                        day_chargeout
	/*   stamping   (l. 604)  invoice_generated = 1 for every DISTINCT callID in
	/*                        accounting on the booking whose call is locked and
	/*                        invoice_generated = 0 -- with NO crew status
	/*                        condition, so a call whose crew are all
	/*                        unconfirmed is stamped too, having made no lines
	/*
	/* THE CREW STATUS CONDITION, copied verbatim from l. 406 and l. 440:
	/*   (status = 5 OR (status = 9 AND prev_status = 5
	/*                   AND (cancel_pay = 1 OR cancel_charge = 1)))
	/* i.e. confirmed crew, and cancellations that are still paid or charged.
	/*
	/* THE TWO STATES THAT MATTER (§3.1):
	/*   ready  invoiceID = 0, invoice_generated = 0, has pay rows
	/*   stuck  invoiceID > 0, invoice_generated = 0, has pay rows
	/*
	/* A STUCK CALL BLOCKS ITS WHOLE BOOKING. The lines query at l. 406 does
	/* not filter on invoiceID, so a call already stamped onto invoice #34012
	/* but never marked invoiced would have its lines written onto the NEW
	/* invoice, where it is not even in the call_list. That is a wrong invoice,
	/* so the booking is refused rather than warned about (design §11, "gates,
	/* not warnings").
	/*
	/* ---- WHY THE EXPECTED AMOUNT CAN BE WRONG, AND HOW THAT IS SAID ----
	/*
	/* expected_charge is a PREDICTION of what the class will write. Two things
	/* can make it wrong, and both are reported rather than hidden:
	/*
	/*   mixed_groups  The class groups crew by (on, off, CONCAT(on, off,
	/*                 break, break_night), day_hours, day_chargeout) and then
	/*                 prices ONE ROW of the group, multiplying by the group
	/*                 size. `calculated` is not in that key, so two crew who
	/*                 share a day rate and times but differ on, say, the night
	/*                 rate group together and one of them is silently dropped.
	/*                 MariaDB picks which one. This file cannot know, so when a
	/*                 group's rows do not all price the same it says so.
	/*   null_calc     accounting.calculated IS NULL takes the class down its
	/*                 LEGACY branch (l. 302-598), which re-prices day and night
	/*                 hours in PHP and substitutes Sunday and public-holiday
	/*                 rates for paygrades 10, 25 and 26. That is not
	/*                 reproducible in SQL, so those rows are counted, not
	/*                 priced.
	/*
	/* Either one sets expected_exact = false on the booking, and
	/* generate-invoice.php then SKIPS its money check for that booking rather
	/* than failing a Generate that did exactly what SmartStaff always does.
	/*
	/* MONEY IS RETURNED UNROUNDED, AS DECIMAL STRINGS, exactly as Slice A's
	/* list-invoices.php: every multiplication is CAST to DECIMAL first so that
	/* single-precision FLOAT noise cannot accumulate (fix 1, invoice #34334),
	/* and the sums are NOT cast to float in PHP on the way out -- mysqli hands
	/* a DECIMAL back as a string, and (float) would put it into a double where
	/* json_encode could print 32783.950000000004. All rounding happens once,
	/* in Python, in Decimal.
	/*
	/* PHP 5.6 -- array(), no null-coalescing, no short array syntax. */

	if (!defined('GOAT_READY_WINDOW_MONTHS'))
	{
		define('GOAT_READY_WINDOW_MONTHS', 12);
		define('GOAT_READY_MAX_BOOKINGS',  300);   /* ?all=1 only -- the 2010-11 block is 1,672 calls (P3d) */
		define('GOAT_READY_ID_CHUNK',      1000);
	}

	if (!function_exists('goat_ready_connect'))
	{

		/* Same credential resolution as get-booking-figures.php. */
		function goat_ready_connect($tag)
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
				error_log($tag . ': could not resolve DB credentials');
				return null;
			}

			mysqli_report(MYSQLI_REPORT_OFF);
			$link = @mysqli_connect($host, $user, $pass, $name);

			if (!$link)
			{
				error_log($tag . ': connect failed: ' . mysqli_connect_error());
				return null;
			}

			/*
			/* UTF-8 on the wire: these endpoints json_encode() booking and
			/* customer names, and json_encode REFUSES invalid UTF-8, so one
			/* latin1 apostrophe would turn a whole list into a 500.
			*/
			if (function_exists('mysqli_set_charset'))
			{
				if (!@mysqli_set_charset($link, 'utf8mb4'))
					@mysqli_set_charset($link, 'utf8');
			}

			return $link;
		}

		/*
		/* Returns the result, or FALSE having logged. The CALLER decides how to
		/* fail, because generate-invoice.php has a named lock to release first
		/* and list-ready-to-invoice.php has not.
		*/
		function goat_ready_query($link, $tag, $label, $sql)
		{
			$res = mysqli_query($link, $sql);

			if ($res === false)
				error_log($tag . ': query "' . $label . '" failed: ' . mysqli_error($link));

			return $res;
		}

		function goat_ready_has_column($link, $table, $column)
		{
			$sql = "SHOW COLUMNS FROM `" . $table . "` LIKE '"
			     . mysqli_real_escape_string($link, $column) . "'";

			$r = mysqli_query($link, $sql);

			return ($r !== false && mysqli_num_rows($r) > 0);
		}

		/* A list of ids, already integers, safe to drop into an IN (...). */
		function goat_ready_ids($ids)
		{
			$out = array();

			foreach ($ids as $id)
				$out[] = (int) $id;

			return join(',', $out);
		}

		/*
		/* The fingerprint: sha1 of the READY call ids, sorted ascending and
		/* joined with commas. Sorted, so the order the rows arrived in cannot
		/* change it; the ready ids only, because those are what Generate turns
		/* into lines. Both endpoints call THIS function, so the two can only
		/* disagree if the ready set itself has changed.
		*/
		function goat_ready_fingerprint($readyIDs)
		{
			$ids = array();

			foreach ($readyIDs as $id)
				$ids[] = (int) $id;

			sort($ids, SORT_NUMERIC);

			return sha1(join(',', $ids));
		}

		/*
		/* 1 visible, 0 hidden, -1 missing. Needed on its own because the
		/* candidate scan joins bookings ON hidden = 0, which makes a hidden
		/* booking look identical to one with no locked calls.
		*/
		function goat_ready_booking_state($link, $tag, $bookingID)
		{
			$res = goat_ready_query($link, $tag, 'booking state',
				"SELECT hidden FROM `bookings` WHERE id = " . (int) $bookingID . " LIMIT 1");

			if ($res === false)
				return null;

			$row = mysqli_fetch_assoc($res);
			mysqli_free_result($res);

			if ($row === null)
				return -1;

			return ((int) $row['hidden'] === 0) ? 1 : 0;
		}

		/* The most recent invoice on a booking, or 0. For `nothing_to_invoice`. */
		function goat_ready_latest_invoice($link, $tag, $bookingID)
		{
			$res = goat_ready_query($link, $tag, 'latest invoice',
				"SELECT MAX(id) AS id FROM `invoices` WHERE bookingID = " . (int) $bookingID);

			if ($res === false)
				return 0;

			$row = mysqli_fetch_assoc($res);
			mysqli_free_result($res);

			return ($row === null || $row['id'] === null) ? 0 : (int) $row['id'];
		}

		/*
		/* THE SCAN. $opts:
		/*   'all'        true drops the 12-month window (list only)
		/*   'booking_id' when set, only that booking, and the window is
		/*                IGNORED -- Generate must see a booking's true state
		/*                however old its calls are
		/*   'cap'        true applies GOAT_READY_MAX_BOOKINGS
		/*
		/* Returns array('ok' => true, 'bookings' => array(bid => booking),
		/* 'order' => array(bid, ...), 'truncated' => bool, 'total_found' => n)
		/* or array('ok' => false, 'query' => label).
		*/
		function goat_ready_scan($link, $tag, $opts)
		{
			$all       = isset($opts['all']) ? (bool) $opts['all'] : false;
			$bookingID = isset($opts['booking_id']) ? (int) $opts['booking_id'] : 0;
			$cap       = isset($opts['cap']) ? (bool) $opts['cap'] : false;

			/*
			/* calls.cancelled_at arrived with a migration and is not on every
			/* box, so it is capability-checked rather than assumed (the
			/* goat_calls_have_cancelled() pattern in get-booking.php l. 113).
			*/
			$notCancelled = goat_ready_has_column($link, 'calls', 'cancelled_at')
				? "(c.cancelled_at IS NULL OR c.cancelled_at = 0)"
				: "1";

			/* ---- 1. candidate calls ---- */

			if ($bookingID > 0)
				$scope = " AND c.bookingID = " . $bookingID;
			elseif (!$all)
				$scope = " AND c.start_date >= UNIX_TIMESTAMP(NOW() - INTERVAL "
				       . (int) GOAT_READY_WINDOW_MONTHS . " MONTH)";
			else
				$scope = "";

			$res = goat_ready_query($link, $tag, 'candidate calls',
				"SELECT c.id, c.bookingID, c.call_name, c.start_date,
				        c.invoiceID, c.invoice_generated
				   FROM `calls` c
				   JOIN `bookings` b ON b.id = c.bookingID
				  WHERE c.call_locked = 1
				    AND c.invoice_generated = 0
				    AND b.hidden = 0" . $scope . "
				  ORDER BY c.start_date, c.id");

			if ($res === false)
				return array('ok' => false, 'query' => 'candidate calls');

			$candidates = array();
			$callIDs    = array();

			while ($row = mysqli_fetch_assoc($res))
			{
				$id = (int) $row['id'];

				$candidates[$id] = array(
					'booking_id' => (int) $row['bookingID'],
					'call_name'  => $row['call_name'],
					'start_date' => (int) $row['start_date'],
					'invoice_id' => (int) $row['invoiceID']
				);

				$callIDs[] = $id;
			}

			mysqli_free_result($res);

			/* ---- 2. the pay rows for those calls only ---- */

			$pay = array();

			foreach (array_chunk($callIDs, GOAT_READY_ID_CHUNK) as $chunk)
			{
				$ids = goat_ready_ids($chunk);

				/*
				/* Three levels, innermost first:
				/*
				/*   p   one row per accounting row, priced from its
				/*       `calculated` buckets. timeTotal and chargeout are CAST
				/*       TO DECIMAL BEFORE MULTIPLYING (fix 1) so the product is
				/*       exact decimal arithmetic, not two doubles. Zero-time
				/*       buckets are skipped exactly as l. 464 does.
				/*   r   one row per accounting row, with its group key and
				/*       whether its `calculated` was NULL.
				/*   g   one row per SmartStaff GROUP -- the key copied from
				/*       l. 408 -- with the group size, and MIN and MAX of the
				/*       per-row price so a group whose rows disagree is seen.
				/*
				/* The outer SELECT then sums the groups per call the way the
				/* class prices them: group size times ONE row's price.
				/*
				/* `accounting` is referenced TWICE -- once for the rows and
				/* once in the derived table that prices them -- which is the
				/* shape get-performance-cost.php l. 195-207 already uses in
				/* production. One reference is not available without dropping
				/* the NULL-calculated rows, which are exactly the rows that
				/* have to be counted. Both are restricted to the same id list.
				*/
				$res = goat_ready_query($link, $tag, 'pay rows',
					"SELECT g.callID,
					        SUM(g.n)                      AS crew_rows,
					        SUM(g.n * g.vmin)             AS expected_charge,
					        SUM(g.n * g.hmin)             AS hours,
					        SUM(NOT (g.vmin <=> g.vmax))  AS mixed_groups,
					        SUM(g.nulls)                  AS null_calc
					   FROM (
					        SELECT r.callID,
					               COUNT(*)          AS n,
					               MIN(r.chg)        AS vmin,
					               MAX(r.chg)        AS vmax,
					               MIN(r.hrs)        AS hmin,
					               SUM(r.null_calc)  AS nulls
					          FROM (
					               SELECT a.callID,
					                      a.id,
					                      m.`on`        AS m_on,
					                      m.`off`       AS m_off,
					                      m.`break`     AS m_break,
					                      m.break_night AS m_break_night,
					                      a.day_hours,
					                      a.day_chargeout,
					                      (a.calculated IS NULL) AS null_calc,
					                      p.chg,
					                      p.hrs
					                 FROM `accounting` a
					                 LEFT JOIN `call_crew_map` m ON m.crewmapID = a.callcrewmapID
					                 LEFT JOIN (
					                      SELECT a2.id,
					                             SUM(CAST(jt.t AS DECIMAL(14,6))
					                                 * CAST(jt.co AS DECIMAL(12,4))) AS chg,
					                             SUM(CAST(jt.t AS DECIMAL(14,6)))    AS hrs
					                        FROM `accounting` a2,
					                             JSON_TABLE(a2.calculated, '$[*]' COLUMNS(
					                               co DOUBLE PATH '$.chargeout',
					                               t  DOUBLE PATH '$.timeTotal')) jt
					                       WHERE a2.callID IN (" . $ids . ")
					                         AND jt.t <> 0
					                       GROUP BY a2.id) p ON p.id = a.id
					                WHERE a.callID IN (" . $ids . ")
					                  AND (m.status = 5
					                       OR (m.status = 9 AND m.prev_status = 5
					                           AND (m.cancel_pay = 1 OR m.cancel_charge = 1)))
					               ) r
					         GROUP BY r.callID, r.m_on, r.m_off,
					                  CONCAT(r.m_on, r.m_off, r.m_break, r.m_break_night),
					                  r.day_hours, r.day_chargeout
					        ) g
					  GROUP BY g.callID");

				if ($res === false)
					return array('ok' => false, 'query' => 'pay rows');

				while ($row = mysqli_fetch_assoc($res))
				{
					$pay[(int) $row['callID']] = array(
						'crew_rows'       => (int) $row['crew_rows'],
						/*
						/* NOT CAST TO FLOAT. mysqli returns a DECIMAL as a
						/* STRING and it is handed on as one; (float) would put
						/* it back into a double and json_encode could then
						/* print 32783.950000000004.
						*/
						'expected_charge' => $row['expected_charge'] === null ? '0' : (string) $row['expected_charge'],
						'hours'           => $row['hours']           === null ? '0' : (string) $row['hours'],
						'mixed_groups'    => (int) $row['mixed_groups'],
						'null_calc'       => (int) $row['null_calc']
					);
				}

				mysqli_free_result($res);
			}

			/* ---- classify ---- */

			$bookings = array();

			foreach ($candidates as $id => $call)
			{
				$bid = $call['booking_id'];
				$p   = isset($pay[$id]) ? $pay[$id] : null;

				if (!isset($bookings[$bid]))
				{
					$bookings[$bid] = array(
						'booking_id'         => $bid,
						'ready_calls'        => array(),
						'stuck_calls'        => array(),
						'latest_call'        => 0,
						'mixed_groups'       => 0,
						'null_calc'          => 0,
						'no_crew_candidates' => array()
					);
				}

				if ($call['start_date'] > $bookings[$bid]['latest_call'])
					$bookings[$bid]['latest_call'] = $call['start_date'];

				if ($p === null || $p['crew_rows'] < 1)
				{
					/*
					/* Locked, not invoiced, but nothing to charge for. It is
					/* not work; it still joins the new call_list (l. 366) when
					/* invoiceID = 0, so it is counted for the UI note in §5.2.
					*/
					if ($call['invoice_id'] === 0)
						$bookings[$bid]['no_crew_candidates'][] = $id;

					continue;
				}

				if ($call['invoice_id'] > 0)
				{
					$bookings[$bid]['stuck_calls'][] = array(
						'call_id'    => $id,
						'invoice_id' => $call['invoice_id'],
						'call_name'  => $call['call_name'],
						'start_date' => $call['start_date']
					);

					continue;
				}

				$bookings[$bid]['ready_calls'][] = array(
					'call_id'         => $id,
					'call_name'       => $call['call_name'],
					'start_date'      => $call['start_date'],
					'crew_rows'       => $p['crew_rows'],
					'hours'           => $p['hours'],
					'expected_charge' => $p['expected_charge']
				);

				$bookings[$bid]['mixed_groups'] += $p['mixed_groups'];
				$bookings[$bid]['null_calc']    += $p['null_calc'];
			}

			/* A booking is listed only if it has a ready or a stuck call. */
			foreach (array_keys($bookings) as $bid)
			{
				if (count($bookings[$bid]['ready_calls']) < 1
				 && count($bookings[$bid]['stuck_calls']) < 1)
				{
					unset($bookings[$bid]);
				}
			}

			/*
			/* Newest work first, and the cap applied here so ?all=1 returns
			/* the 300 bookings most likely to be wanted, not the oldest 300.
			*/
			$order = array();

			foreach ($bookings as $bid => $b)
				$order[$bid] = $b['latest_call'];

			arsort($order);

			$ids        = array_keys($order);
			$totalFound = count($ids);
			$truncated  = false;

			if ($cap && $totalFound > GOAT_READY_MAX_BOOKINGS)
			{
				$truncated = true;
				$ids       = array_slice($ids, 0, GOAT_READY_MAX_BOOKINGS);
			}

			/* ---- 3. every call on those bookings, for the §5.2 counts ---- */

			$otherCalls = array();

			foreach ($ids as $bid)
				$otherCalls[$bid] = array('unlocked' => 0, 'locked_no_crew' => 0);

			foreach (array_chunk($ids, GOAT_READY_ID_CHUNK) as $chunk)
			{
				$res = goat_ready_query($link, $tag, 'booking calls',
					"SELECT c.id, c.bookingID, c.call_locked, c.invoiceID,
					        c.invoice_generated
					   FROM `calls` c
					  WHERE c.bookingID IN (" . goat_ready_ids($chunk) . ")
					    AND " . $notCancelled);

				if ($res === false)
					return array('ok' => false, 'query' => 'booking calls');

				while ($row = mysqli_fetch_assoc($res))
				{
					$bid = (int) $row['bookingID'];

					if (!isset($otherCalls[$bid]))
						continue;

					$cid = (int) $row['id'];

					if ((int) $row['call_locked'] !== 1)
					{
						$otherCalls[$bid]['unlocked']++;
						continue;
					}

					/*
					/* A locked call with invoiceID = 0 joins the new call_list
					/* (l. 366) whatever else is true of it. It produces no
					/* lines when it has no pay rows, or when it is already
					/* invoice_generated = 1 (l. 406 excludes it). Either way it
					/* is on the invoice as a call and contributes nothing,
					/* which is what §5.2's note tells Mike.
					*/
					if ((int) $row['invoiceID'] !== 0)
						continue;

					$noCrew = $bookings[$bid]['no_crew_candidates'];

					if ((int) $row['invoice_generated'] === 1
					 || in_array($cid, $noCrew, true))
					{
						$otherCalls[$bid]['locked_no_crew']++;
					}
				}

				mysqli_free_result($res);
			}

			/* ---- 4. booking and customer names ---- */

			$names = array();

			foreach (array_chunk($ids, GOAT_READY_ID_CHUNK) as $chunk)
			{
				$res = goat_ready_query($link, $tag, 'booking names',
					"SELECT b.id, b.name, b.reference, b.customerID,
					        customers.customer_name
					   FROM `bookings` b
					   LEFT JOIN `customers` ON customers.id = b.customerID
					  WHERE b.id IN (" . goat_ready_ids($chunk) . ")");

				if ($res === false)
					return array('ok' => false, 'query' => 'booking names');

				while ($row = mysqli_fetch_assoc($res))
				{
					$names[(int) $row['id']] = array(
						'booking_name'      => $row['name'],
						'booking_reference' => $row['reference'],
						'customer_id'       => (int) $row['customerID'],
						'customer_name'     => $row['customer_name']
					);
				}

				mysqli_free_result($res);
			}

			/* ---- the per-booking answer ---- */

			$out = array();

			foreach ($ids as $bid)
			{
				$b = $bookings[$bid];

				$n = isset($names[$bid]) ? $names[$bid] : array(
					'booking_name' => null, 'booking_reference' => null,
					'customer_id'  => 0,    'customer_name'     => null);

				$o = $otherCalls[$bid];

				$readyIDs = array();

				foreach ($b['ready_calls'] as $rc)
					$readyIDs[] = (int) $rc['call_id'];

				$out[$bid] = array(
					'booking_id'           => $bid,
					'booking_name'         => $n['booking_name'],
					'booking_reference'    => $n['booking_reference'],
					'customer_id'          => $n['customer_id'],
					'customer_name'        => $n['customer_name'],
					'ready_calls'          => $b['ready_calls'],
					'stuck_calls'          => $b['stuck_calls'],
					'unlocked_calls'       => $o['unlocked'],
					'locked_no_crew_calls' => $o['locked_no_crew'],
					'blocked'              => (count($b['stuck_calls']) > 0),
					/*
					/* False when the prediction cannot be trusted to the cent
					/* -- see the mixed_groups and null_calc note above.
					*/
					'expected_exact'       => ($b['mixed_groups'] === 0 && $b['null_calc'] === 0),
					'mixed_groups'         => $b['mixed_groups'],
					'null_calc'            => $b['null_calc'],
					'fingerprint'          => goat_ready_fingerprint($readyIDs)
				);
			}

			return array(
				'ok'          => true,
				'bookings'    => $out,
				'order'       => $ids,
				'truncated'   => $truncated,
				'total_found' => $totalFound
			);
		}

	}
