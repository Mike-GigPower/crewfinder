<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* SELF endpoint — returns the acting user's OWN pay weeks (workslips), most
	/* recent first, to build the crew app's "Your Payslips" list. Self-scoped
	/* via goat_acting_user_id() (session OR service key), the same pattern as
	/* my-shifts.php / my-details.php.
	/*
	/* SmartStaff stores payslips as rows in `accounting`, keyed by userID +
	/* week_ending (unix seconds). A user's payslips are the DISTINCT week_ending
	/* values for their userID — mirrors weekly-workslips.php's list query.
	/*
	/* Returns: { "workslips": [ { "week_ending": <unix> }, ... ] }  DESC
	*/

	$userID = goat_acting_user_id();

	$workslipGroups = $db->select(
		'DISTINCT(week_ending) as week_ending',
		'accounting',
		'userID = ' . (int) $userID . ' AND week_ending > 0',
		'week_ending DESC'
	);

	$out = array();
	foreach ($workslipGroups as $g)
	{
		$out[] = array('week_ending' => (int) $g->week_ending);
	}

	echo json_encode(array('workslips' => $out));

?>
