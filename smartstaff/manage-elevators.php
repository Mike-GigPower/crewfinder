<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN-ONLY — manage the GOAT admin-elevation EIN allow-list.
	/* The list only controls whether THE GOAT shows the step-up "Admin" button
	/* to a logged-in crew/operations user; it grants nothing on its own —
	/* elevation still requires authenticating a real usergroupID == 1 account.
	/* Managing it is therefore gated to admins (usergroupID == 1).
	*/

	if (goat_user_cohort() !== 'admin')
	{
		http_response_code(403);
		die('{"error":"Admin only"}');
	}

	$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'list';
	$ein    = isset($_REQUEST['ein'])    ? (int) $_REQUEST['ein'] : 0;

	/*
	/* ADD — only EINs that belong to a crew (usergroupID == 3) record may be
	/* added, so a typo can't seed a phantom EIN. Elevators are operations by
	/* policy, so the same action also promotes the user to the operations
	/* cohort — the list and the base cohort are set together and can't drift.
	*/

	if ($action === 'add')
	{
		if ($ein <= 0)
		{
			http_response_code(400);
			die('{"error":"missing or invalid ein"}');
		}

		$chk = mysql_query("SELECT id FROM users WHERE ein = $ein AND usergroupID = 3 LIMIT 1");
		if ($chk === false || mysql_num_rows($chk) == 0)
		{
			http_response_code(404);
			die('{"error":"no crew record with that EIN"}');
		}

		$added_by = (int) $_SESSION[SITE_KEY]['userID'];
		$added_at = time();

		/* PRIMARY KEY(ein) makes a repeat add a harmless no-op */
		mysql_query("INSERT IGNORE INTO goat_elevators (ein, added_by, added_at)
		             VALUES ($ein, $added_by, $added_at)");

		/* Grant the operations base view in the same action. Scoped to the
		/* crew (usergroupID == 3) record so a same-EIN admin row is untouched. */
		mysql_query("UPDATE users SET cohort = 'operations'
		             WHERE ein = $ein AND usergroupID = 3");

		echo json_encode(array('ok' => true, 'action' => 'add', 'ein' => $ein));
		return;
	}

	/*
	/* REMOVE — drops the elevate grant only. The user's cohort is left as-is
	/* (reverting someone to plain crew is a deliberate decision, not a side
	/* effect of removing elevate access).
	*/

	if ($action === 'remove')
	{
		if ($ein <= 0)
		{
			http_response_code(400);
			die('{"error":"missing or invalid ein"}');
		}

		mysql_query("DELETE FROM goat_elevators WHERE ein = $ein");
		echo json_encode(array('ok' => true, 'action' => 'remove', 'ein' => $ein));
		return;
	}

	/*
	/* LIST (default) — EINs with a best-effort crew name for display. The name
	/* subquery is pinned to usergroupID == 3 so it resolves the crew record,
	/* not a same-EIN admin row.
	*/

	$sql = "
		SELECT e.ein,
		       e.added_by,
		       e.added_at,
		       (SELECT TRIM(CONCAT(TRIM(u.lastname), ', ', TRIM(u.firstname)))
		          FROM users u
		         WHERE u.ein = e.ein AND u.usergroupID = 3
		         LIMIT 1) AS name
		FROM goat_elevators e
		ORDER BY name ASC, e.ein ASC
	";

	$res = mysql_query($sql);

	$elevators = array();
	if ($res !== false)
	{
		while ($r = mysql_fetch_object($res))
		{
			$elevators[] = array(
				'ein'      => (int) $r->ein,
				'name'     => isset($r->name) ? $r->name : '',
				'added_by' => (int) $r->added_by,
				'added_at' => (int) $r->added_at,
			);
		}
	}

	echo json_encode(array('elevators' => $elevators));

?>
