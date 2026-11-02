<?php

	/*
	/* global file */

	include('../../global.php');
	include_once(dirname(__FILE__) . '/../crew/cohort.php');

	/*
	/* JSON for this one — unlike its siblings in this directory, which are
	/* text/plain. Set BEFORE goat_acting_user_id(), which emits a JSON body
	/* with a 401/400 status when it rejects the caller.
	*/

	header('Content-Type: application/json');

	/*
	/* Bulk-create type = 1 (unavailability) rows for ONE crew member.
	/*
	/* Exists because SmartStaff holds a PHP file lock, so Crew Hub must issue
	/* requests one at a time. Materialising a recurring pattern over a 12-month
	/* horizon is ~200 rows; as individual add-event.php calls that is ~100
	/* seconds of sequential HTTP. Here it is one request and one lock.
	/*
	/* ADDITIVE — nothing else calls this. add-event.php, delete-event.php and
	/* get-unavailabilities.php are untouched, so THE GOAT and the legacy browser
	/* calendar are unaffected.
	/*
	/* REQUEST
	/*   POST /ajax/calendar/add-events-bulk.php?userID=123
	/*   X-Goat-Service-Key: <secret>
	/*   Content-Type: application/json
	/*
	/*   { "title": "Uni (recurring)",
	/*     "start_time": "00:00",
	/*     "end_time": "23:59",
	/*     "days": ["2026-08-03", "2026-08-04"] }
	/*
	/*   Every row shares one title and one time-of-day range; only the date
	/*   varies. That is exactly what a weekly pattern produces, and it keeps a
	/*   200-row payload down to a list of date strings.
	/*
	/*   NOTE: userID goes in the QUERY STRING, not the JSON body.
	/*   goat_acting_user_id() reads $_GET / $_POST, and a JSON body populates
	/*   neither. This is the single easiest thing to get wrong here.
	/*
	/* RESPONSE  200
	/*   { "ok": true,
	/*     "created": [ { "day": "2026-08-03", "id": 40122 } ],
	/*     "failed":  [ "2026-08-04" ] }
	/*
	/*   Per-day results, so the caller records exactly what landed. A day in
	/*   "failed" wrote nothing and is safe to retry.
	/*
	/* SAFETY
	/*   - type is hardcoded '1' and never read from input, so this path can
	/*     only ever create unavailability — never fabricate a shift.
	/*   - user comes from goat_acting_user_id(), never from the payload.
	/*   - the client never supplies userID; Crew Hub derives it server-side
	/*     from its own signed session, behind the service key.
	*/

	$userID = goat_acting_user_id();

	/*
	/* Guard rails. MAX_DAYS is a little over one year of a 4-day-a-week
	/* pattern — enough for a 12-month horizon in one call, low enough that a
	/* malformed payload cannot hold the file lock indefinitely.
	*/

	$MAX_DAYS = 400;

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

	$title = isset($payload['title']) ? trim($payload['title']) : '';
	if ($title === '')
	{
		http_response_code(400);
		die('{"error":"No title specified"}');
	}
	if (strlen($title) > 200)
		$title = substr($title, 0, 200);

	$startTime = isset($payload['start_time']) ? trim($payload['start_time']) : '';
	$endTime   = isset($payload['end_time'])   ? trim($payload['end_time'])   : '';

	if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $startTime)
	 || !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $endTime))
	{
		http_response_code(400);
		die('{"error":"start_time and end_time must be HH:MM"}');
	}

	/*
	/* String compare is correct for zero-padded HH:MM, and deliberately uses
	/* >= so a zero-length range is rejected here. Note this is the same class
	/* of check add-event.php gets WRONG via intval() on a date — see
	/* GOAT-Calendar-Endpoints-Handover.md item 3.
	*/
	if ($startTime >= $endTime)
	{
		http_response_code(400);
		die('{"error":"end_time must be after start_time"}');
	}

	$days = isset($payload['days']) && is_array($payload['days']) ? $payload['days'] : array();
	if (count($days) === 0)
	{
		http_response_code(400);
		die('{"error":"No days specified"}');
	}
	if (count($days) > $MAX_DAYS)
	{
		http_response_code(400);
		die('{"error":"Too many days (max '. $MAX_DAYS .')"}');
	}

	/*
	/* Validate every date BEFORE writing anything. A payload with one bad date
	/* is a caller bug, and a half-materialised pattern is worse to reason about
	/* than one that was rejected outright.
	*/

	$clean = array();
	$seen  = array();

	foreach ($days as $day)
	{
		if (!is_string($day) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day))
		{
			http_response_code(400);
			die('{"error":"Invalid date in days"}');
		}

		list($Y, $M, $D) = explode('-', $day);
		if (!checkdate((int) $M, (int) $D, (int) $Y))
		{
			http_response_code(400);
			die('{"error":"Invalid date in days"}');
		}

		/* Duplicates would silently double-write the same day. */
		if (isset($seen[$day]))
			continue;

		$seen[$day] = true;
		$clean[] = $day;
	}

	/*
	/* process the request */

	$created = array();
	$failed  = array();

	foreach ($clean as $day)
	{
		$eventData = array(
			'user'  => (int) $userID,
			'title' => $db->sc($title),
			'start' => $db->sc($day . ' ' . $startTime . ':00'),
			'end'   => $db->sc($day . ' ' . $endTime . ':00'),
			'type'  => '1'
		);

		$db->insert('calendars', $eventData);

		/*
		/* $db->insert() returns nothing and swallows errors, so the write is
		/* checked here rather than assumed. mysql_affected_rows() reports on
		/* the last query on the connection; the class opens a single link via
		/* mysql_connect() in connect(), so the bare call reads the right one.
		/*
		/* Deliberately NOT solved by changing $db->insert() itself — that class
		/* backs every page in SmartStaff, and this endpoint needs the result
		/* from exactly two callers. See GOAT-Calendar-Endpoints-Handover.md
		/* item 2.
		*/

		$rows = mysql_affected_rows();
		$id   = $db->insert_id();

		if ($rows > 0 && $id > 0)
			$created[] = array('day' => $day, 'id' => (int) $id);
		else
			$failed[] = $day;
	}

	echo json_encode(array(
		'ok'      => (count($failed) === 0),
		'created' => $created,
		'failed'  => $failed
	));

?>
