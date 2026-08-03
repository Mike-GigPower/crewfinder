<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — writes one induction catalogue row (insert OR update),
	/* rewrites its covered venues, or computes a re-date PREVIEW without writing.
	/* Admin-gated, POST-only. Modeled on update-venue.php (partial-write: only
	/* fields actually present in the POST are written) plus delete-venue.php's
	/* dependency-aware idiom for the covers conflict guard.
	/*
	/* Three modes, chosen in order:
	/*   preview=1  -> compute the status delta a validity_months change would
	/*                 cause across crew who hold this induction, WRITE NOTHING.
	/*   id <= 0    -> INSERT a new catalogue row (code required, set-once).
	/*   id  > 0    -> UPDATE the existing row (code is never rewritten).
	/*
	/* Covers (venue_induction_covers) are rewritten in the same request when
	/* venue_ids is present. The conflict check runs BEFORE the row write and
	/* surfaces the UNIQUE(venue_id) as a clean 409 before #1062 — so a colliding
	/* INSERT never happens and leaves no orphan catalogue row (empty row + a
	/* consumed unique code). Only once covers are clear is the row written, then
	/* DELETE+re-INSERT replaces the set. Not transactional (MyISAM), but a
	/* conflict leaves the DB exactly as it was pre-request.
	/*
	/* Write success is gated on mysql_error(), NEVER affected_rows (which is 0 on
	/* a no-op save where the submitted values equal what is already stored).
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

	$WARN_DAYS_DEFAULT = 14;

	/*
	/* Unified induction status rule — the SAME arithmetic every other surface
	/* uses (get-induction-catalogue.php's consumers, the reminder cron):
	/*   days   = round(months / 12 * 365)
	/*   expiry = complete_date + days * 86400   (complete_date is a Unix ts)
	/*   now >= expiry                 -> 'expired'
	/*   now >= expiry - warn * 86400  -> 'expiring'
	/*   otherwise                     -> 'complete'
	*/
	function goat_ind_status($completeDate, $months, $warnDays, $now)
	{
		$days   = (int) round(($months / 12.0) * 365);
		$expiry = (int) $completeDate + ($days * 86400);

		if ($now >= $expiry)
			return 'expired';

		if ($now >= $expiry - ($warnDays * 86400))
			return 'expiring';

		return 'complete';
	}

	$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

	/*
	/* ------------------------------------------------------------------ *
	/* PREVIEW branch — before any write. Needs an existing row (for the
	/* stored validity_months + warn_days) and the proposed validity_months.
	/* ------------------------------------------------------------------ */

	if (isset($_POST['preview']) && $_POST['preview'] === '1')
	{
		if ($id <= 0)
		{
			http_response_code(400);
			die('{"error":"preview requires an existing id"}');
		}

		if (!isset($_POST['validity_months']) || $_POST['validity_months'] === '')
		{
			http_response_code(400);
			die('{"error":"preview requires validity_months"}');
		}

		$prow = mysql_query("SELECT validity_months, warn_days FROM venue_induction_catalogue WHERE id = " . $id . " LIMIT 1");

		if ($prow === false)
		{
			http_response_code(500);
			die('{"error":"lookup failed: ' . addslashes(mysql_error()) . '"}');
		}

		if (mysql_num_rows($prow) == 0)
		{
			http_response_code(404);
			die('{"error":"catalogue row not found"}');
		}

		$prowObj = mysql_fetch_object($prow);

		/* old status uses the stored validity; new uses the proposed one.
		/* warn resolves from the row, or the global default when NULL. */
		$oldMonths = ($prowObj->validity_months !== null) ? (int) $prowObj->validity_months : 12;
		$newMonths = (int) $_POST['validity_months'];
		$warnDays  = ($prowObj->warn_days !== null) ? (int) $prowObj->warn_days : $WARN_DAYS_DEFAULT;

		$sql = "SELECT cvi.crew_id AS crew_id, cvi.complete_date AS complete_date
		        FROM crew_venue_induction cvi
		        JOIN venue_induction_covers cov ON cov.venue_id = cvi.venue_id
		        WHERE cov.catalogue_id = " . $id . "
		          AND cvi.complete_date IS NOT NULL
		          AND cvi.complete_date > 0";

		$res = mysql_query($sql);

		if ($res === false)
		{
			http_response_code(500);
			die('{"error":"preview query failed: ' . addslashes(mysql_error()) . '"}');
		}

		$now         = time();
		$total       = 0;
		$to_expired  = 0;
		$to_expiring = 0;
		$to_complete = 0;

		while ($row = mysql_fetch_object($res))
		{
			$total++;

			$cd  = (int) $row->complete_date;
			$old = goat_ind_status($cd, $oldMonths, $warnDays, $now);
			$new = goat_ind_status($cd, $newMonths, $warnDays, $now);

			if ($new === $old)
				continue;

			/* count only rows whose status CHANGES to the new value */
			if ($new === 'expired')
				$to_expired++;
			else if ($new === 'expiring')
				$to_expiring++;
			else if ($new === 'complete')
				$to_complete++;
		}

		echo json_encode(array(
			'preview'     => true,
			'total'       => $total,
			'to_expired'  => $to_expired,
			'to_expiring' => $to_expiring,
			'to_complete' => $to_complete
		));

		exit;
	}

	/*
	/* ------------------------------------------------------------------ *
	/* WRITE path — INSERT (id <= 0) or UPDATE (id > 0).
	/* ------------------------------------------------------------------ */

	$isInsert    = ($id <= 0);
	$storedMonths = null;   /* stored validity_months, for the change stamp on UPDATE */

	if (!$isInsert)
	{
		$chk = mysql_query("SELECT id, validity_months FROM venue_induction_catalogue WHERE id = " . $id . " LIMIT 1");

		if ($chk === false)
		{
			http_response_code(500);
			die('{"error":"lookup failed: ' . addslashes(mysql_error()) . '"}');
		}

		if (mysql_num_rows($chk) == 0)
		{
			http_response_code(404);
			die('{"error":"catalogue row not found"}');
		}

		$chkRow = mysql_fetch_object($chk);
		$storedMonths = ($chkRow->validity_months !== null) ? (int) $chkRow->validity_months : null;
	}

	/*
	/* ------------------------------------------------------------------ *
	/* COVERS conflict check — BEFORE any row write, so a colliding insert
	/* never leaves an orphan catalogue row (empty "monitors nobody" row +
	/* a now-consumed unique code the user can't resubmit). Parse venue_ids
	/* up front; the DELETE+re-INSERT replace runs later once the row exists.
	/*
	/* thisId sentinel: on INSERT the row has no id yet, so exclude a value no
	/* real row can have (0). `cov.catalogue_id <> 0` then treats EVERY existing
	/* cover on an incoming venue as a conflict — exactly right for a new row.
	/* On UPDATE it is the real id, so the row's own covers are excluded.
	/* ------------------------------------------------------------------ */

	$hasVenueIds = isset($_POST['venue_ids']);
	$venueIds    = array();

	if ($hasVenueIds)
	{
		/* accept comma-separated OR repeated venue_ids[]. */
		$rawIds = $_POST['venue_ids'];
		$parts  = is_array($rawIds) ? $rawIds : explode(',', $rawIds);

		foreach ($parts as $p)
		{
			$vid = (int) trim($p);
			if ($vid > 0 && !in_array($vid, $venueIds))
				$venueIds[] = $vid;
		}

		if (!empty($venueIds))
		{
			$conflictExclId = $isInsert ? 0 : $id;
			$inList         = implode(',', $venueIds);

			$csql = "SELECT cov.venue_id AS venue_id, c.title AS title
			         FROM venue_induction_covers cov
			         JOIN venue_induction_catalogue c ON c.id = cov.catalogue_id
			         WHERE cov.venue_id IN (" . $inList . ")
			           AND cov.catalogue_id <> " . $conflictExclId;

			$cres = mysql_query($csql);

			if ($cres === false)
			{
				http_response_code(500);
				die('{"error":"covers conflict check failed: ' . addslashes(mysql_error()) . '"}');
			}

			$conflicts = array();

			while ($crow = mysql_fetch_object($cres))
			{
				$conflicts[] = array(
					'venue_id' => (int) $crow->venue_id,
					'title'    => $crow->title
				);
			}

			if (!empty($conflicts))
			{
				http_response_code(409);
				die(json_encode(array(
					'error'     => 'venue already assigned',
					'conflicts' => $conflicts
				)));
			}
		}
	}

	$set = array();

	/*
	/* code — set-once. Required on INSERT (uppercased, unique-checked). On
	/* UPDATE any posted code is IGNORED, never rewritten.
	*/
	if ($isInsert)
	{
		$code = isset($_POST['code']) ? strtoupper(trim($_POST['code'])) : '';

		if ($code === '')
		{
			http_response_code(400);
			die('{"error":"code required"}');
		}

		$codeEsc = mysql_real_escape_string($code);

		$dup = mysql_query("SELECT id FROM venue_induction_catalogue WHERE code = '" . $codeEsc . "' LIMIT 1");

		if ($dup === false)
		{
			http_response_code(500);
			die('{"error":"code check failed: ' . addslashes(mysql_error()) . '"}');
		}

		if (mysql_num_rows($dup) > 0)
		{
			http_response_code(409);
			die('{"error":"code in use"}');
		}

		$set[] = "code = '" . $codeEsc . "'";
	}

	/* title — text, quoted. */
	if (isset($_POST['title']))
		$set[] = "title = '" . mysql_real_escape_string(trim($_POST['title'])) . "'";

	/* validity_months — INT. Stamp validity_changed_at when it actually changes
	/* (UPDATE only; on INSERT there is no stored value to differ from). */
	if (isset($_POST['validity_months']) && $_POST['validity_months'] !== '')
	{
		$vm = (int) $_POST['validity_months'];
		$set[] = "validity_months = " . $vm;

		if (!$isInsert && $vm !== $storedMonths)
			$set[] = "validity_changed_at = NOW()";
	}

	/* warn_days — present-but-empty is an explicit SQL NULL (inherit the global
	/* default downstream); do NOT default to 14 here. */
	if (isset($_POST['warn_days']))
	{
		if ($_POST['warn_days'] !== '')
			$set[] = "warn_days = " . (int) $_POST['warn_days'];
		else
			$set[] = "warn_days = NULL";
	}

	/* show_in_checker / published — INT flags, only 1 or 0. */
	if (isset($_POST['show_in_checker']))
		$set[] = "show_in_checker = " . (($_POST['show_in_checker'] === '1') ? 1 : 0);

	if (isset($_POST['published']))
		$set[] = "published = " . (($_POST['published'] === '1') ? 1 : 0);

	/* crew_note / ops_note — free text, quoted. */
	if (isset($_POST['crew_note']))
		$set[] = "crew_note = '" . mysql_real_escape_string(trim($_POST['crew_note'])) . "'";

	if (isset($_POST['ops_note']))
		$set[] = "ops_note = '" . mysql_real_escape_string(trim($_POST['ops_note'])) . "'";

	/* links — JSON string, stored verbatim as text (quoted + escaped). */
	if (isset($_POST['links']))
		$set[] = "links = '" . mysql_real_escape_string(trim($_POST['links'])) . "'";

	/*
	/* match_keywords — comma text. Normalised to a trimmed, lowercase,
	/* comma-joined list so the read endpoint's split-on-comma has exactly one
	/* canonical shape. A comma inside a single keyword is unsupported by design.
	*/
	if (isset($_POST['match_keywords']))
	{
		$rawKw = trim($_POST['match_keywords']);
		$clean = array();

		if (strlen($rawKw) > 0)
		{
			$parts = explode(',', $rawKw);

			foreach ($parts as $p)
			{
				$k = strtolower(trim($p));
				if (strlen($k) > 0)
					$clean[] = $k;
			}
		}

		$set[] = "match_keywords = '" . mysql_real_escape_string(implode(',', $clean)) . "'";
	}

	/* sort_order — INT. */
	if (isset($_POST['sort_order']) && $_POST['sort_order'] !== '')
		$set[] = "sort_order = " . (int) $_POST['sort_order'];

	/*
	/* Run the row write. INSERT always has at least `code` in $set. UPDATE may
	/* have an empty $set when only venue_ids changed — that is legal, skip the
	/* row write but still process covers below.
	*/
	if ($isInsert)
	{
		$sql = "INSERT INTO venue_induction_catalogue SET " . implode(", ", $set);
		mysql_query($sql);

		if (mysql_error() !== '')
		{
			http_response_code(500);
			die('{"error":"insert failed: ' . addslashes(mysql_error()) . '"}');
		}

		$id = (int) mysql_insert_id();
	}
	else if (!empty($set))
	{
		$sql = "UPDATE venue_induction_catalogue SET " . implode(", ", $set) . " WHERE id = " . $id;
		mysql_query($sql);

		if (mysql_error() !== '')
		{
			http_response_code(500);
			die('{"error":"update failed: ' . addslashes(mysql_error()) . '"}');
		}
	}
	else if (!$hasVenueIds)
	{
		/* nothing to write and no covers to touch */
		http_response_code(400);
		die('{"error":"no fields to update"}');
	}

	/*
	/* ------------------------------------------------------------------ *
	/* COVERS replace — the conflict was already cleared BEFORE the row write
	/* (above), so no orphan is possible. Present-but-empty clears all covers
	/* for this row; present-with-ids replaces the set. Absent leaves covers
	/* untouched. $id is now the real row id (post-insert on the insert path).
	/* ------------------------------------------------------------------ */

	if ($hasVenueIds)
	{
		/* Replace: clear this row's covers, then re-insert the incoming set. */
		mysql_query("DELETE FROM venue_induction_covers WHERE catalogue_id = " . $id);

		if (mysql_error() !== '')
		{
			http_response_code(500);
			die('{"error":"covers clear failed: ' . addslashes(mysql_error()) . '"}');
		}

		foreach ($venueIds as $vid)
		{
			mysql_query("INSERT INTO venue_induction_covers (catalogue_id, venue_id) VALUES (" . $id . ", " . $vid . ")");

			if (mysql_error() !== '')
			{
				http_response_code(500);
				die('{"error":"covers insert failed: ' . addslashes(mysql_error()) . '"}');
			}
		}
	}

	echo json_encode(array(
		'ok' => true,
		'id' => (int) $id
	));

?>
