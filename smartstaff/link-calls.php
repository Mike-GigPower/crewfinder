<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* Link or unlink a set of calls (Phase 1 of "linked calls").
	/*
	/* Calls that share a non-null `link_group` are one linked set: a crew
	/* member's confirm/decline on any of them cascades to all of their offered
	/* rows in the group (see respond-to-call.php). This endpoint only maintains
	/* the grouping on the `calls` table — it never touches call_crew_map,
	/* calendars or accounting.
	/*
	/*   action = "link"   : body {action, call_ids:[>=2]} — all must belong to
	/*                       the SAME booking and be currently unlinked. Mints a
	/*                       fresh group id from call_link_seq and stamps it on.
	/*   action = "unlink" : body {action, call_ids:[>=1]} — clears link_group on
	/*                       those calls. Any group left with a single member is
	/*                       dissolved too (a group of one is meaningless).
	/*
	/* Admin-only (same gate as update-call.php). PHP 5.x — no null-coalescing
	/* (??), no short array syntax.
	*/

	function send_status($code, $msg)
	{
		$proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($proto . ' ' . $code . ' ' . $msg);
	}

	/* ---- ADMIN ONLY ---- */

	if (goat_user_cohort() !== 'admin')
	{
		send_status(403, 'Forbidden');
		die('{"error":"Admin only"}');
	}

	/* ---- parse body ---- */

	$raw     = file_get_contents('php://input');
	$payload = json_decode($raw);

	if (!$payload || !isset($payload->action) || !isset($payload->call_ids) || !is_array($payload->call_ids))
	{
		send_status(400, 'Bad Request');
		die('{"error":"Invalid or missing JSON body (expected {action, call_ids:[...]})"}');
	}

	$action = $payload->action;

	/* sanitise ids to positive ints, dedup */

	$ids = array();

	foreach ($payload->call_ids as $v)
	{
		$n = (int) $v;
		if ($n > 0 && !in_array($n, $ids))
		{
			$ids[] = $n;
		}
	}

	if (count($ids) < 1)
	{
		send_status(422, 'Unprocessable Entity');
		die('{"error":"No valid call_ids"}');
	}

	$idList = implode(',', $ids);

	/* ======================= LINK ======================= */

	if ($action === 'link')
	{
		if (count($ids) < 2)
		{
			send_status(422, 'Unprocessable Entity');
			die('{"error":"Linking needs at least two calls"}');
		}

		/* all ids must exist, share one booking, and be currently unlinked */

		$res = mysql_query("SELECT id, bookingID, link_group FROM calls WHERE id IN (" . $idList . ")");

		if ($res === false)
		{
			send_status(500, 'Internal Server Error');
			die('{"error":"calls lookup failed: ' . addslashes(mysql_error()) . '"}');
		}

		$found     = 0;
		$bookingID = null;
		$errors    = array();

		while ($row = mysql_fetch_object($res))
		{
			$found++;

			if ($bookingID === null)
			{
				$bookingID = (int) $row->bookingID;
			}
			else if ((int) $row->bookingID !== $bookingID)
			{
				$errors[] = 'All calls must belong to the same booking';
			}

			if ($row->link_group !== null && (int) $row->link_group > 0)
			{
				$errors[] = 'Call ' . ((int) $row->id) . ' is already linked (unlink it first)';
			}
		}

		if ($found !== count($ids))
		{
			$errors[] = 'One or more calls were not found';
		}

		$errors = array_values(array_unique($errors));

		if (count($errors))
		{
			send_status(422, 'Unprocessable Entity');
			echo json_encode(array('error' => 'link rejected', 'errors' => $errors));
			die();
		}

		/* mint a fresh group id */

		mysql_query("INSERT INTO call_link_seq (created) VALUES (" . time() . ")");

		if (mysql_error())
		{
			send_status(500, 'Internal Server Error');
			die('{"error":"could not create link group: ' . addslashes(mysql_error()) . '"}');
		}

		$group = (int) mysql_insert_id();

		mysql_query("UPDATE calls SET link_group = " . $group . " WHERE id IN (" . $idList . ")");

		if (mysql_error())
		{
			send_status(500, 'Internal Server Error');
			die('{"error":"link update failed: ' . addslashes(mysql_error()) . '"}');
		}

		echo json_encode(array(
			'ok'         => true,
			'action'     => 'link',
			'link_group' => $group,
			'call_ids'   => $ids
		));
		die();
	}

	/* ======================= UNLINK ======================= */

	if ($action === 'unlink')
	{
		/* remember which groups these calls belonged to, to clean up singletons */

		$affectedGroups = array();
		$gres = mysql_query("SELECT DISTINCT link_group FROM calls WHERE id IN (" . $idList . ") AND link_group IS NOT NULL");

		if ($gres !== false)
		{
			while ($g = mysql_fetch_object($gres))
			{
				$affectedGroups[] = (int) $g->link_group;
			}
		}

		mysql_query("UPDATE calls SET link_group = NULL WHERE id IN (" . $idList . ")");

		if (mysql_error())
		{
			send_status(500, 'Internal Server Error');
			die('{"error":"unlink update failed: ' . addslashes(mysql_error()) . '"}');
		}

		/* dissolve any group now left with a single call */

		$dissolved = array();

		foreach ($affectedGroups as $g)
		{
			$cres = mysql_query("SELECT COUNT(*) AS n FROM calls WHERE link_group = " . $g);

			if ($cres !== false)
			{
				$crow = mysql_fetch_object($cres);
				if ($crow && ((int) $crow->n) === 1)
				{
					mysql_query("UPDATE calls SET link_group = NULL WHERE link_group = " . $g);
					$dissolved[] = $g;
				}
			}
		}

		echo json_encode(array(
			'ok'                   => true,
			'action'               => 'unlink',
			'unlinked'             => $ids,
			'dissolved_singletons' => $dissolved
		));
		die();
	}

	/* unknown action */

	send_status(400, 'Bad Request');
	die('{"error":"action must be link or unlink"}');

?>
