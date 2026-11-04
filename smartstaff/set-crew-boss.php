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
	/* SET OR CLEAR THE CREW BOSS MARKER — call_crew_map.is_crew_boss (D19).
	/* Slice C of BRIEF-crew-self-times, §4.1.
	/*
	/* THE GOAT calls this FIRST when Ops set or clear the Crew Boss pill, and
	/* only then writes the Supabase crew_row_override row. SmartStaff goes
	/* first because it gates payroll: time approval is still done here, and
	/* goat_call_tiers() (supervision-graph.php) reads this column to decide
	/* who enters a call's times. A nightly check reports any row where the two
	/* stores disagree; this endpoint never touches Supabase.
	/*
	/* NOT is_call_boss. That binary(50) column is the Team Lead tick, written
	/* by SmartStaff's own makeboss; this is a separate tinyint(1) added by
	/* slice A, and nothing here reads or writes is_call_boss.
	/*
	/*   POST {callID, userID, on: 0|1}
	/*
	/*   200  {ok, callID, userID, is_crew_boss, tier}
	/*   403  not an admin session
	/*   404  no call_crew_map row for (callID, userID); for on:1, no
	/*        CONFIRMED row — the pill marks someone who is working the call
	/*   409  {ok:false, error, team_leads:[{user_id, name}]} — clearing would
	/*        leave the call with no Crew Boss and two or more Team Leads (D17)
	/*   422  bad body
	/*   500  database failure (detail in the server log, never the body)
	/*
	/* on:0 IS ACCEPTED ON A ROW IN ANY STATUS. Clearing has to work after the
	/* person has declined or been stood down — that is the moment the pill
	/* most needs to come off, and a refusal then would leave the marker on a
	/* row nobody is working, where goat_call_tiers() ignores it but the
	/* nightly drift check would flag it forever.
	/*
	/* THE D17 GUARD covers only what passes through here. SmartStaff's own
	/* screens can tick a second Team Lead without THE GOAT, so a call can
	/* still break the rule; goat_call_tiers() handles that case defensively
	/* (multi_team_lead, every Team Lead may enter, flagged to Ops).
	/*
	/* ADMIN SESSION ONLY, read through goat_user_cohort(). No service-key
	/* path: this is a write by an operator, made from THE GOAT on their own
	/* SmartStaff session.
	/*
	/* DATABASE: the write and its checks go through goat_pdo() (goat-db.php),
	/* prepared statements only; success is judged on the caught
	/* PDOException, then a follow-up SELECT — never affected_rows, which is 0
	/* for a write that sets the value it already had. The tier reads go
	/* through goat_call_tier(), which is mysql_* like every helper in
	/* supervision-graph.php: two connections in one request, nothing shared
	/* in a transaction (MyISAM has none).
	/*
	/* PHP 5.x — array(), no ??, no short arrays.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		goat_json_error(403, 'Admin only');
	}

	/* ---- body ---- */

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

	/* ---- the row(s) ---- */

	try
	{
		$stmt = $pdo->prepare("SELECT `status` FROM `call_crew_map`
		                       WHERE `callID` = ? AND `userID` = ?");
		$stmt->execute(array($callID, $userID));
		$rows = $stmt->fetchAll();
	}
	catch (PDOException $e)
	{
		error_log('set-crew-boss: row lookup: ' . $e->getMessage());
		goat_json_error(500, 'row lookup failed');
	}

	if (count($rows) === 0)
	{
		goat_json_error(404, 'That person is not on this call');
	}

	$confirmed = false;

	foreach ($rows as $r)
	{
		if ((int) $r['status'] === 5)
		{
			$confirmed = true;
		}
	}

	if ($on === 1 && !$confirmed)
	{
		goat_json_error(404, 'That person is not confirmed on this call');
	}

	/*
	/* ---- D17: clearing must not leave two Team Leads and no Crew Boss ----
	/*
	/* Read through goat_call_tier(), the same answer the submit gate reads,
	/* so "would have no Crew Boss" means what the tier means: no OTHER
	/* confirmed pill holder on the call, and no live Crew Boss call above it
	/* with someone confirmed. A dedicated Crew Boss call is exempt — D21 has
	/* its own entry rule and it never becomes tier team_lead.
	*/

	if ($on === 0)
	{
		$t = goat_call_tier($callID);

		if (!$t['ok'])
		{
			error_log('set-crew-boss: tier lookup: ' . $t['error']);
			goat_json_error(500, 'tier lookup failed');
		}

		$tier = $t['tier'];

		if ($tier !== null && (int) $tier['is_boss_call'] !== 1)
		{
			/* other pill holders ON THE CALL — the supervising call's ticked
			   person is in crew_boss_user_ids too, so read the call's own */

			$otherPills = 0;

			try
			{
				$ps = $pdo->prepare("SELECT ccm.`userID` AS uid
				                     FROM `call_crew_map` ccm
				                     LEFT JOIN `call_promo_ack` cpa
				                            ON cpa.`callID` = ccm.`callID`
				                           AND cpa.`userID` = ccm.`userID`
				                           AND cpa.`acked_at` IS NULL
				                     WHERE ccm.`callID` = ?
				                       AND ccm.`status` = 5
				                       AND ccm.`is_crew_boss` = 1
				                       AND ccm.`userID` <> ?
				                       AND cpa.`id` IS NULL");
				$ps->execute(array($callID, $userID));
				$otherPills = count($ps->fetchAll());
			}
			catch (PDOException $e)
			{
				error_log('set-crew-boss: pill lookup: ' . $e->getMessage());
				goat_json_error(500, 'pill lookup failed');
			}

			$leads = $tier['team_lead_user_ids'];

			if ($otherPills === 0 && (int) $tier['supervised_by'] === 0 && count($leads) >= 2)
			{
				$named = array();

				try
				{
					$in = implode(',', array_fill(0, count($leads), '?'));
					$ns = $pdo->prepare("SELECT `id`, `firstname`, `lastname` FROM `users`
					                     WHERE `id` IN (" . $in . ")
					                     ORDER BY `lastname` ASC, `firstname` ASC");
					$ns->execute(array_map('intval', $leads));

					foreach ($ns->fetchAll() as $u)
					{
						$named[] = array(
							'user_id' => (int) $u['id'],
							'name'    => trim(trim(html_entity_decode((string) $u['firstname'], ENT_QUOTES))
							                . ' '
							                . trim(html_entity_decode((string) $u['lastname'], ENT_QUOTES)))
						);
					}
				}
				catch (PDOException $e)
				{
					error_log('set-crew-boss: team lead names: ' . $e->getMessage());
					goat_json_error(500, 'team lead lookup failed');
				}

				$names = array();

				foreach ($named as $n)
				{
					$names[] = $n['name'];
				}

				http_response_code(409);

				$body = json_encode(array(
					'ok'         => false,
					'error'      => 'This call has ' . count($leads) . ' Team Leads ('
					              . implode(', ', $names) . '). Without a Crew Boss it can only have one. '
					              . 'Clear all but one Team Lead first.',
					'team_leads' => $named
				));

				die($body === false ? '{"ok":false,"error":"request failed"}' : $body);
			}
		}
	}

	/* ---- write ---- */

	try
	{
		if ($on === 1)
		{
			/* confirmed rows only: the pill is never left on a row nobody works */

			$ws = $pdo->prepare("UPDATE `call_crew_map` SET `is_crew_boss` = 1
			                     WHERE `callID` = ? AND `userID` = ? AND `status` = 5");
		}
		else
		{
			$ws = $pdo->prepare("UPDATE `call_crew_map` SET `is_crew_boss` = 0
			                     WHERE `callID` = ? AND `userID` = ?");
		}

		$ws->execute(array($callID, $userID));
	}
	catch (PDOException $e)
	{
		/* the likeliest cause before slice A: the column does not exist yet */

		error_log('set-crew-boss: write: ' . $e->getMessage());
		goat_json_error(500, 'write failed');
	}

	/* ---- prove it ---- */

	try
	{
		$vs = $pdo->prepare("SELECT `status`, `is_crew_boss` FROM `call_crew_map`
		                     WHERE `callID` = ? AND `userID` = ?");
		$vs->execute(array($callID, $userID));
		$after = $vs->fetchAll();
	}
	catch (PDOException $e)
	{
		error_log('set-crew-boss: verify: ' . $e->getMessage());
		goat_json_error(500, 'verify failed');
	}

	foreach ($after as $r)
	{
		$want = ($on === 1 && (int) $r['status'] !== 5) ? (int) $r['is_crew_boss'] : $on;

		if ((int) $r['is_crew_boss'] !== $want)
		{
			goat_json_error(500, 'write did not take effect');
		}
	}

	/* the call's tier after the change, so THE GOAT can redraw the header */

	$t2 = goat_call_tier($callID);

	echo json_encode(array(
		'ok'           => true,
		'callID'       => $callID,
		'userID'       => $userID,
		'is_crew_boss' => $on,
		'tier'         => ($t2['ok'] && $t2['tier'] !== null) ? $t2['tier']['tier'] : null
	));

?>
