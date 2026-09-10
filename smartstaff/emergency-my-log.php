<?php

	/*
	/* THE CREW MEMBER READS THEIR OWN ACCESS LOG — who opened their emergency
	/* card, and when.
	/*
	/* D6, and it is not a nicety. The card is only defensible to the person
	/* whose data it is if they can see who looked at it. Everything else in
	/* this feature is something done TO them; this is the one surface that is
	/* theirs.
	/*
	/*   GET  ->  { ok, card: {...}, log: [ ... ] }
	/*
	/* Self-scope only. There is no parameter here that names another user, by
	/* design -- not a filtered one, not an admin override. An operator who
	/* wants to know who read a card queries the table.
	/*
	/* PHP 5.6. JSON on every path.
	*/

	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/emergency-db.php');

	header('Content-Type: application/json');

	$LIMIT = 200;

	$userID = (int) goat_acting_user_id();

	if ($userID <= 0)
	{
		goat_json_error(401, 'Not authenticated');
		exit;
	}

	$pdo = goat_emergency_pdo();

	if ($pdo === null)
	{
		goat_json_error(503, 'Emergency card storage is unavailable');
		exit;
	}

	try
	{
		/* ---- the card itself, so one call drives the whole page ---- */

		$st = $pdo->prepare(
			'SELECT state, consent_at, updated_at
			   FROM crew_emergency_info
			  WHERE user_id = ?');
		$st->execute(array($userID));
		$info = $st->fetch();

		$card = array(
			'state'      => 'not_answered',
			'consent_at' => null,
			'updated_at' => null,
			'entries'    => array()
		);

		if ($info !== false)
		{
			$card['state']      = $info['state'];
			$card['consent_at'] = $info['consent_at'];
			$card['updated_at'] = $info['updated_at'];

			$st = $pdo->prepare(
				'SELECT id, category, action_note, medication_location, sort_order
				   FROM crew_emergency_entry
				  WHERE user_id = ?
				  ORDER BY sort_order ASC, id ASC');
			$st->execute(array($userID));

			while ($row = $st->fetch())
			{
				/* (int) on every integer field: this driver returns strings
				/* for numerics even with emulation off. */

				$card['entries'][] = array(
					'id'                  => (int) $row['id'],
					'category'            => $row['category'],
					'action_note'         => $row['action_note'],
					'medication_location' => $row['medication_location'],
					'sort_order'          => (int) $row['sort_order']
				);
			}
		}

		/* ---- the log ---- */

		$st = $pdo->prepare(
			'SELECT viewer_user_id, viewer_username, viewer_cohort,
			        surface, grant_reason, call_id, accessed_at
			   FROM crew_emergency_access_log
			  WHERE subject_user_id = ?
			  ORDER BY accessed_at DESC, id DESC
			  LIMIT ' . (int) $LIMIT);
		$st->execute(array($userID));

		$rows = $st->fetchAll();
	}
	catch (PDOException $e)
	{
		error_log('emergency-my-log: read failed for user ' . $userID . ': ' . $e->getMessage());
		goat_json_error(500, 'Could not load the access log');
		exit;
	}

	/*
	/* Put a human name on each viewer. The log stores viewer_username because
	/* it must stay meaningful even after a users row changes or goes -- but
	/* "smartst_ops2" tells a crew member nothing, and the point of showing
	/* them the log is that they can recognise who looked.
	/*
	/* Names come from the LEGACY mysql_* connection: `users` lives in the same
	/* database but everything else that reads it in this codebase goes through
	/* that handle, and a second reader of `users` on a different connection is
	/* not worth introducing for a display string.
	/*
	/* SELF-REVEALS ARE FILTERED OUT. Every time the crew member opens their
	/* own card the scope function returns reason 'self', and a log that is
	/* mostly "you looked at your own card" buries the entries that matter.
	*/

	$log = array();

	foreach ($rows as $r)
	{
		if ($r['grant_reason'] === 'self')
		{
			continue;
		}

		$vid  = (int) $r['viewer_user_id'];
		$name = trim((string) $r['viewer_username']);

		$ures = mysql_query('SELECT firstname, lastname FROM users WHERE id = ' . $vid . ' LIMIT 1');

		if ($ures !== false && mysql_num_rows($ures) > 0)
		{
			$urow = mysql_fetch_object($ures);
			$full = trim($urow->firstname . ' ' . $urow->lastname);

			if ($full !== '')
			{
				$name = $full;
			}
		}

		$log[] = array(
			'viewed_by'   => $name,
			'cohort'      => $r['viewer_cohort'],
			'surface'     => $r['surface'],
			'reason'      => $r['grant_reason'],
			'call_id'     => ($r['call_id'] === null ? null : (int) $r['call_id']),
			'accessed_at' => $r['accessed_at']
		);
	}

	echo json_encode(array(
		'ok'   => true,
		'card' => $card,
		'log'  => $log
	));
