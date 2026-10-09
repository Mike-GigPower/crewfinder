<?php

	/*
	/* CREW LIST SENT — the one place a call's crew-list state is worked out
	/* (DESIGN-crewlist-sent-v0_2 §4.2: "The comparison is done in PHP in one
	/* place, so all three screens get the same answer").
	/*
	/* Two endpoints need the answer: get-crewlist-status.php (every screen)
	/* and undo-crewlist-sent.php (which reports the call's state AFTER the
	/* undo, because an older send may now be current). A copy in each would
	/* only have to drift once for the Schedule and the booking dialog to
	/* disagree about the same call, so both include this file.
	/*
	/* STATE, per call:
	/*   none     no live row (newest row with undone_at IS NULL). Callers OMIT
	/*            the call rather than emit 'none', so responses stay small.
	/*   sent     the newest live snapshot equals the live call: the confirmed
	/*            crew set (call_crew_map.status 5 ONLY — D3), start_date,
	/*            start_time to the minute, est_length to 2 dp.
	/*   changed  anything above differs. diff says what.
	/*
	/* CANCELLED CALLS ARE LEFT OUT entirely (D17, design §4.2) — not in the
	/* per-call map and not in a booking's calls_total. Same predicate as
	/* get-calls-bulk.php (cancelled_at IS NULL), capability-guarded the same
	/* way so an environment without MIGRATION-call-cancellation.sql degrades
	/* to "nothing is cancelled" rather than a failed query. The check runs
	/* through PDO here, not goat_calls_have_cancelled(), because that helper
	/* is mysql_* and this family adds no mysql_* calls.
	/*
	/* Every query takes ids as BOUND parameters (positional, PARAM_INT). The
	/* caller passes a PDO from goat_pdo() and catches PDOException.
	/*
	/* PHP 5.x — array(), no ??, no short arrays, tabs. Integers cast on the way
	/* out: the PDO driver returns strings (goat-db.php header).
	*/

	if (!function_exists('goat_crewlist_have_cancelled'))
	{
		function goat_crewlist_have_cancelled($pdo)
		{
			static $has = null;

			if ($has === null)
			{
				$r   = $pdo->query("SHOW COLUMNS FROM calls LIKE 'cancelled_at'");
				$has = ($r !== false && $r->fetch() !== false);
				if ($r !== false)
					$r->closeCursor();
			}

			return $has;
		}

		/*
		/* "?,?,?" for n ids. Callers guarantee n > 0. */
		function goat_crewlist_placeholders($n)
		{
			return implode(',', array_fill(0, (int) $n, '?'));
		}

		/*
		/* Bind a list of ints to positional placeholders starting at $pos
		/* (1-based). Returns the next free position. */
		function goat_crewlist_bind_ints($stmt, $ids, $pos)
		{
			foreach ($ids as $id)
			{
				$stmt->bindValue($pos, (int) $id, PDO::PARAM_INT);
				$pos++;
			}
			return $pos;
		}

		/*
		/* CSV of userIDs -> sorted unique ints. Empty string -> empty array. */
		function goat_crewlist_parse_ids($csv)
		{
			$out = array();
			foreach (explode(',', (string) $csv) as $p)
			{
				$p = trim($p);
				if ($p !== '' && preg_match('/^[0-9]+$/', $p) && (int) $p > 0)
					$out[(int) $p] = true;
			}
			$out = array_keys($out);
			sort($out, SORT_NUMERIC);
			return $out;
		}

		/*
		/* 'lastname, firstname', entity-decoded like get-booking.php. */
		function goat_crewlist_name($first, $last)
		{
			$first = trim(html_entity_decode((string) $first, ENT_QUOTES, 'UTF-8'));
			$last  = trim(html_entity_decode((string) $last,  ENT_QUOTES, 'UTF-8'));

			if ($last !== '' && $first !== '')
				return $last . ', ' . $first;
			return $last . $first;
		}

		/* 'HH:MM' from a TIME value or a stored 'HH:MM:SS' — "to the minute". */
		function goat_crewlist_hhmm($t)
		{
			$t = trim((string) $t);
			if (preg_match('/^(\d{1,2}):(\d{2})/', $t, $m))
				return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
			return $t;
		}

		/* est_length to 2 dp, as a comparable string. */
		function goat_crewlist_len2($v)
		{
			return sprintf('%.2f', round((float) $v, 2));
		}

		/*
		/* All non-cancelled calls of the given bookings:
		/*   callID => array(booking_id, start_date, start_time, est_length)
		*/
		function goat_crewlist_calls_of_bookings($pdo, $bookingIDs)
		{
			$out = array();
			if (!count($bookingIDs))
				return $out;

			$cancel = goat_crewlist_have_cancelled($pdo) ? 'AND cancelled_at IS NULL' : '';

			$stmt = $pdo->prepare(
				"SELECT id, bookingID, start_date, start_time, est_length
				 FROM calls
				 WHERE bookingID IN (" . goat_crewlist_placeholders(count($bookingIDs)) . ")
				 " . $cancel);
			goat_crewlist_bind_ints($stmt, $bookingIDs, 1);
			$stmt->execute();

			while ($r = $stmt->fetch(PDO::FETCH_ASSOC))
			{
				$out[(int) $r['id']] = array(
					'booking_id' => (int) $r['bookingID'],
					'start_date' => (int) $r['start_date'],
					'start_time' => (string) $r['start_time'],
					'est_length' => $r['est_length']
				);
			}
			$stmt->closeCursor();

			return $out;
		}

		/*
		/* THE COMPARISON. $calls is the map goat_crewlist_calls_of_bookings()
		/* returns (live values, cancelled already removed). Returns
		/*   callID => array(booking_id, state, sent_at, sent_by, sent_by_name, diff)
		/* for every call in $calls that has a live row. Calls with none are
		/* absent: absence is 'none'.
		*/
		function goat_crewlist_states($pdo, $calls)
		{
			$result = array();
			$callIDs = array_keys($calls);
			if (!count($callIDs))
				return $result;

			/* 1. the newest live row per call. MAX(id) is "newest": ids are
			/*    assigned in insert order and a row is never re-dated. */
			$stmt = $pdo->prepare(
				"SELECT s.callID, s.crew_ids, s.start_date, s.start_time, s.est_length,
				        s.sent_by, s.sent_at,
				        u.firstname AS sb_first, u.lastname AS sb_last
				 FROM call_crewlist_sent s
				 INNER JOIN (SELECT callID, MAX(id) AS mid
				             FROM call_crewlist_sent
				             WHERE undone_at IS NULL
				               AND callID IN (" . goat_crewlist_placeholders(count($callIDs)) . ")
				             GROUP BY callID) m ON m.mid = s.id
				 LEFT JOIN users u ON u.id = s.sent_by");
			goat_crewlist_bind_ints($stmt, $callIDs, 1);
			$stmt->execute();

			$snap = array();
			while ($r = $stmt->fetch(PDO::FETCH_ASSOC))
				$snap[(int) $r['callID']] = $r;
			$stmt->closeCursor();

			if (!count($snap))
				return $result;

			/* 2. live confirmed crew (status 5 — D3) for the marked calls only */
			$marked = array_keys($snap);
			$live   = array();
			foreach ($marked as $cid)
				$live[$cid] = array();

			$stmt = $pdo->prepare(
				"SELECT callID, userID
				 FROM call_crew_map
				 WHERE status = 5
				   AND callID IN (" . goat_crewlist_placeholders(count($marked)) . ")");
			goat_crewlist_bind_ints($stmt, $marked, 1);
			$stmt->execute();

			while ($r = $stmt->fetch(PDO::FETCH_ASSOC))
			{
				$uid = (int) $r['userID'];
				if ($uid > 0)
					$live[(int) $r['callID']][$uid] = true;
			}
			$stmt->closeCursor();

			/* 3. compare; collect the user ids a diff needs a name for */
			$pending = array();
			$needName = array();

			foreach ($snap as $cid => $s)
			{
				$c = $calls[$cid];

				$was = goat_crewlist_parse_ids($s['crew_ids']);
				$now = array_keys($live[$cid]);
				sort($now, SORT_NUMERIC);

				$added   = array_values(array_diff($now, $was));
				$removed = array_values(array_diff($was, $now));

				foreach ($added as $u)   $needName[$u] = true;
				foreach ($removed as $u) $needName[$u] = true;

				$dDate = ((int) $s['start_date'] !== (int) $c['start_date'])
				       ? array('was' => (int) $s['start_date'], 'now' => (int) $c['start_date'])
				       : null;

				$dTime = (goat_crewlist_hhmm($s['start_time']) !== goat_crewlist_hhmm($c['start_time']))
				       ? array('was' => (string) $s['start_time'], 'now' => (string) $c['start_time'])
				       : null;

				$dLen  = (goat_crewlist_len2($s['est_length']) !== goat_crewlist_len2($c['est_length']))
				       ? array('was' => (float) goat_crewlist_len2($s['est_length']),
				               'now' => (float) goat_crewlist_len2($c['est_length']))
				       : null;

				$pending[$cid] = array(
					's'       => $s,
					'added'   => $added,
					'removed' => $removed,
					'date'    => $dDate,
					'time'    => $dTime,
					'len'     => $dLen
				);
			}

			/* 4. names for added / removed, one query */
			$names = array();
			$ids   = array_keys($needName);

			if (count($ids))
			{
				$stmt = $pdo->prepare(
					"SELECT id, firstname, lastname
					 FROM users
					 WHERE id IN (" . goat_crewlist_placeholders(count($ids)) . ")");
				goat_crewlist_bind_ints($stmt, $ids, 1);
				$stmt->execute();

				while ($r = $stmt->fetch(PDO::FETCH_ASSOC))
					$names[(int) $r['id']] = goat_crewlist_name($r['firstname'], $r['lastname']);
				$stmt->closeCursor();
			}

			foreach ($pending as $cid => $p)
			{
				$s = $p['s'];

				$added = array();
				foreach ($p['added'] as $u)
					$added[] = array('id' => (int) $u, 'name' => isset($names[$u]) ? $names[$u] : '');

				$removed = array();
				foreach ($p['removed'] as $u)
					$removed[] = array('id' => (int) $u, 'name' => isset($names[$u]) ? $names[$u] : '');

				usort($added,   'goat_crewlist_cmp_name');
				usort($removed, 'goat_crewlist_cmp_name');

				$same = (!count($added) && !count($removed)
				         && $p['date'] === null && $p['time'] === null && $p['len'] === null);

				$result[$cid] = array(
					'booking_id'   => (int) $calls[$cid]['booking_id'],
					'state'        => $same ? 'sent' : 'changed',
					'sent_at'      => (int) $s['sent_at'],
					'sent_by'      => (int) $s['sent_by'],
					'sent_by_name' => goat_crewlist_name($s['sb_first'], $s['sb_last']),
					'diff'         => $same ? null : array(
						'added'      => $added,
						'removed'    => $removed,
						'start_date' => $p['date'],
						'start_time' => $p['time'],
						'est_length' => $p['len']
					)
				);
			}

			return $result;
		}

		function goat_crewlist_cmp_name($a, $b)
		{
			$c = strcasecmp($a['name'], $b['name']);
			return $c !== 0 ? $c : ($a['id'] - $b['id']);
		}

		/*
		/* Per-booking aggregates over EVERY non-cancelled call of the booking
		/* (D5 is about the whole booking; D11: finished calls count). Every
		/* id in $bookingIDs gets an entry, including a booking with no calls
		/* (calls_total 0, all_sent false), so a caller can tell "asked about
		/* and empty" from "not asked about".
		*/
		function goat_crewlist_booking_counts($bookingIDs, $calls, $states)
		{
			$out = array();

			foreach ($bookingIDs as $bid)
				$out[(int) $bid] = array('calls_total' => 0, 'calls_sent' => 0, 'all_sent' => false);

			foreach ($calls as $cid => $c)
			{
				$bid = (int) $c['booking_id'];
				if (!isset($out[$bid]))
					continue;

				$out[$bid]['calls_total']++;
				if (isset($states[$cid]) && $states[$cid]['state'] === 'sent')
					$out[$bid]['calls_sent']++;
			}

			foreach ($out as $bid => $b)
				$out[$bid]['all_sent'] = ($b['calls_total'] > 0 && $b['calls_sent'] === $b['calls_total']);

			return $out;
		}
	}

?>
