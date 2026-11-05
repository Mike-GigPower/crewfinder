<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');
	include_once('supervision-graph.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* ADD OR REMOVE ONE TEAM LEAD TICK WITHOUT TOUCHING THE OTHERS — slice G of
	/* BRIEF-crew-self-times (§14.3.4, D15/D17).
	/*
	/* SmartStaff's own makeboss (add-call.php) clears is_call_boss on EVERY row
	/* of the call before setting one, so through it a call has exactly one Team
	/* Lead. D15 says a call WITH a Crew Boss may have several (department
	/* leads). This endpoint is the only way to set a second one, and it does so
	/* only there; THE GOAT keeps using makeboss to MOVE the single Team Lead on
	/* a call with no Crew Boss.
	/*
	/*   POST {callID, userID, on: 0|1}
	/*
	/*   200  {ok, callID, userID, is_call_boss, team_leads} — team_leads are
	/*        the ticks that COUNT: confirmed, not promo-pending (R13)
	/*   403  not an admin session
	/*   404  no call_crew_map row; for on:1, no CONFIRMED row
	/*   409  on:1 to a promo-pending person, or
	/*        on:1 adding a SECOND Team Lead on a call with no Crew Boss (no
	/*        pill on it, no counting link — R14) — D17: "This call has no Crew
	/*        Boss, so it can only have
	/*        one Team Lead. Assign a Crew Boss first, or move the Team Lead."
	/*   422  bad body
	/*   500  database failure (detail in the server log, never the body)
	/*
	/* WHAT IS WRITTEN. on:1 writes the STRING '1' — what makeboss writes (byte
	/* 0x31, null-padded by binary(50)), and what every reader's (int) cast
	/* reads as 1. on:0 writes DEFAULT(is_call_boss), the column's own default:
	/* the value a row SmartStaff never ticked carries, so SmartStaff's screens
	/* see it exactly as they see any un-ticked row. (What makeboss writes when
	/* it CLEARS a row is not known from here — check on test, see the handback.)
	/*
	/* A Team Lead tick is NOT the Crew Boss pill. Nothing here reads or writes
	/* is_crew_boss.
	/*
	/* ADMIN SESSION ONLY, through goat_user_cohort(). Writes through goat_pdo(),
	/* prepared statements, success judged on the caught PDOException and then a
	/* follow-up SELECT — never affected_rows. The tier read goes through
	/* goat_call_tier(), mysql_*: two connections, no shared transaction.
	/*
	/* PHP 5.x — array(), no ??, no short arrays.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		goat_json_error(403, 'Admin only');
	}

	$payload = json_decode(file_get_contents('php://input'));

	if (!$payload || !isset($payload->callID) || !isset($payload->userID) || !isset($payload->on))
	{
		goat_json_error(422, 'callID, userID and on are required');
	}

	$callID = (int) $payload->callID;
	$userID = (int) $payload->userID;
	$onRaw  = $payload->on;

	if ($callID <= 0 || $userID <= 0)
	{
		goat_json_error(422, 'callID and userID must be positive integers');
	}

	if ($onRaw !== 0 && $onRaw !== 1 && $onRaw !== true && $onRaw !== false)
	{
		goat_json_error(422, 'on must be 0 or 1');
	}

	$on = ($onRaw === 1 || $onRaw === true) ? 1 : 0;

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		goat_json_error(500, 'database unavailable');
	}

	/* ---- the row(s), with the promotion state ---- */

	try
	{
		$stmt = $pdo->prepare("SELECT ccm.`status` AS status, ccm.`is_call_boss` AS is_call_boss,
		                              (cpa.`id` IS NOT NULL) AS promo_pending
		                       FROM `call_crew_map` ccm
		                       LEFT JOIN `call_promo_ack` cpa
		                              ON cpa.`callID` = ccm.`callID`
		                             AND cpa.`userID` = ccm.`userID`
		                             AND cpa.`acked_at` IS NULL
		                       WHERE ccm.`callID` = ? AND ccm.`userID` = ?");
		$stmt->execute(array($callID, $userID));
		$rows = $stmt->fetchAll();
	}
	catch (PDOException $e)
	{
		error_log('set-team-lead: row lookup: ' . $e->getMessage());
		goat_json_error(500, 'row lookup failed');
	}

	if (count($rows) === 0)
	{
		goat_json_error(404, 'That person is not on this call');
	}

	$confirmed    = false;
	$promoPending = false;
	$already      = false;

	foreach ($rows as $r)
	{
		if ((int) $r['promo_pending'] === 1) $promoPending = true;

		if ((int) $r['status'] === 5)
		{
			$confirmed = true;

			if ((int) $r['is_call_boss'] === 1) $already = true;   /* binary(50): first byte */
		}
	}

	/* A promo-pending person's tick does not count (review R13) — the tiers
	   ignore it, so "already a Team Lead" must not either. */

	if ($promoPending)
	{
		$already = false;
	}

	if ($on === 1)
	{
		if (!$confirmed)
		{
			goat_json_error(404, 'That person is not confirmed on this call');
		}

		if ($promoPending)
		{
			goat_json_error(409, 'That person has not accepted their promotion to this call yet, so they cannot be made Team Lead. Set it once they accept.');
		}

		/*
		/* D17, read through the tier. A second Team Lead is allowed only when
		/* the call HAS A CREW BOSS — goat_tier_has_crew_boss(): a confirmed
		/* pill on the call, or a supervision link that counts (review R14).
		/* NOT "tier is crew_boss": a dedicated Crew Boss call is tier
		/* crew_boss on a Team Lead tick alone, and that is not a Crew Boss.
		/*
		/* "Other Team Leads" are the tier's team_lead_user_ids — confirmed,
		/* NOT promo-pending (review R13). An unanswered promotion's tick is not
		/* a Team Lead anywhere else in the system, so it does not block one
		/* here either.
		*/

		if (!$already)
		{
			$t = goat_call_tier($callID);

			if (!$t['ok'] || $t['tier'] === null)
			{
				error_log('set-team-lead: tier lookup: ' . $t['error']);
				goat_json_error(500, 'tier lookup failed');
			}

			$others = 0;

			foreach ($t['tier']['team_lead_user_ids'] as $tl)
			{
				if ((int) $tl !== $userID) $others++;
			}

			if ($others >= 1 && !goat_tier_has_crew_boss($t['tier']))
			{
				goat_json_error(409, 'This call has no Crew Boss, so it can only have one Team Lead. Assign a Crew Boss first, or move the Team Lead.');
			}
		}
	}

	/* ---- write ---- */

	try
	{
		if ($on === 1)
		{
			$ws = $pdo->prepare("UPDATE `call_crew_map` SET `is_call_boss` = '1'
			                     WHERE `callID` = ? AND `userID` = ? AND `status` = 5");
		}
		else
		{
			$ws = $pdo->prepare("UPDATE `call_crew_map` SET `is_call_boss` = DEFAULT(`is_call_boss`)
			                     WHERE `callID` = ? AND `userID` = ?");
		}

		$ws->execute(array($callID, $userID));
	}
	catch (PDOException $e)
	{
		error_log('set-team-lead: write: ' . $e->getMessage());
		goat_json_error(500, 'write failed');
	}

	/* ---- prove it, and report the call's Team Leads after ---- */

	try
	{
		$vs = $pdo->prepare("SELECT ccm.`userID` AS userID, ccm.`status` AS status, ccm.`is_call_boss` AS is_call_boss,
		                            (cpa.`id` IS NOT NULL) AS promo_pending
		                     FROM `call_crew_map` ccm
		                     LEFT JOIN `call_promo_ack` cpa
		                            ON cpa.`callID` = ccm.`callID`
		                           AND cpa.`userID` = ccm.`userID`
		                           AND cpa.`acked_at` IS NULL
		                     WHERE ccm.`callID` = ?");
		$vs->execute(array($callID));
		$after = $vs->fetchAll();
	}
	catch (PDOException $e)
	{
		error_log('set-team-lead: verify: ' . $e->getMessage());
		goat_json_error(500, 'verify failed');
	}

	$leads = array();

	foreach ($after as $r)
	{
		$tick = ((int) $r['is_call_boss'] === 1) ? 1 : 0;

		if ((int) $r['userID'] === $userID)
		{
			$want = ($on === 1 && (int) $r['status'] !== 5) ? $tick : $on;

			if ($tick !== $want)
			{
				goat_json_error(500, 'write did not take effect');
			}
		}

		/* the Team Leads that COUNT: confirmed and not promo-pending (R13) */

		if ($tick === 1 && (int) $r['status'] === 5 && (int) $r['promo_pending'] !== 1)
		{
			$leads[(int) $r['userID']] = true;
		}
	}

	$leadIDs = array_keys($leads);
	sort($leadIDs);

	echo json_encode(array(
		'ok'           => true,
		'callID'       => $callID,
		'userID'       => $userID,
		'is_call_boss' => $on,
		'team_leads'   => $leadIDs
	));

?>
