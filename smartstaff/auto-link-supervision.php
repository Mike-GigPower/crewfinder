<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');
	include_once('supervision-graph.php');
	include_once('link-button.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* THE LINK BUTTON — "Link to Crew Boss calls" on a booking's Crew bosses
	/* tab (BRIEF-default-link-self-submit-off, Release 2, B3; DESIGN v0.6
	/* §4.2; J13-J18).
	/*
	/* Links each call that overlaps exactly one live Crew Boss call on its
	/* booking to that call, after Ops have seen the list and unticked any that
	/* really have no Crew Boss. Every link is an Ops action: there is no cron,
	/* no CLI path and no service key (design v0.6 §5).
	/*
	/*   POST {mode: "dry_run", booking_id}
	/*     200 {ok, mode, booking_id, boss_call_count, candidates: [row], counts}
	/*         row = {child_call, call_name, start_date, start_time,
	/*                boss_call, boss_call_name, boss_calls: [{call_id, call_name}],
	/*                from_boss_call, from_boss_call_name, outcome, reason,
	/*                reason_text, boss_has_confirmed, note}
	/*         outcome offer | relink | marked | skip — see
	/*         goat_auto_link_candidates(). Calls already linked to a live Crew
	/*         Boss call are left out: there is nothing to do or say about them.
	/*
	/*   POST {mode: "apply", booking_id, link: [callIDs], mark: [callIDs]}
	/*     200 {ok, mode, booking_id, results: [{call_id, action: link|mark,
	/*          result: done|refused, reason, boss_call, note}], counts}
	/*     Each call is RE-VALIDATED against goat_auto_link_candidates() under
	/*     the booking's lock — the booking may have changed since the preview.
	/*     Only offer / relink rows are linked; anything else is refused with
	/*     its reason. `mark` uses no-crew-boss.php's checks. A callID in both
	/*     lists is linked, and its mark refused. A partial failure never hides
	/*     the successes: per-call outcomes are always a 200.
	/*
	/*   403 not an admin session (J18)
	/*   404 no such booking
	/*   409 another apply holds this booking's lock
	/*   422 bad body
	/*   500 a lookup or the lock failed
	/*
	/* WHAT `set` WOULD SAY. The link is the same upsert call-supervision.php
	/* `set` writes, plus auto = 1, after the same checks: the boss call is
	/* boss-named and live (rule 2), and D17 is re-run (rule 7). Of set's
	/* overridable warnings, this endpoint accepts exactly two, as an Ops user
	/* pressing the button has already seen both in the preview:
	/*   no_confirmed_boss  (J16) — boss_has_confirmed = 0; the note says the
	/*                      link takes effect once someone is confirmed;
	/*   reassignment       (J17) — only on a relink, whose current link is to a
	/*                      cancelled boss call.
	/* Every other warning or refusal refuses that call.
	/*
	/* created_by is the session user, read straight from the session as
	/* call-supervision.php does it — never goat_acting_user_id(), whose
	/* service-key branch would let a caller name the user.
	/*
	/* LOCK: GET_LOCK('goat_link_<bookingID>', 10) on the PDO connection that
	/* writes, held from before the re-validation read until the last write,
	/* so two operators pressing Link on one booking cannot both pass the same
	/* check. GET_LOCK and RELEASE_LOCK both run on that one connection
	/* (goat_pdo() is memoised), and alsv_unlock() is registered as a shutdown
	/* function so the lock is released on every exit path — die(), a fatal
	/* error or the normal reply.
	/*
	/* DATABASE: the rule's reads go through supervision-graph.php's shared
	/* helpers (mysql_*); everything this file reads or writes itself goes
	/* through goat_pdo() (here and in link-button.php) — set-team-lead.php's
	/* split. No new mysql_* call sites.
	/*
	/* PHP 5.x — array(), no ??, no short array syntax.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		goat_json_error(403, 'Admin only');
	}

	$actor = isset($_SESSION[SITE_KEY]['userID']) ? (int) $_SESSION[SITE_KEY]['userID'] : 0;

	$payload = json_decode(file_get_contents('php://input'));

	if (!$payload || !isset($payload->mode) || !isset($payload->booking_id))
	{
		goat_json_error(422, 'mode and booking_id are required');
	}

	$mode      = (string) $payload->mode;
	$bookingID = (int) $payload->booking_id;

	if ($mode !== 'dry_run' && $mode !== 'apply')
	{
		goat_json_error(422, 'mode must be dry_run or apply');
	}

	if ($bookingID <= 0)
	{
		goat_json_error(422, 'booking_id must be a positive integer');
	}

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		goat_json_error(500, 'database unavailable');
	}

	try
	{
		$bs = $pdo->prepare("SELECT `id` FROM `bookings` WHERE `id` = ? LIMIT 1");
		$bs->execute(array($bookingID));
		$brow = $bs->fetch();
	}
	catch (PDOException $e)
	{
		error_log('auto-link-supervision: booking lookup: ' . $e->getMessage());
		goat_json_error(500, 'booking lookup failed');
	}

	if (!$brow)
	{
		goat_json_error(404, 'No such booking');
	}

	/* One row as the preview reads it. */

	function alsv_row($r, $names)
	{
		$from = (int) $r['from_boss_call'];
		$list = array();

		foreach ($r['boss_calls'] as $b)
		{
			$list[] = array('call_id' => (int) $b['call_id'], 'call_name' => goat_lb_name($b['call_name']));
		}

		$note = '';

		if (($r['outcome'] === 'offer' || $r['outcome'] === 'relink') && (int) $r['boss_has_confirmed'] === 0)
		{
			$note = 'Crew Boss call has nobody confirmed yet — takes effect once someone is';
		}

		return array(
			'child_call'          => (int) $r['child_call'],
			'call_name'           => goat_lb_name($r['call_name']),
			'start_date'          => $r['start_date'],
			'start_time'          => $r['start_time'],
			'boss_call'           => (int) $r['boss_call'],
			'boss_call_name'      => goat_lb_name($r['boss_call_name']),
			'boss_calls'          => $list,
			'from_boss_call'      => $from,
			'from_boss_call_name' => ($from > 0 && isset($names[$from])) ? goat_lb_name($names[$from]) : '',
			'outcome'             => $r['outcome'],
			'reason'              => $r['reason'],
			'reason_text'         => goat_auto_link_reason_text($r),
			'boss_has_confirmed'  => (int) $r['boss_has_confirmed'],
			'note'                => $note
		);
	}

	/* Names of the cancelled boss calls a relink moves away from (not in the live set). */

	function alsv_from_names($pdo, $rows)
	{
		$ids = array();

		foreach ($rows as $r)
		{
			if ((int) $r['from_boss_call'] > 0)
			{
				$ids[] = (int) $r['from_boss_call'];
			}
		}

		$ids = goat_sg_clean_ids($ids);
		$out = array();

		if (count($ids) === 0)
		{
			return $out;
		}

		try
		{
			$st = $pdo->prepare("SELECT `id`, `call_name` FROM `calls` WHERE `id` IN ("
			                    . implode(',', array_fill(0, count($ids), '?')) . ")");
			$st->execute($ids);

			foreach ($st->fetchAll() as $row)
			{
				$out[(int) $row['id']] = (string) $row['call_name'];
			}
		}
		catch (PDOException $e)
		{
			error_log('auto-link-supervision: names: ' . $e->getMessage());   /* display only: names stay blank */
		}

		return $out;
	}

	function alsv_reply($body)
	{
		$json = json_encode($body);

		if ($json === false)
		{
			goat_json_error(500, 'could not encode the reply');
		}

		echo $json;
		die();
	}

	/* ======================= DRY RUN ======================= */

	if ($mode === 'dry_run')
	{
		$cand = goat_auto_link_candidates($bookingID, time());

		if (!$cand['ok'])
		{
			error_log('auto-link-supervision: dry_run: ' . $cand['error']);
			goat_json_error(500, 'Couldn\'t work out which calls to link: ' . $cand['error']);
		}

		$names  = alsv_from_names($pdo, $cand['rows']);
		$rows   = array();
		$counts = array('offer' => 0, 'relink' => 0, 'marked' => 0, 'skip' => 0);

		foreach ($cand['rows'] as $r)
		{
			if ($r['reason'] === 'already_linked')
			{
				continue;
			}

			$rows[] = alsv_row($r, $names);
			$counts[$r['outcome']]++;
		}

		alsv_reply(array(
			'ok'              => true,
			'mode'            => 'dry_run',
			'booking_id'      => $bookingID,
			'boss_call_count' => (int) $cand['boss_call_count'],
			'candidates'      => $rows,
			'counts'          => $counts
		));
	}

	/* ======================= APPLY ======================= */

	$link = goat_lb_ids(isset($payload->link) ? $payload->link : null);
	$mark = goat_lb_ids(isset($payload->mark) ? $payload->mark : null);

	if (count($link) === 0 && count($mark) === 0)
	{
		goat_json_error(422, 'link or mark must name at least one call');
	}

	$lockName = 'goat_link_' . $bookingID;

	try
	{
		$ls = $pdo->prepare("SELECT GET_LOCK(?, 10) AS got");
		$ls->execute(array($lockName));
		$lrow = $ls->fetch();
	}
	catch (PDOException $e)
	{
		error_log('auto-link-supervision: lock: ' . $e->getMessage());
		goat_json_error(500, 'lock failed');
	}

	if (!$lrow || (int) $lrow['got'] !== 1)
	{
		goat_json_error(409, 'Someone else is linking this booking — try again.');
	}

	/*
	/* RELEASE ON EVERY EXIT PATH. From here the request can end in a die()
	/* (goat_json_error() on a failed re-check), a fatal error, or the normal
	/* reply. A shutdown function runs after all three, while $pdo — the
	/* connection that holds the lock, since locks are per connection — is
	/* still open. alsv_unlock() is idempotent: the normal path calls it
	/* before replying, and the shutdown call then does nothing.
	*/

	$GLOBALS['alsv_lock'] = array('pdo' => $pdo, 'name' => $lockName, 'held' => true);

	function alsv_unlock()
	{
		$l =& $GLOBALS['alsv_lock'];

		if (empty($l['held']))
		{
			return;
		}

		$l['held'] = false;

		try
		{
			$rs = $l['pdo']->prepare("SELECT RELEASE_LOCK(?)");
			$rs->execute(array($l['name']));
		}
		catch (PDOException $e)
		{
			error_log('auto-link-supervision: unlock: ' . $e->getMessage());   /* freed at disconnect anyway */
		}
	}

	register_shutdown_function('alsv_unlock');

	$now  = time();
	$cand = goat_auto_link_candidates($bookingID, $now);

	if (!$cand['ok'])
	{
		error_log('auto-link-supervision: apply: ' . $cand['error']);
		goat_json_error(500, 'Couldn\'t re-check the calls: ' . $cand['error']);
	}

	$results = array();
	$linked  = array();
	$counts  = array('linked' => 0, 'marked' => 0, 'refused' => 0);

	foreach ($link as $cid)
	{
		$r = isset($cand['by_call'][$cid]) ? $cand['by_call'][$cid] : null;

		if ($r === null || ($r['outcome'] !== 'offer' && $r['outcome'] !== 'relink'))
		{
			$why = ($r === null)
			     ? 'It isn\'t a call on this booking that overlaps a Crew Boss call.'
			     : goat_auto_link_reason_text($r);

			$results[] = array('call_id' => $cid, 'action' => 'link', 'result' => 'refused',
			                   'reason' => $why, 'boss_call' => 0, 'note' => '');
			$counts['refused']++;
			continue;
		}

		$err = goat_lb_link($pdo, $bookingID, (int) $r['boss_call'], $cid, $actor, $now);

		if ($err !== '')
		{
			$results[] = array('call_id' => $cid, 'action' => 'link', 'result' => 'refused',
			                   'reason' => $err, 'boss_call' => (int) $r['boss_call'], 'note' => '');
			$counts['refused']++;
			continue;
		}

		$note = goat_lb_unmark($pdo, $cid);

		if ($note === '' && (int) $r['boss_has_confirmed'] === 0)
		{
			$note = 'Crew Boss call has nobody confirmed yet — takes effect once someone is';
		}

		$results[] = array('call_id' => $cid, 'action' => 'link', 'result' => 'done',
		                   'reason' => '', 'boss_call' => (int) $r['boss_call'], 'note' => $note);
		$linked[$cid] = true;
		$counts['linked']++;
	}

	if (count($mark) > 0)
	{
		$ref = goat_no_crew_boss_refusals($mark, $now);

		if (!$ref['ok'])
		{
			error_log('auto-link-supervision: mark check: ' . $ref['error']);

			foreach ($mark as $cid)
			{
				$results[] = array('call_id' => $cid, 'action' => 'mark', 'result' => 'refused',
				                   'reason' => 'Couldn\'t check the call: ' . $ref['error'], 'boss_call' => 0, 'note' => '');
				$counts['refused']++;
			}
		}
		else
		{
			foreach ($mark as $cid)
			{
				$why = isset($ref['by_call'][$cid]) ? $ref['by_call'][$cid] : 'Call not found.';

				if (isset($linked[$cid]))
				{
					$why = 'It was linked in this same request.';
				}
				else if ($why === '' && !isset($cand['by_call'][$cid]))
				{
					$why = 'It isn\'t a call on this booking.';
				}

				if ($why === '')
				{
					$why = goat_lb_mark($pdo, $cid, $actor, $now);
				}

				if ($why !== '')
				{
					$results[] = array('call_id' => $cid, 'action' => 'mark', 'result' => 'refused',
					                   'reason' => $why, 'boss_call' => 0, 'note' => '');
					$counts['refused']++;
					continue;
				}

				$results[] = array('call_id' => $cid, 'action' => 'mark', 'result' => 'done',
				                   'reason' => '', 'boss_call' => 0, 'note' => '');
				$counts['marked']++;
			}
		}
	}

	alsv_unlock();

	alsv_reply(array(
		'ok'         => true,
		'mode'       => 'apply',
		'booking_id' => $bookingID,
		'results'    => $results,
		'counts'     => $counts
	));

?>
