<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');
	include('licence-catalogue-lib.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — deletes one licence_catalogue row (admin-gated, POST-only).
	/* Modeled on delete-induction-catalogue.php and delete-venue.php: a GUARDED
	/* HARD DELETE.
	/*
	/* GUARD: refuse if ANY user_licenses row carries this code in type_canonical.
	/* Deleting would orphan every one of them — the code would still be stored on
	/* the row while resolving to nothing in the catalogue, which is precisely the
	/* state §6 of the Phase 0 migration checks for and expects to be empty.
	/* n > 0 -> 409 {"error":"in use","count":n}, and the UI offers Unpublish, which
	/* is the reversible action that achieves what the operator actually wanted.
	/*
	/* The guard counts ROWS ACROSS ALL USERS, not just active crew — an inactive
	/* crew member's licence row is orphaned just as thoroughly as an active one's,
	/* and this is a data-integrity guard rather than a staffing question. That is
	/* deliberately a WIDER net than the holder counts shown in the editor list,
	/* which are about who a search could return.
	/*
	/* Only a code nothing points at deletes outright.
	/*
	/* Delete success gated on mysql_error(), never affected_rows.
	*/

	if (goat_user_cohort() !== 'admin')
	{
		http_response_code(403);
		die('{"error":"Admin only"}');
	}

	if ($_SERVER['REQUEST_METHOD'] !== 'POST')
	{
		http_response_code(405);
		die('{"error":"POST required"}');
	}

	$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

	if ($id <= 0)
	{
		http_response_code(400);
		die('{"error":"id required"}');
	}

	/*
	/* Confirm the row exists, and get its code for the guard. Read through the
	/* shared lib — one SELECT against this table.
	*/

	$allRows = goat_licence_catalogue_rows(true);

	if ($allRows === false)
	{
		http_response_code(500);
		die('{"error":"licence catalogue unavailable"}');
	}

	$row = null;

	foreach ($allRows as $r)
	{
		if ((int) $r['id'] === $id)
		{
			$row = $r;
			break;
		}
	}

	if ($row === null)
	{
		http_response_code(404);
		die('{"error":"catalogue row not found"}');
	}

	$codeEsc = mysql_real_escape_string($row['code']);

	/* DEPENDENCY GUARD: any licence row carrying this code blocks the delete. */

	$g = mysql_query("SELECT COUNT(*) AS n
	                  FROM user_licenses
	                  WHERE type_canonical = '" . $codeEsc . "'");

	if ($g === false)
	{
		http_response_code(500);
		die('{"error":"in-use check failed: ' . addslashes(mysql_error()) . '"}');
	}

	$grow  = mysql_fetch_object($g);
	$count = (int) $grow->n;

	if ($count > 0)
	{
		http_response_code(409);
		die(json_encode(array(
			'error' => 'in use',
			'count' => $count,
			'code'  => $row['code']
		)));
	}

	mysql_query("DELETE FROM `licence_catalogue` WHERE `id` = " . $id);

	if (mysql_error() !== '')
	{
		http_response_code(500);
		die('{"error":"delete failed: ' . addslashes(mysql_error()) . '"}');
	}

	echo json_encode(array(
		'ok'      => true,
		'id'      => $id,
		'code'    => $row['code'],
		'deleted' => 'catalogue'
	));

?>
