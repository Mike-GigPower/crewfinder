<?php

	/*
	/* GRADE LADDER — shared by preview-grade-change.php and
	/* apply-grade-change.php (BRIEF-promotion-paygrade-slice1 §2.1,
	/* DESIGN-promotion-paygrade-cascade v0.2).
	/*
	/* The standard-crew ladder, in tier order:
	/*
	/*   tier 1  10  T1  / T2    Sun/PH 38  Sun/Pub Hol
	/*   tier 2  26  T1B / T2B   Sun/PH 39  Sun/Pub HolB
	/*   tier 3  25  T1A / T2A   Sun/PH 40  Sun/Pub HolA
	/*
	/* ONLY THE THREE BASE IDS ARE WRITTEN HERE. The Sun/PH id of each comes from
	/* the base row's paygrades.ot_paygrade, the same link SmartStaff's own
	/* InvoiceHours::findRates() follows (DESIGN-call-types-v0_2 §6b). Confirmed
	/* on prod 17 Nov 2026: 10->38, 26->39, 25->40, and back via monday_paygrade.
	/*
	/* FAILS CLOSED: if any base row is missing, or its ot_paygrade is 0, or two
	/* bases share one, the map is refused and nothing is written. A wrong map
	/* here would move rows onto the wrong rate, which is worse than not moving
	/* them.
	/*
	/* Role grades (Fork, Steel Hand, Crew Boss, Show Rate, ...) are not in the
	/* map and are never touched (design D4/D11).
	/*
	/* PHP 5.6: array(), no ??, tabs. PDO handles come from goat_pdo().
	*/

	if (!function_exists('goat_grade_ladder'))
	{
		/* Base grade ids in tier order. A function, not a constant: define()
		/* cannot hold an array on PHP 5.6 (goat_probation_grade_ids precedent). */
		function goat_grade_ladder()
		{
			return array(10, 26, 25);
		}

		/* call_crew_map statuses a grade change applies to (design D7):
		/*   0 unconfirmed, 1 pending, 5 confirmed, 7 backup, 9 cancelled
		/*   (a paid late cancellation pays at the row's grade).
		/* Never 6 declined, 8 no-show, 10/11/12 withdrawn offers, 13 left
		/* standby -- none of them can produce delivered work to pay for. */
		function goat_grade_change_statuses()
		{
			return array(0, 1, 5, 7, 9);
		}

		/* ISO day of week the weekly pay period starts on. The pay week runs
		/* Tuesday to Tuesday and Joe processes it each Tuesday (Mike, 17 Nov
		/* 2026; design Q12). Slice 2 may replace the rule with EH's last pay
		/* run end date + 1. */
		if (!defined('GOAT_PAY_WEEK_START_DOW'))
			define('GOAT_PAY_WEEK_START_DOW', 2);

		function goat_melbourne_tz()
		{
			return new DateTimeZone('Australia/Melbourne');
		}

		/* Today in Melbourne as Y-m-d. */
		function goat_today_iso()
		{
			$d = new DateTime('now', goat_melbourne_tz());
			return $d->format('Y-m-d');
		}

		/* The first day of the current pay period: the most recent
		/* GOAT_PAY_WEEK_START_DOW on or before $today_iso (today itself when it
		/* is that day). Y-m-d. */
		function goat_pay_period_start($today_iso)
		{
			$d    = DateTime::createFromFormat('!Y-m-d', $today_iso, goat_melbourne_tz());
			$dow  = (int) $d->format('N');                         /* 1 Mon .. 7 Sun */
			$back = ($dow - GOAT_PAY_WEEK_START_DOW + 7) % 7;
			if ($back > 0)
				$d->modify('-' . $back . ' days');
			return $d->format('Y-m-d');
		}

		/* Unix timestamp of 00:00 Melbourne on $iso (Y-m-d). calls.start_date
		/* is a unix timestamp. */
		function goat_day_start_ts($iso)
		{
			$d = DateTime::createFromFormat('!Y-m-d', $iso, goat_melbourne_tz());
			return (int) $d->getTimestamp();
		}

		/* A strict Y-m-d, or false. */
		function goat_valid_iso_date($s)
		{
			if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s))
				return false;
			$d = DateTime::createFromFormat('!Y-m-d', $s, goat_melbourne_tz());
			return ($d && $d->format('Y-m-d') === $s);
		}

		/*
		/* The ladder as data, read from paygrades:
		/*   array('ok' => true, 'map' => array(grade_id => array(
		/*       'tier' => 1..3, 'kind' => 'base'|'sph', 'base' => base_id,
		/*       'day_desc', 'night_desc', 'rate', 'night_rate', 'co_rate',
		/*       'night_co_rate')))
		/* or array('ok' => false, 'error' => '...').
		/*
		/* The four rate values are kept AS THE STRINGS PDO RETURNED. They are
		/* written back as bound strings, never as SET col = pg.col in SQL: that
		/* copies FLOAT bits (32.33000183105469). See
		/* REMEDIATION-new-crew-paygrade-2026-09-09.sql step 3.
		*/
		function goat_grade_ladder_map($pdo)
		{
			/* CONCAT(col, '') makes the SERVER format each FLOAT as text — the
			/* exact string the legacy mysql_* text protocol hands
			/* update-call-times.php ("32.33"). Fetching the raw FLOAT over a
			/* native prepared statement can instead yield its binary expansion
			/* (32.33000183105469), the float dust this must not write. */
			$cols  = "id, day_desc, night_desc, CONCAT(rate, '') AS rate, CONCAT(night_rate, '') AS night_rate,
			          CONCAT(co_rate, '') AS co_rate, CONCAT(night_co_rate, '') AS night_co_rate, ot_paygrade";
			$bases = goat_grade_ladder();

			$st = $pdo->prepare('SELECT ' . $cols . ' FROM paygrades WHERE id IN (?, ?, ?)');
			$st->execute($bases);
			$byId = array();
			foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
				$byId[(int) $r['id']] = $r;

			$sphIds = array();
			foreach ($bases as $b)
			{
				if (!isset($byId[$b]))
					return array('ok' => false, 'error' => 'ladder misconfigured: paygrade ' . $b . ' missing');
				$ot = (int) $byId[$b]['ot_paygrade'];
				if ($ot <= 0)
					return array('ok' => false, 'error' => 'ladder misconfigured: paygrade ' . $b . ' has no ot_paygrade');
				if (in_array($ot, $sphIds, true) || in_array($ot, $bases, true))
					return array('ok' => false, 'error' => 'ladder misconfigured: ot_paygrade ' . $ot . ' is not unique');
				$sphIds[] = $ot;
			}

			$st = $pdo->prepare('SELECT ' . $cols . ' FROM paygrades WHERE id IN (?, ?, ?)');
			$st->execute($sphIds);
			foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
				$byId[(int) $r['id']] = $r;

			$map = array();
			foreach ($bases as $i => $b)
			{
				$s = $sphIds[$i];
				if (!isset($byId[$s]))
					return array('ok' => false, 'error' => 'ladder misconfigured: paygrade ' . $s . ' missing');

				foreach (array(array($b, 'base'), array($s, 'sph')) as $pair)
				{
					$r = $byId[$pair[0]];
					$map[$pair[0]] = array(
						'tier'          => $i + 1,
						'kind'          => $pair[1],
						'base'          => $b,
						'day_desc'      => (string) $r['day_desc'],
						'night_desc'    => (string) $r['night_desc'],
						'rate'          => (string) $r['rate'],
						'night_rate'    => (string) $r['night_rate'],
						'co_rate'       => (string) $r['co_rate'],
						'night_co_rate' => (string) $r['night_co_rate']
					);
				}
			}

			return array('ok' => true, 'map' => $map);
		}

		/* The grade a ladder row moves to when the person's base grade becomes
		/* $newBase: same kind (base or Sun/PH), new tier. null for a row that is
		/* not on the ladder. */
		function goat_grade_target($rowGrade, $newBase, $map)
		{
			$rowGrade = (int) $rowGrade;
			$newBase  = (int) $newBase;
			if (!isset($map[$rowGrade]) || !isset($map[$newBase]))
				return null;
			if ($map[$rowGrade]['kind'] === 'base')
				return $newBase;
			foreach ($map as $id => $g)
				if ($g['kind'] === 'sph' && $g['base'] === $newBase)
					return (int) $id;
			return null;
		}

		/* "T1B / T2B" — the display name of a grade. */
		function goat_grade_name($id, $map)
		{
			$id = (int) $id;
			if (!isset($map[$id]))
				return '';
			$g = $map[$id];
			if ($g['night_desc'] === '' || $g['night_desc'] === $g['day_desc'])
				return html_entity_decode($g['day_desc'], ENT_QUOTES);
			return html_entity_decode($g['day_desc'] . ' / ' . $g['night_desc'], ENT_QUOTES);
		}

		/*
		/* Rows on this person's calls that the change from $wef would affect,
		/* split three ways (BRIEF §2.2):
		/*   rows        unlocked, on/after WEF, would change
		/*   before_wef  unlocked, in the 60 days before WEF, would change if WEF were earlier
		/*   locked      locked, on/after WEF, would otherwise qualify
		/*
		/* $onlyCallIds limits the read to those calls (the apply re-check).
		*/
		function goat_grade_change_rows($pdo, $userId, $newBase, $wefIso, $map, $onlyCallIds)
		{
			$wefTs    = goat_day_start_ts($wefIso);
			$fromTs   = $wefTs - 60 * 86400;
			$statuses = goat_grade_change_statuses();
			$ladder   = array_keys($map);

			$sql = 'SELECT ccm.callID, ccm.status, ccm.callpaygradeID,
			               c.start_date, c.call_name, c.call_locked, c.bookingID,
			               b.name AS booking_name
			        FROM call_crew_map ccm
			        JOIN calls    c ON c.id = ccm.callID
			        JOIN bookings b ON b.id = c.bookingID
			        WHERE ccm.userID = ?
			          AND b.hidden = 0
			          AND c.start_date >= ?
			          AND ccm.status IN (' . implode(',', array_map('intval', $statuses)) . ')
			          AND ccm.callpaygradeID IN (' . implode(',', array_map('intval', $ladder)) . ')';
			$params = array((int) $userId, $fromTs);

			if (is_array($onlyCallIds))
			{
				if (!count($onlyCallIds))
					return array('rows' => array(), 'before_wef' => array(), 'locked' => array());
				$sql .= ' AND ccm.callID IN (' . implode(',', array_map('intval', $onlyCallIds)) . ')';
			}
			$sql .= ' ORDER BY c.start_date, ccm.callID';

			$st = $pdo->prepare($sql);
			$st->execute($params);

			$out = array('rows' => array(), 'before_wef' => array(), 'locked' => array());
			$tz  = goat_melbourne_tz();

			foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
			{
				$from = (int) $r['callpaygradeID'];
				$to   = goat_grade_target($from, $newBase, $map);
				if ($to === null || $to === $from)
					continue;

				$ts = (int) $r['start_date'];
				$dt = new DateTime('@' . $ts);
				$dt->setTimezone($tz);

				$item = array(
					'call_id'      => (int) $r['callID'],
					'booking_id'   => (int) $r['bookingID'],
					'booking_name' => html_entity_decode((string) $r['booking_name'], ENT_QUOTES),
					'call_name'    => html_entity_decode((string) $r['call_name'], ENT_QUOTES),
					'call_date'    => $dt->format('Y-m-d'),
					'call_time'    => $dt->format('H:i'),
					'status'       => (int) $r['status'],
					'from_grade'   => $from,
					'from_name'    => goat_grade_name($from, $map),
					'to_grade'     => $to,
					'to_name'      => goat_grade_name($to, $map),
					'direction'    => ($map[$to]['tier'] > $map[$from]['tier']) ? 'up' : 'down'
				);

				if ($ts >= $wefTs && (int) $r['call_locked'] === 1)
					$out['locked'][] = $item;
				else if ($ts >= $wefTs)
					$out['rows'][] = $item;
				else if ((int) $r['call_locked'] !== 1)
					$out['before_wef'][] = $item;
			}

			return $out;
		}
	}

?>
