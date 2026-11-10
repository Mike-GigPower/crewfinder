<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include('call-graph.php');
	include(dirname(__FILE__) . '/goat-db.php');

	header('Content-Type: application/json');

	/*
	/* OFFER REMINDERS — every OPEN offer made since go-live, for the Crew Hub
	/* reminder cron (DESIGN-offer-reminders v0.3 §6, BRIEF slice 2 §3.1).
	/*
	/* Selection is BY OFFER TIME, not call date (unlike get-open-offers-bulk.php,
	/* which serves the ops dashboard and is deliberately left alone). It mirrors
	/* what crew can see in my-call-offers.php: status 0/1 on a call that exists,
	/* Completed bookings included; HIDDEN bookings excluded — on prod they are
	/* test and cancelled jobs (Q12 revised, 10 Nov). Cancelled calls are
	/* excluded as a safety net; cancellation normally moves the rows to 9 anyway.
	/*
	/* EVERY INSTANT IS UNIX SECONDS. offered_unix comes from UNIX_TIMESTAMP()
	/* in the same MariaDB session timezone that wrote offered_at with NOW(), so
	/* the round trip is exact. start_unix uses the DST-safe strtotime idiom from
	/* get-open-offers-bulk.php. The cron does arithmetic on these two numbers
	/* only; the *_local strings are for display.
	/*
	/* PACKAGE: goat_user_package() — the same helper respond-to-call.php uses
	/* to answer linked calls as one unit — so one reminder per package can never
	/* disagree with how the crew member's answer is applied. Memoised per
	/* person: every call in a found package is assigned to it, so each package
	/* is walked once.
	/*
	/* No writes. PHP 5.6.
	*/

	$goat_key = isset($_SERVER['HTTP_X_GOAT_SERVICE_KEY'])
	          ? $_SERVER['HTTP_X_GOAT_SERVICE_KEY'] : '';

	if (!goat_service_key_ok($goat_key) && goat_user_cohort() !== 'admin')
	{
		goat_json_error(403, 'Service key or admin session required');
	}

	$since_raw = isset($_GET['since']) ? trim($_GET['since']) : '';

	if (!preg_match('/^\d{10}$/', $since_raw))
	{
		goat_json_error(400, 'since must be a 10-digit unix timestamp');
	}

	$since = (int) $since_raw;

	/* 1 Nov 2026 00:00 AEDT. Nothing before this can be a go-live instant, so a
	/* smaller value is a configuration mistake, not a request. */
	if ($since < 1761915600)
	{
		goat_json_error(400, 'since is earlier than 1 Nov 2026');
	}

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		goat_json_error(503, 'Database handle unavailable');
	}

	$sql = "
		SELECT
			ccm.crewmapID                                      AS crewmap_id,
			ccm.callID                                         AS call_id,
			ccm.userID                                         AS user_id,
			ccm.status                                         AS status,
			ccm.responded_src                                  AS responded_src,
			DATE_FORMAT(ccm.offered_at, '%Y-%m-%d %H:%i:%s')   AS offered_at,
			UNIX_TIMESTAMP(ccm.offered_at)                     AS offered_unix,
			u.ein                                              AS ein,
			c.bookingID                                        AS booking_id,
			c.call_name                                        AS call_name,
			c.start_date                                       AS start_date,
			c.start_time                                       AS start_time,
			b.name                                             AS booking_name,
			v.venue                                            AS venue_name
		FROM call_crew_map ccm
		INNER JOIN calls    c ON c.id = ccm.callID
		INNER JOIN users    u ON u.id = ccm.userID
		LEFT  JOIN bookings b ON b.id = c.bookingID
		LEFT  JOIN venues   v ON v.id = b.venueID
		WHERE ccm.status IN (0, 1)
		  AND ccm.offered_at IS NOT NULL
		  AND ccm.offered_at >= FROM_UNIXTIME(:since)
		  AND c.cancelled_at IS NULL
		  AND (b.hidden IS NULL OR b.hidden = 0)
		ORDER BY ccm.userID ASC, ccm.offered_at ASC, ccm.callID ASC
	";

	$rows = array();

	try
	{
		$stmt = $pdo->prepare($sql);
		$stmt->execute(array(':since' => $since));

		while ($r = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$rows[] = $r;
		}

		$stmt->closeCursor();
	}
	catch (PDOException $e)
	{
		error_log('get-offers-due: ' . $e->getMessage());
		goat_json_error(500, 'offers query failed');
	}

	/* ---- packages, memoised per person ---- */

	$pkgOf = array();   /* [userID][callID] => array of call ids */

	foreach ($rows as $r)
	{
		$uid = (int) $r['user_id'];
		$cid = (int) $r['call_id'];

		if (isset($pkgOf[$uid][$cid]))
			continue;

		$pkg = array();

		foreach (goat_user_package($uid, $cid) as $p)
		{
			$p = (int) $p;
			if ($p > 0)
				$pkg[] = $p;
		}

		if (!in_array($cid, $pkg))
			$pkg[] = $cid;

		sort($pkg);

		foreach ($pkg as $p)
		{
			$pkgOf[$uid][$p] = $pkg;
		}
	}

	/* ---- rows ---- */

	$offers = array();

	foreach ($rows as $r)
	{
		$uid = (int) $r['user_id'];
		$cid = (int) $r['call_id'];

		/* start instant — identical idiom to get-open-offers-bulk.php */
		$date_i  = (int) $r['start_date'];
		$time_hm = substr((string) $r['start_time'], 0, 5);
		if (!preg_match('/^\d{2}:\d{2}$/', $time_hm)) $time_hm = '00:00';

		list($hh, $mm) = array_map('intval', explode(':', $time_hm));
		$start_unix = strtotime(date('Y-m-d', $date_i) . ' ' . $time_hm . ':00');
		if ($start_unix === false) $start_unix = $date_i + ($hh * 3600) + ($mm * 60);

		$offered_unix = (int) $r['offered_unix'];
		$pkg          = $pkgOf[$uid][$cid];

		$offers[] = array(
			'crewmap_id'    => (int) $r['crewmap_id'],
			'call_id'       => $cid,
			'booking_id'    => (int) $r['booking_id'],
			'user_id'       => $uid,
			'ein'           => ($r['ein'] === null ? null : (string) $r['ein']),
			'status'        => (int) $r['status'],
			'offered_at'    => $r['offered_at'],
			'offered_unix'  => $offered_unix,
			'start_unix'    => (int) $start_unix,
			'start_local'   => date('Y-m-d\TH:i:s', $start_unix),
			'call_name'     => $r['call_name'],
			'booking_name'  => $r['booking_name'],
			'venue'         => $r['venue_name'],
			'short_notice'  => (($start_unix - $offered_unix) < 108000) ? true : false,
			'package_id'    => (int) $pkg[0],
			'package_calls' => $pkg,
			'phone_flag'    => ($r['responded_src'] === 'phone') ? true : false
		);
	}

	$out = json_encode(array(
		'ok'              => true,
		'server_now_unix' => time(),
		'since_unix'      => $since,
		'count'           => count($offers),
		'offers'          => $offers
	));

	if ($out === false)
	{
		/* latin1 names that are not valid UTF-8 make json_encode fail on 5.6 */
		error_log('get-offers-due: json_encode failed: ' . json_last_error());
		goat_json_error(500, 'could not encode response');
	}

	echo $out;

?>
