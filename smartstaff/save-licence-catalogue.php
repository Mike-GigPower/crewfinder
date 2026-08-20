<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');
	include('licence-catalogue-lib.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — insert / update / PREVIEW one licence_catalogue row.
	/* Modeled on save-induction-catalogue.php, including the preview=1 branch.
	/*
	/* ADMIN ONLY, deliberately not goat_can_read_all(): the read paths are open to
	/* leadership, operations and the service key, but editing the taxonomy is
	/* editing compliance POLICY for everyone holding that type. Reads stay as they
	/* are; only this write narrows.
	/*
	/* preview=1 computes the impact and writes NOTHING. Two changes need it, and
	/* both look like editing one row while rewriting compliance for every holder:
	/*
	/*   - UNPUBLISHING a code whose expiry_mode != 'none'. licence_expiry_expected()
	/*     in app.py derives from PUBLISHED rows only, so unpublishing flips every
	/*     undated row of that type from 'unknown' to 'na' — the pill goes quiet and
	/*     the crew member silently stops being chased.
	/*   - Changing expiry_mode or validity_months. none->date turns every undated
	/*     holder into 'unknown'; date->period derives an expiry that may contradict
	/*     what is already recorded; a new validity_months re-dates every holder of
	/*     a period type at once.
	/*
	/* Both are the SAME computation — status per holder under the stored row vs
	/* under the proposed one — so there is one preview branch, not two.
	/*
	/* WRITE INVARIANTS, enforced HERE and not only in the UI. MariaDB CHECK
	/* constraints are unevenly enforced across the versions here, and a silent
	/* truncation is worse than a 400:
	/*   - expiry_mode 'period'      -> validity_months a positive int AND
	/*                                  require_certified = 1. A period type with an
	/*                                  optional issue date has nothing to compute from.
	/*   - expiry_mode 'none'/'date' -> validity_months MUST be NULL. Never both a
	/*                                  period and a date; a stale period left on a
	/*                                  'date' type is how it gets applied by accident
	/*                                  the next time the mode changes.
	/*   - code  ^[A-Z0-9]{1,64}$, unique -> 409 on collision.
	/*   - grp   must be one of the groups already in the table. Free text here would
	/*           hand the editor a group-management problem it doesn't need.
	/*
	/* code is SET-ONCE: required on INSERT, IGNORED on UPDATE. It is the value in
	/* user_licenses.type_canonical across every triaged row; renaming it orphans
	/* all of them. Same rule and reasoning as the induction code.
	/*
	/* PHP 5.x — mysql_*, array(), no ??, no short arrays.
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

	/*
	/* Global warn window, mirroring app.py's LICENCE_WARN_DAYS = 60. Per-type warn
	/* days were ruled out deliberately, which is why there is no column for it.
	*/

	$LICENCE_WARN_DAYS = 60;

	/*
	/* One licence row's status, mirroring app.py compliance_status() + the
	/* expiry_mode model:
	/*   period -> the effective expiry is date_certified + N months (DERIVED; the
	/*             recorded date_expiry is not consulted, which is the point of the
	/*             mode). No certified date -> nothing to derive from.
	/*   date   -> the recorded date_expiry.
	/*   none   -> no expiry concept at all.
	/* A missing effective expiry is 'unknown' when the type expects one and 'na'
	/* when it doesn't. $expects folds in `published`, because an unpublished code
	/* is absent from licence_expiry_expected() and therefore expects nothing.
	*/

	function goat_lic_status($dateExpiry, $dateCertified, $mode, $months, $expects, $warnDays, $now)
	{
		$eff = null;

		if ($mode === 'period')
		{
			if ($dateCertified !== null && $dateCertified !== '' && $dateCertified !== '0000-00-00' && $months > 0)
			{
				$certTs = strtotime($dateCertified);

				if ($certTs !== false)
					$eff = strtotime('+' . (int) $months . ' months', $certTs);
			}
		}
		else if ($mode === 'date')
		{
			if ($dateExpiry !== null && $dateExpiry !== '' && $dateExpiry !== '0000-00-00')
			{
				$ts = strtotime($dateExpiry);

				if ($ts !== false)
					$eff = $ts;
			}
		}

		if ($eff === null)
			return $expects ? 'unknown' : 'na';

		$daysLeft = (int) floor(($eff - $now) / 86400);

		if ($daysLeft < 0)
			return 'expired';

		if ($daysLeft <= $warnDays)
			return 'expiring_soon';

		return 'valid';
	}

	/*
	/* Best-first rank when one crew member holds SEVERAL rows for the same code (a
	/* renewal filed alongside the original). Mirrors app.py's _LICENCE_BEST_ORDER —
	/* deliberately NOT the display order — so the preview counts CREW the way the
	/* Crew Finder counts them, not rows.
	*/

	function goat_lic_best_rank($status)
	{
		$order = array('na' => 0, 'valid' => 1, 'expiring_soon' => 2, 'unknown' => 3, 'expired' => 4);

		return isset($order[$status]) ? $order[$status] : 9;
	}

	/*
	/* Biggest movement first — that ordering is the sentence the operator reads,
	/* and a named function rather than a closure because this is PHP 5.x.
	*/

	function goat_lic_change_cmp($a, $b)
	{
		if ($a['count'] === $b['count'])
			return 0;

		return ($a['count'] > $b['count']) ? -1 : 1;
	}

	$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

	/*
	/* Stored row, for both branches: the preview needs it to compute the "before"
	/* side, and the write needs it so an invariant can be checked against the
	/* EFFECTIVE values (posted where posted, stored otherwise) rather than against
	/* a half-supplied form. Read through licence-catalogue-lib.php — one SELECT
	/* against this table, which is the whole point of Phase 1.
	*/

	$stored = null;

	if ($id > 0)
	{
		$allRows = goat_licence_catalogue_rows(true);

		if ($allRows === false)
		{
			http_response_code(500);
			die('{"error":"licence catalogue unavailable"}');
		}

		foreach ($allRows as $r)
		{
			if ((int) $r['id'] === $id)
			{
				$stored = $r;
				break;
			}
		}

		if ($stored === null)
		{
			http_response_code(404);
			die('{"error":"catalogue row not found"}');
		}
	}

	/*
	/* Effective proposed values: posted where posted, stored otherwise. The
	/* invariants are checked against THESE, so posting expiry_mode alone can't
	/* leave a period type with a NULL validity_months.
	*/

	$propMode = isset($_POST['expiry_mode']) ? trim($_POST['expiry_mode'])
	                                         : ($stored !== null ? $stored['expiry_mode'] : 'none');

	if (!in_array($propMode, array('none', 'date', 'period'), true))
	{
		http_response_code(400);
		die('{"error":"expiry_mode must be none, date or period"}');
	}

	if (isset($_POST['validity_months']) && trim($_POST['validity_months']) !== '')
		$propMonths = (int) $_POST['validity_months'];
	else if (isset($_POST['validity_months']))
		$propMonths = 0;                      /* posted blank = cleared */
	else
		$propMonths = ($stored !== null && $stored['validity_months'] !== null) ? (int) $stored['validity_months'] : 0;

	if (isset($_POST['require_certified']))
		$propReqCert = ($_POST['require_certified'] === '1' || $_POST['require_certified'] === 'true') ? 1 : 0;
	else
		$propReqCert = ($stored !== null && $stored['require_certified']) ? 1 : 0;

	if (isset($_POST['published']))
		$propPublished = ($_POST['published'] === '1' || $_POST['published'] === 'true') ? 1 : 0;
	else
		$propPublished = ($stored !== null && !$stored['published']) ? 0 : 1;

	/*
	/* period forces require_certified on. Stated as a rule rather than a
	/* validation error: the editor disables the checkbox for the same reason, and
	/* rejecting the write instead would just be a 400 nobody could act on.
	*/

	if ($propMode === 'period')
		$propReqCert = 1;

	/*
	/* ------------------------------------------------------------------ *
	/* PREVIEW branch — before any write.
	/* ------------------------------------------------------------------ */

	if (isset($_POST['preview']) && $_POST['preview'] === '1')
	{
		if ($id <= 0)
		{
			http_response_code(400);
			die('{"error":"preview requires an existing id"}');
		}

		$codeEsc = mysql_real_escape_string($stored['code']);

		/*
		/* Every row of this code held by ACTIVE CREW, never inductions. The
		/* empty-venue discriminator is the one every licence read uses; the type
		/* check is harmless extra cover.
		*/

		$sql = "SELECT l.`user` AS user_id, l.date_expiry AS date_expiry,
		               l.date_certified AS date_certified
		        FROM user_licenses l
		        INNER JOIN users u ON u.id = l.`user`
		        WHERE u.usergroupID = 3 AND u.active = '1'
		          AND l.type_canonical = '" . $codeEsc . "'
		          AND (l.venue IS NULL OR l.venue = 0 OR l.venue = '')
		          AND l.`type` != 'Induction Certificate'";

		$res = mysql_query($sql);

		if ($res === false)
		{
			http_response_code(500);
			die('{"error":"preview query failed: ' . addslashes(mysql_error()) . '"}');
		}

		$now = time();

		$oldMode      = $stored['expiry_mode'];
		$oldMonths    = ($stored['validity_months'] !== null) ? (int) $stored['validity_months'] : 0;
		$oldExpects   = ($oldMode !== 'none') && $stored['published'];
		$newExpects   = ($propMode !== 'none') && ($propPublished === 1);

		/*
		/* Aggregate to ONE status per crew member (best row wins), so the counts
		/* read as people rather than rows.
		*/

		$bestOld = array();
		$bestNew = array();

		while ($row = mysql_fetch_object($res))
		{
			$uid = (int) $row->user_id;

			$old = goat_lic_status($row->date_expiry, $row->date_certified, $oldMode, $oldMonths, $oldExpects, $LICENCE_WARN_DAYS, $now);
			$new = goat_lic_status($row->date_expiry, $row->date_certified, $propMode, $propMonths, $newExpects, $LICENCE_WARN_DAYS, $now);

			if (!isset($bestOld[$uid]) || goat_lic_best_rank($old) < goat_lic_best_rank($bestOld[$uid]))
				$bestOld[$uid] = $old;

			if (!isset($bestNew[$uid]) || goat_lic_best_rank($new) < goat_lic_best_rank($bestNew[$uid]))
				$bestNew[$uid] = $new;
		}

		$holders = count($bestOld);

		/*
		/* `undated` is who is being CHASED right now — crew whose current best
		/* status is 'unknown'. Deliberately not "rows with no date": a crew member
		/* holding one dated row and one blank one is not being chased, and on an
		/* unpublish it is the chased ones who go quiet. This is the number the
		/* §0a confirm quotes, so it has to mean exactly that.
		*/

		$undated = 0;

		foreach ($bestOld as $uid => $old)
		{
			if ($old === 'unknown')
				$undated++;
		}

		$fromCounts = array();
		$toCounts   = array();
		$changes    = array();

		foreach ($bestOld as $uid => $old)
		{
			$new = isset($bestNew[$uid]) ? $bestNew[$uid] : $old;

			$fromCounts[$old] = (isset($fromCounts[$old]) ? $fromCounts[$old] : 0) + 1;
			$toCounts[$new]   = (isset($toCounts[$new]) ? $toCounts[$new] : 0) + 1;

			if ($new === $old)
				continue;

			$key = $old . '>' . $new;

			if (!isset($changes[$key]))
				$changes[$key] = array('from' => $old, 'to' => $new, 'count' => 0);

			$changes[$key]['count']++;
		}

		$changeList = array_values($changes);

		usort($changeList, 'goat_lic_change_cmp');

		echo json_encode(array(
			'ok'      => true,
			'preview' => true,
			'code'    => $stored['code'],
			'name'    => $stored['name'],
			'holders' => $holders,
			'undated' => $undated,
			'from'    => (object) $fromCounts,
			'to'      => (object) $toCounts,
			'changes' => $changeList
		));

		exit;
	}

	/*
	/* ------------------------------------------------------------------ *
	/* WRITE path — INSERT (id <= 0) or UPDATE (id > 0).
	/* ------------------------------------------------------------------ */

	$isInsert = ($id <= 0);
	$set      = array();

	/*
	/* code — set-once. Required on INSERT, uppercased and unique-checked. Any
	/* code posted on UPDATE is IGNORED, never written.
	*/

	if ($isInsert)
	{
		$code = isset($_POST['code']) ? strtoupper(trim($_POST['code'])) : '';

		if ($code === '')
		{
			http_response_code(400);
			die('{"error":"code required"}');
		}

		if (!preg_match('/^[A-Z0-9]{1,64}$/', $code))
		{
			http_response_code(400);
			die('{"error":"code must be 1-64 characters, letters and digits only"}');
		}

		$codeEsc = mysql_real_escape_string($code);

		$dup = mysql_query("SELECT id FROM `licence_catalogue` WHERE `code` = '" . $codeEsc . "' LIMIT 1");

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

		$set[] = "`code` = '" . $codeEsc . "'";
	}

	if (isset($_POST['name']))
	{
		$name = trim($_POST['name']);

		if ($name === '')
		{
			http_response_code(400);
			die('{"error":"name required"}');
		}

		$set[] = "`name` = '" . mysql_real_escape_string($name) . "'";
	}
	else if ($isInsert)
	{
		http_response_code(400);
		die('{"error":"name required"}');
	}

	/*
	/* grp — must already exist in the table. A new group would need an ordering
	/* decision (sort_order is group-major), and this editor has no place to make
	/* one, so it offers a select rather than free text.
	*/

	if (isset($_POST['grp']) || $isInsert)
	{
		$grp     = isset($_POST['grp']) ? trim($_POST['grp']) : '';
		$allRows = goat_licence_catalogue_rows(true);

		if ($allRows === false)
		{
			http_response_code(500);
			die('{"error":"licence catalogue unavailable"}');
		}

		$known = array();

		foreach ($allRows as $r)
		{
			if (!in_array($r['grp'], $known, true))
				$known[] = $r['grp'];
		}

		if (!in_array($grp, $known, true))
		{
			http_response_code(400);
			die('{"error":"group must be one of the existing groups"}');
		}

		$set[] = "`grp` = '" . mysql_real_escape_string($grp) . "'";
	}

	/*
	/* The expiry triple is written TOGETHER whenever any part of it is posted, so
	/* the row can never be left half-consistent — a 'period' with a NULL month
	/* count, or a 'date' still carrying a stale period.
	*/

	if (isset($_POST['expiry_mode']) || isset($_POST['validity_months']) || isset($_POST['require_certified']) || $isInsert)
	{
		if ($propMode === 'period')
		{
			if ($propMonths <= 0)
			{
				http_response_code(400);
				die('{"error":"a fixed-period licence needs a positive number of months"}');
			}

			$set[] = "`expiry_mode` = 'period'";
			$set[] = "`validity_months` = " . (int) $propMonths;
			$set[] = "`require_certified` = 1";
		}
		else
		{
			$set[] = "`expiry_mode` = '" . mysql_real_escape_string($propMode) . "'";
			$set[] = "`validity_months` = NULL";
			$set[] = "`require_certified` = " . ($propReqCert ? 1 : 0);
		}
	}

	if (isset($_POST['published']) || $isInsert)
		$set[] = "`published` = " . ($propPublished ? 1 : 0);

	if (isset($_POST['sort_order']))
		$set[] = "`sort_order` = " . (int) $_POST['sort_order'];

	if (isset($_POST['notes']))
		$set[] = "`notes` = '" . mysql_real_escape_string(trim($_POST['notes'])) . "'";

	if (empty($set))
	{
		http_response_code(400);
		die('{"error":"no fields to update"}');
	}

	if ($isInsert)
	{
		mysql_query("INSERT INTO `licence_catalogue` SET " . implode(", ", $set));

		if (mysql_error() !== '')
		{
			http_response_code(500);
			die('{"error":"insert failed: ' . addslashes(mysql_error()) . '"}');
		}

		$id = (int) mysql_insert_id();
	}
	else
	{
		mysql_query("UPDATE `licence_catalogue` SET " . implode(", ", $set) . " WHERE `id` = " . $id);

		if (mysql_error() !== '')
		{
			http_response_code(500);
			die('{"error":"update failed: ' . addslashes(mysql_error()) . '"}');
		}
	}

	echo json_encode(array('ok' => true, 'id' => (int) $id));

?>
