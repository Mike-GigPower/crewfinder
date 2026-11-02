<?php

	/*
	/* global file */

	include('../../global.php');
	include_once(dirname(__FILE__) . '/../crew/cohort.php');

	/*
	/* JSON, like its sibling add-events-bulk.php. Set BEFORE
	/* goat_acting_user_id(), which emits a JSON body with a 401/400 status
	/* when it rejects the caller.
	*/

	header('Content-Type: application/json');

	/*
	/* Bulk-delete type = 1 (unavailability) rows for ONE crew member.
	/*
	/* The mirror of add-events-bulk.php, and needed for the same reason: the
	/* PHP file lock means Crew Hub issues requests one at a time, so removing a
	/* 12-month recurring pattern would otherwise be ~200 sequential calls to
	/* delete-event.php.
	/*
	/* ADDITIVE — nothing else calls this. delete-event.php is untouched, so THE
	/* GOAT and the legacy browser calendar are unaffected.
	/*
	/* REQUEST
	/*   POST /ajax/calendar/delete-events-bulk.php?userID=123
	/*   X-Goat-Service-Key: <secret>
	/*   Content-Type: application/json
	/*
	/*   { "ids": [40122, 40123, 40124] }
	/*
	/*   NOTE: userID goes in the QUERY STRING, not the JSON body.
	/*   goat_acting_user_id() reads $_GET / $_POST, and a JSON body populates
	/*   neither.
	/*
	/* RESPONSE  200
	/*   { "ok": true, "deleted": [40122, 40123], "missing": [40124] }
	/*
	/*   "missing" is an id that matched no row — already gone, not owned by
	/*   this user, or not type = 1. That is INFORMATION, not an error: the
	/*   caller clears its ledger either way, since the goal state (no such row)
	/*   has been reached.
	/*
	/* SAFETY — three independent scopes on every DELETE
	/*   1. id      the specific row
	/*   2. user    from goat_acting_user_id(), never from the payload, so a
	/*              crew member can only ever delete their own rows
	/*   3. type=1  hardcoded, so this endpoint CANNOT delete a shift row even
	/*              if handed a shift's id
	/*
	/*   Scope 3 is the one delete-event.php lacks — see
	/*   GOAT-Calendar-Endpoints-Handover.md item 1, which is blocked there
	/*   because the legacy calendar may legitimately delete shifts. A NEW
	/*   endpoint has no such consumer, so it can be strict from birth.
	*/

	$userID = goat_acting_user_id();

	/*
	/* Matches MAX_DAYS in add-events-bulk.php: a pattern cannot create more
	/* rows than that, so it can never need to delete more in one call.
	*/

	$MAX_IDS = 400;

	if ($_SERVER['REQUEST_METHOD'] !== 'POST')
	{
		http_response_code(405);
		die('{"error":"POST required"}');
	}

	$raw = file_get_contents('php://input');
	if ($raw === false || $raw === '')
	{
		http_response_code(400);
		die('{"error":"Empty body"}');
	}

	$payload = json_decode($raw, true);
	if (!is_array($payload))
	{
		http_response_code(400);
		die('{"error":"Invalid JSON"}');
	}

	/*
	/* validate input */

	$ids = isset($payload['ids']) && is_array($payload['ids']) ? $payload['ids'] : array();
	if (count($ids) === 0)
	{
		http_response_code(400);
		die('{"error":"No ids specified"}');
	}
	if (count($ids) > $MAX_IDS)
	{
		http_response_code(400);
		die('{"error":"Too many ids (max '. $MAX_IDS .')"}');
	}

	$clean = array();
	$seen  = array();

	foreach ($ids as $id)
	{
		$n = (int) $id;
		if ($n <= 0)
		{
			http_response_code(400);
			die('{"error":"Invalid id"}');
		}
		if (isset($seen[$n]))
			continue;
		$seen[$n] = true;
		$clean[] = $n;
	}

	/*
	/* process the request */

	$deleted = array();
	$missing = array();

	foreach ($clean as $id)
	{
		/*
		/* intval on the way in above, so $id is an integer literal here. The
		/* user and type clauses are what make this safe — see SAFETY above.
		*/

		$db->delete('calendars', 'id = ' . $id . ' AND user = ' . (int) $userID . ' AND type = 1');

		/*
		/* $db->delete() returns nothing, so the outcome is read here rather
		/* than assumed. mysql_affected_rows() reports on the last query on the
		/* connection; the class opens a single link in connect(), so the bare
		/* call reads the right one.
		/*
		/* 0 rows is a legitimate outcome (already deleted, or not this user's),
		/* which is exactly why it must be distinguishable from 1 — without this
		/* check, a delete that matched nothing would look identical to success.
		*/

		if (mysql_affected_rows() > 0)
			$deleted[] = $id;
		else
			$missing[] = $id;
	}

	echo json_encode(array(
		'ok'      => true,
		'deleted' => $deleted,
		'missing' => $missing
	));

?>
