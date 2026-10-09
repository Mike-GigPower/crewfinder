<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');
	include(dirname(__FILE__) . '/crewlist-sent-lib.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — undo the current crew-list mark on one call
	/* (BRIEF-crewlist-sent §A2, D10).
	/*
	/*   POST { "call_id": 39050 }
	/*   -> { "ok": true, "call_id": 39050, "state": "none" | "sent" | "changed" }
	/*
	/* A SOFT DELETE. The newest live row (undone_at IS NULL) gets undone_at /
	/* undone_by stamped; nothing is deleted, so "who marked it, who took it
	/* back" survives. The previous live send, if any, becomes current again —
	/* which is why `state` is the call's state AFTER the undo, worked out by
	/* the same crewlist-sent-lib.php comparison get-crewlist-status.php uses.
	/*
	/* No live mark on the call -> 404, nothing written.
	/*
	/* The UPDATE re-checks undone_at IS NULL, so two undos racing for the same
	/* row stamp it once; the loser gets 409 rather than silently undoing the
	/* send underneath it.
	/*
	/* Gate: admin only, the same gate as marking (D10).
	/*
	/* PHP 5.x — array(), no ??, no short arrays, tabs. PDO via goat_pdo().
	*/

	if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST')
	{
		goat_json_error(405, 'POST only');
		exit;
	}

	if (goat_user_cohort() !== 'admin')
	{
		goat_json_error(403, 'Admin only');
		exit;
	}

	$actorID = isset($_SESSION[SITE_KEY]['userID']) ? (int) $_SESSION[SITE_KEY]['userID'] : 0;

	if ($actorID <= 0)
	{
		goat_json_error(401, 'Not authenticated');
		exit;
	}

	/* JSON via php://input, the house convention for GOAT write endpoints. */
	$raw  = file_get_contents('php://input');
	$body = json_decode($raw, true);

	if (!is_array($body) || !isset($body['call_id']))
	{
		goat_json_error(400, 'Body must be a JSON object with call_id');
		exit;
	}

	$v = $body['call_id'];
	$callID = 0;

	if (is_int($v))
		$callID = $v;
	else if (is_string($v) && preg_match('/^[0-9]+$/', $v))
		$callID = (int) $v;

	if ($callID <= 0)
	{
		goat_json_error(400, 'call_id must be a positive integer');
		exit;
	}

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		goat_json_error(503, 'Database handle unavailable');
		exit;
	}

	try
	{
		/* the newest live row */
		$stmt = $pdo->prepare(
			"SELECT id, bookingID
			 FROM call_crewlist_sent
			 WHERE callID = :call_id
			   AND undone_at IS NULL
			 ORDER BY id DESC
			 LIMIT 1");
		$stmt->bindValue(':call_id', $callID, PDO::PARAM_INT);
		$stmt->execute();
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$stmt->closeCursor();

		if (!$row)
		{
			goat_json_error(404, 'call ' . $callID . ' has no crew list marked sent');
			exit;
		}

		$stmt = $pdo->prepare(
			"UPDATE call_crewlist_sent
			 SET undone_at = :now, undone_by = :actor
			 WHERE id = :id
			   AND undone_at IS NULL");
		$stmt->bindValue(':now',   time(),             PDO::PARAM_INT);
		$stmt->bindValue(':actor', $actorID,           PDO::PARAM_INT);
		$stmt->bindValue(':id',    (int) $row['id'],   PDO::PARAM_INT);
		$stmt->execute();

		if ($stmt->rowCount() !== 1)
		{
			goat_json_error(409, 'that mark was already undone');
			exit;
		}

		/* state AFTER the undo, by the shared comparison. Only this call's
		/* booking is loaded; a cancelled call is absent and reads as none. */
		$calls = goat_crewlist_calls_of_bookings($pdo, array((int) $row['bookingID']));
		$only  = isset($calls[$callID]) ? array($callID => $calls[$callID]) : array();
		$st    = goat_crewlist_states($pdo, $only);
	}
	catch (PDOException $e)
	{
		error_log('undo-crewlist-sent: ' . $e->getMessage());
		goat_json_error(500, 'could not undo crew list sent');
		exit;
	}

	echo json_encode(array(
		'ok'      => true,
		'call_id' => $callID,
		'state'   => isset($st[$callID]) ? $st[$callID]['state'] : 'none'
	));

?>
