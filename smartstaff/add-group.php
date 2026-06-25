<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint -- create a crew group in crew_groups. Admin-gated.
	/* Returns {ok, id, name} (or existed:true if the name already exists,
	/* case-insensitive). Surfaces mysql_error() so an unexpected NOT NULL
	/* column shows up plainly rather than silently failing.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		http_response_code(403);
		die('{"error":"Admin only"}');
	}

	$name = isset($_POST['name']) ? trim($_POST['name']) : '';

	if ($name === '')
	{
		http_response_code(400);
		die('{"error":"group name required"}');
	}

	$esc = mysql_real_escape_string($name);

	/* refuse a duplicate (case-insensitive) -- return the existing row instead */
	$dup = mysql_query("SELECT id FROM crew_groups WHERE LOWER(group_name) = LOWER('" . $esc . "') LIMIT 1");
	if ($dup !== false && mysql_num_rows($dup) > 0)
	{
		$row = mysql_fetch_object($dup);
		echo json_encode(array('ok' => true, 'id' => (int) $row->id, 'name' => $name, 'existed' => true));
		exit;
	}

	mysql_query("INSERT INTO crew_groups (group_name) VALUES ('" . $esc . "')");
	if (mysql_error() !== '')
	{
		http_response_code(500);
		die('{"error":"insert failed: ' . addslashes(mysql_error()) . '"}');
	}

	echo json_encode(array('ok' => true, 'id' => (int) mysql_insert_id(), 'name' => $name));

?>
