<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* READ-ALL endpoint -- crew_groups master list for filter UIs
	/* (Crew Finder group chips). Same gate as the bulk roster endpoint.
	*/

	if (!goat_can_read_all())
	{
		http_response_code(403);
		die('{"error":"forbidden"}');
	}

	$out = array();
	$res = mysql_query("SELECT id, group_name FROM crew_groups ORDER BY group_name ASC");
	if ($res !== false)
	{
		while ($row = mysql_fetch_object($res))
			$out[] = array('id' => (int) $row->id, 'name' => $row->group_name);
	}

	echo json_encode(array('groups' => $out));

?>
