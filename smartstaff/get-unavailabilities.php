<?php

	/*
	/* global file */

	include('../../global.php');

	/*
	/* use plain text for AJAX */

	header('Content-Type: application/json');

	/*
	/* check user is logged in; if so, get id */

	if (!$user->checkSession())
		die('[]');

	$userID = (int) $_SESSION[SITE_KEY]['userID'];

	/*
	/* process the request
	/*
	/* Returns this user's genuine unavailability periods (type = 1) as JSON,
	/* including the row id and full start/end datetimes so callers can read
	/* hour-level granularity and delete by id.
	/*
	/* This is the query that get-events.php intends to run but cannot, because
	/* its call_crew_map join silently drops every type=1 row.
	*/

	$rows = $db->select(
		'id, title, start, end',
		'calendars',
		'user = ' . $userID . ' AND type = 1',
		'start ASC'
	);

	$out = array();

	if (count($rows) > 0)
	{
		foreach ($rows as $row)
		{
			$out[] = array(
				'id'     => (int) $row->id,
				'title'  => $row->title,
				'start'  => date('c', strtotime($row->start)),
				'end'    => date('c', strtotime($row->end)),
			);
		}
	}

	echo json_encode($out);

?>
