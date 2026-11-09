<?php

	/*
	/* LINK BUTTON WRITES — shared by auto-link-supervision.php and
	/* no-crew-boss.php (BRIEF-default-link-self-submit-off, Release 2, B3/B4).
	/*
	/* An include, not an endpoint: it does nothing on its own. The callers
	/* include global.php, cohort.php, goat-db.php and supervision-graph.php
	/* first, gate on goat_user_cohort() === 'admin' (J18), and pass in the
	/* goat_pdo() handle.
	/*
	/* WRITES GO THROUGH PDO (goat_pdo(): prepared statements, ERRMODE_EXCEPTION,
	/* no emulated prepares), the style of the newest endpoint in the folder,
	/* set-team-lead.php. Success is judged on the caught PDOException and then
	/* a read-back of the row — never on rowCount(). The READS that decide what
	/* to write go through supervision-graph.php's mysql_* helpers, as in
	/* set-team-lead.php: two connections, no shared transaction, which MyISAM
	/* could not give anyway.
	/*
	/* Each function returns '' on success or a short error for the caller to
	/* report against that one call. None of them dies: a reply is per call
	/* and a partial failure never hides the successes.
	/*
	/* PHP 5.x — array(), no ??, no short array syntax.
	*/

	if (!function_exists('goat_lb_link'))
	{

		/*
		/* Link $childCall to $bossCall, made by the button (auto = 1).
		/*
		/* The SAME upsert as call-supervision.php `set` — UNIQUE (child_call),
		/* so a relink (J17) replaces the row to the cancelled boss call in one
		/* statement, with no window in which the call has no row — plus `auto`.
		/* created / created_by record the current grant, as there. The caller
		/* then clears any "No Crew Boss" marker with goat_lb_unmark() ("linking
		/* wins") — separately, so a link that saved is never reported as
		/* failed because the marker didn't clear.
		*/

		function goat_lb_link($pdo, $bookingID, $bossCall, $childCall, $actor, $now)
		{
			$auto = goat_sg_have_supervision_auto();

			try
			{
				$sql = "INSERT INTO `call_supervision`
				        (`booking_id`, `boss_call`, `child_call`, `created`, `created_by`" . ($auto ? ", `auto`" : "") . ")
				        VALUES (?, ?, ?, ?, ?" . ($auto ? ", 1" : "") . ")
				        ON DUPLICATE KEY UPDATE
				          `boss_call`  = VALUES(`boss_call`),
				          `booking_id` = VALUES(`booking_id`),
				          `created`    = VALUES(`created`),
				          `created_by` = VALUES(`created_by`)" . ($auto ? ",
				          `auto`       = VALUES(`auto`)" : "");

				$st = $pdo->prepare($sql);
				$st->execute(array((int) $bookingID, (int) $bossCall, (int) $childCall, (int) $now, (int) $actor));

				$vs = $pdo->prepare("SELECT `boss_call` FROM `call_supervision` WHERE `child_call` = ?");
				$vs->execute(array((int) $childCall));
				$row = $vs->fetch();
			}
			catch (PDOException $e)
			{
				error_log('link-button: link ' . (int) $childCall . ': ' . $e->getMessage());
				return 'The link didn\'t save.';
			}

			if (!$row || (int) $row['boss_call'] !== (int) $bossCall)
			{
				return 'The link didn\'t take effect.';
			}

			return '';
		}

		/* Mark $callID "No Crew Boss" (insert or replace), by $actor. */

		function goat_lb_mark($pdo, $callID, $actor, $now)
		{
			if (!goat_sg_have_no_crew_boss())
			{
				return 'The No Crew Boss table isn\'t on this server yet (MIGRATION-link-button.sql).';
			}

			try
			{
				$st = $pdo->prepare("INSERT INTO `call_no_crew_boss` (`callID`, `set_by`, `set_at`)
				                     VALUES (?, ?, ?)
				                     ON DUPLICATE KEY UPDATE
				                       `set_by` = VALUES(`set_by`),
				                       `set_at` = VALUES(`set_at`)");
				$st->execute(array((int) $callID, (int) $actor, (int) $now));

				$vs = $pdo->prepare("SELECT `callID` FROM `call_no_crew_boss` WHERE `callID` = ?");
				$vs->execute(array((int) $callID));
				$row = $vs->fetch();
			}
			catch (PDOException $e)
			{
				error_log('link-button: mark ' . (int) $callID . ': ' . $e->getMessage());
				return 'The No Crew Boss mark didn\'t save.';
			}

			return $row ? '' : 'The No Crew Boss mark didn\'t take effect.';
		}

		/* Remove any "No Crew Boss" marker on $callID. No marker is not an error. */

		function goat_lb_unmark($pdo, $callID)
		{
			if (!goat_sg_have_no_crew_boss())
			{
				return '';   /* before the migration nothing can be marked */
			}

			try
			{
				$st = $pdo->prepare("DELETE FROM `call_no_crew_boss` WHERE `callID` = ?");
				$st->execute(array((int) $callID));

				$vs = $pdo->prepare("SELECT `callID` FROM `call_no_crew_boss` WHERE `callID` = ?");
				$vs->execute(array((int) $callID));
				$row = $vs->fetch();
			}
			catch (PDOException $e)
			{
				error_log('link-button: unmark ' . (int) $callID . ': ' . $e->getMessage());
				return 'The No Crew Boss mark didn\'t clear.';
			}

			return $row ? 'The No Crew Boss mark didn\'t clear.' : '';
		}

		/* dedupe and cast a JSON array of ids; anything else is an empty list */

		function goat_lb_ids($v)
		{
			return is_array($v) ? goat_sg_clean_ids($v) : array();
		}

		/* A call name as THE GOAT shows it: users and calls store some names HTML-encoded. */

		function goat_lb_name($s)
		{
			return html_entity_decode((string) $s, ENT_QUOTES);
		}
	}

?>
