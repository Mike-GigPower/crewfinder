<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — deletes one CONTACT (users, usergroup 4). Admin-gated,
	/* POST-only, guarded to usergroupID == 4 so it can NEVER remove a crew or admin
	/* row (mirrors update-contact.php). GUARDED HARD DELETE: blocks if the contact
	/* is referenced by bookings.userID or bookings.onsiteUserID (both hold contact
	/* ids). Auto-cleans the contact's own customer_map join rows. The final DELETE
	/* re-asserts usergroupID = 4 as belt-and-braces. Gated on mysql_error().
	/*
	/* Dependency surface fully scanned: customers.user_id is usergroup 42 (not a
	/* contact) so no block needed there; sms_sent/sms_reply orphans are cosmetic
	/* and left intact (optional cleanup marked below). This is the everyday-rare
	/* path — deactivate (active='0' via update-contact.php) is the normal removal.
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

	/* Confirm the target is a contact (usergroupID 4). */
	$chk = mysql_query("SELECT usergroupID FROM users WHERE id = " . $id . " LIMIT 1");

	if ($chk === false)
	{
		http_response_code(500);
		die('{"error":"lookup failed: ' . addslashes(mysql_error()) . '"}');
	}

	if (mysql_num_rows($chk) == 0)
	{
		http_response_code(404);
		die('{"error":"contact not found"}');
	}

	$chkrow = mysql_fetch_object($chk);

	if ((int) $chkrow->usergroupID !== 4)
	{
		http_response_code(403);
		die('{"error":"not a contact record"}');
	}

	/* DEPENDENCY GUARD: bookings reference contacts in two columns. */
	$bk = mysql_query("SELECT COUNT(*) AS n FROM bookings WHERE userID = " . $id . " OR onsiteUserID = " . $id);
	if ($bk === false)
	{
		http_response_code(500);
		die('{"error":"booking check failed: ' . addslashes(mysql_error()) . '"}');
	}
	$bkrow    = mysql_fetch_object($bk);
	$bookings = (int) $bkrow->n;

	if ($bookings > 0)
	{
		http_response_code(409);
		die(json_encode(array(
			'error'    => "Can't delete — " . $bookings . " booking(s) still linked. Set inactive instead?",
			'bookings' => $bookings
		)));
	}

	/* Auto-clean the contact's customer links (pure joins). */
	mysql_query("DELETE FROM customer_map WHERE userID = " . $id);

	if (mysql_error() !== '')
	{
		http_response_code(500);
		die('{"error":"customer-link cleanup failed: ' . addslashes(mysql_error()) . '"}');
	}

	$unlinked = mysql_affected_rows();

	/* OPTIONAL — SMS log cleanup. Left OFF by default: orphaned sms rows are
	/* cosmetic (blank in any join), and deleting comms history is a bigger
	/* statement than tidiness warrants. To enable, uncomment:
	/*
	/* mysql_query("DELETE FROM sms_sent  WHERE userID = " . $id);
	/* mysql_query("DELETE FROM sms_reply WHERE userID = " . $id);
	*/

	/* Delete the contact. usergroupID = 4 re-asserted as a safety net. */
	mysql_query("DELETE FROM users WHERE id = " . $id . " AND usergroupID = 4");

	if (mysql_error() !== '')
	{
		http_response_code(500);
		die('{"error":"delete failed: ' . addslashes(mysql_error()) . '"}');
	}

	echo json_encode(array(
		'ok'                 => true,
		'id'                 => $id,
		'deleted'            => 'contact',
		'customers_unlinked' => $unlinked
	));

?>
