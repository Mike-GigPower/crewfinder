<?php

	/*
	/* CALL-CHANGE ANSWER HISTORY — the one writer for call_change_log.
	/*
	/* Included by respond-to-change.php (crew accept / decline) and
	/* dismiss-change-ack.php (ops dismiss). One INSERT, one place, so the two
	/* callers cannot drift. See MIGRATION-call-change-log.sql for the table and
	/* why it is separate from call_change_ack.
	/*
	/* Uses the house PDO handle (goat_pdo() in goat-db.php), prepared and
	/* bound, rather than the legacy mysql_* connection the callers otherwise
	/* use. Two connections per request is fine; nothing here shares a
	/* transaction with call_crew_map (MyISAM, no transactions anyway).
	/*
	/* NEVER FATAL, NEVER 500. Callers invoke this only AFTER their own answer
	/* has succeeded and passed its error gate. A missing handle, a missing
	/* table (PHP deployed before the migration) or a failed INSERT is logged
	/* with error_log() and returns false; the answer stands, just unlogged.
	/* Same rule as the promo subsume in respond-to-change.php.
	/*
	/* $ack is the call_change_ack row (an object from $db->selectFirst) read
	/* BEFORE the caller deleted it; it supplies the "was" timing.
	/*
	/* PHP 5.x — array(), no ??, no short arrays.
	*/

	include_once(dirname(__FILE__) . '/goat-db.php');

	if (!function_exists('goat_log_change_answer'))
	{
		function goat_log_change_answer($callID, $userID, $ack, $answer, $answerSrc, $priorStatus, $resultStatus)
		{
			$pdo = goat_pdo();

			if (!$pdo)
			{
				error_log('call-change-log: no PDO handle, answer not logged (call ' .
					intval($callID) . ', user ' . intval($userID) . ')');
				return false;
			}

			try
			{
				$st = $pdo->prepare(
					'INSERT INTO call_change_log ' .
					'(callID, userID, prev_start_date, prev_start_time, prev_est_length, changed_at, ' .
					'answer, answer_src, prior_status, result_status, answered_at) ' .
					'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
				);

				$st->execute(array(
					intval($callID),
					intval($userID),
					intval($ack->prev_start_date),
					(string) $ack->prev_start_time,
					(string) $ack->prev_est_length,
					intval($ack->changed_at),
					(string) $answer,
					(string) $answerSrc,
					intval($priorStatus),
					intval($resultStatus),
					time()
				));

				return true;
			}
			catch (Exception $e)
			{
				/* PDOException extends Exception. Logged server-side only. */
				error_log('call-change-log: insert failed (call ' . intval($callID) .
					', user ' . intval($userID) . '): ' . $e->getMessage());
				return false;
			}
		}
	}

?>
