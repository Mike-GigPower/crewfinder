<?php

	/*
	/* READ ONE CREW MEMBER'S EMERGENCY CARD — and record that it happened.
	/*
	/*   GET ?subject=<userID>
	/*
	/* The VIEWER is resolved by goat_acting_user_id() (session on the GOAT
	/* path, service key + userID on the Crew Hub path). The SUBJECT is a
	/* separate parameter. goat_emergency_scope() decides; this file does not
	/* re-implement any part of that rule.
	/*
	/* THE LOG IS WRITTEN AFTER THE CARD IS ASSEMBLED, AND ITS FAILURE NEVER
	/* BLOCKS THE RESPONSE. D5: nothing in this feature may stand between a
	/* crew boss and an epi-pen instruction. A log that delays care has
	/* inverted its own purpose, so a failed INSERT is logged to error_log and
	/* the card is still served.
	/*
	/* Phase 1 reaches here only through cohort admin/operations, because
	/* GOAT_EMERGENCY_BOSS_SCOPE is off. The boss branches are live in the
	/* scope function and tested; nothing here needs changing to enable them.
	/*
	/* PHP 5.6. JSON on every path.
	*/

	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/emergency-scope.php');
	include(dirname(__FILE__) . '/emergency-db.php');

	header('Content-Type: application/json');

	$viewerID = (int) goat_acting_user_id();

	if ($viewerID <= 0)
	{
		goat_json_error(401, 'Not authenticated');
		exit;
	}

	$subjectID = 0;

	if (isset($_GET['subject']))
	{
		$subjectID = (int) $_GET['subject'];
	}

	if ($subjectID <= 0)
	{
		goat_json_error(400, 'subject required');
		exit;
	}

	$decision = goat_emergency_scope($viewerID, $subjectID);

	if (!$decision['allowed'])
	{
		/*
		/* 403 and nothing else. Deliberately does NOT distinguish "no card"
		/* from "not allowed to see it" -- a refusal that leaks whether a card
		/* exists is a disclosure of its own.
		*/
		goat_json_error(403, 'Not permitted');
		exit;
	}

	$pdo = goat_emergency_pdo();

	if ($pdo === null)
	{
		goat_json_error(503, 'Emergency card storage is unavailable');
		exit;
	}

	$card = array(
		'state'      => 'not_answered',
		'updated_at' => null,
		'entries'    => array()
	);

	try
	{
		$st = $pdo->prepare('SELECT state, updated_at FROM crew_emergency_info WHERE user_id = ?');
		$st->execute(array($subjectID));
		$info = $st->fetch();

		if ($info !== false)
		{
			$card['state']      = $info['state'];
			$card['updated_at'] = $info['updated_at'];

			if ($info['state'] === 'declared')
			{
				$st = $pdo->prepare(
					'SELECT id, category, action_note, medication_location, sort_order
					   FROM crew_emergency_entry
					  WHERE user_id = ?
					  ORDER BY sort_order ASC, id ASC');
				$st->execute(array($subjectID));

				while ($row = $st->fetch())
				{
					$card['entries'][] = array(
						'id'                  => (int) $row['id'],
						'category'            => $row['category'],
						'action_note'         => $row['action_note'],
						'medication_location' => $row['medication_location'],
						'sort_order'          => (int) $row['sort_order']
					);
				}
			}
		}
	}
	catch (PDOException $e)
	{
		error_log('emergency-get: read failed for subject ' . $subjectID . ': ' . $e->getMessage());
		goat_json_error(500, 'Could not load the emergency card');
		exit;
	}

	/*
	/* The emergency CONTACT comes from `users`, where it already lives and
	/* where ops can already see it. Returned alongside because a card that
	/* says what to do and does not say who to ring is half an answer at the
	/* moment it is needed.
	*/

	$contact = array('name' => '', 'phone' => '');

	$ures = mysql_query('SELECT firstname, lastname, emergency_contact, emergency_phone
	                     FROM users WHERE id = ' . $subjectID . ' LIMIT 1');

	$subjectName = '';

	if ($ures !== false && mysql_num_rows($ures) > 0)
	{
		$urow             = mysql_fetch_object($ures);
		$subjectName      = trim($urow->firstname . ' ' . $urow->lastname);
		$contact['name']  = ($urow->emergency_contact === null) ? '' : $urow->emergency_contact;
		$contact['phone'] = ($urow->emergency_phone   === null) ? '' : $urow->emergency_phone;
	}

	/* ---- the log row ---- */

	/*
	/* Which surface asked. Derived from HOW the caller authenticated rather
	/* than taken as a parameter: a client-supplied surface is a client-
	/* supplied audit value, and this column exists to be trusted later.
	*/

	$surface = 'crewhub';

	if (isset($user) && $user->checkSession())
	{
		$surface = 'goat';
	}

	/*
	/* viewer_username, NOT EIN -- admin accounts carry ein "0", a shared
	/* placeholder, so EIN is not an identity. Read from `users` on the legacy
	/* connection, which is where every other reader of that table goes.
	*/

	$viewerUsername = (string) $viewerID;

	$vres = mysql_query('SELECT username FROM users WHERE id = ' . $viewerID . ' LIMIT 1');

	if ($vres !== false && mysql_num_rows($vres) > 0)
	{
		$vrow = mysql_fetch_object($vres);

		if ($vrow->username !== null && trim($vrow->username) !== '')
		{
			$viewerUsername = trim($vrow->username);
		}
	}

	try
	{
		$st = $pdo->prepare(
			'INSERT INTO crew_emergency_access_log
			   (subject_user_id, viewer_user_id, viewer_username, viewer_cohort,
			    surface, grant_reason, call_id, accessed_at)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

		$st->execute(array(
			$subjectID,
			$viewerID,
			$viewerUsername,
			goat_cohort_for_user($viewerID),
			$surface,
			$decision['reason'],
			$decision['call_id'],
			date('Y-m-d H:i:s')
		));
	}
	catch (PDOException $e)
	{
		/* BEST EFFORT, DELIBERATELY. The card is already assembled and is
		/* served below regardless. An audit gap is a problem to investigate;
		/* withholding an emergency instruction because the audit table is
		/* unhappy is a worse one. */

		error_log('emergency-get: ACCESS LOG WRITE FAILED viewer=' . $viewerID
		        . ' subject=' . $subjectID . ': ' . $e->getMessage());
	}

	echo json_encode(array(
		'ok'                => true,
		'subject_user_id'   => $subjectID,
		'subject_name'      => $subjectName,
		'card'              => $card,
		'emergency_contact' => $contact,
		'granted_by'        => $decision['reason']
	));
