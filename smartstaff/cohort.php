<?php

	/*
	/* THE GOAT — shared identity / access helpers for role gating and the
	/* service-scoped (Crew API) access path.
	/*
	/* include() this AFTER global.php (relies on $user, $_SESSION, SITE_KEY and
	/* the active mysql connection global.php establishes).
	/*
	/* Cohort rule — the SINGLE source of truth (whoami.php and
	/* verify-credentials.php both resolve through goat_cohort_for_user()):
	/*   usergroupID == 1 -> 'admin'  (never read from the column)
	/*   otherwise        -> users.cohort restricted to {leadership, operations,
	/*                       crew}, default 'crew'.
	/* The column can grant Leadership/Operations but NEVER Admin. Tolerates the
	/* column being absent (non-admin -> 'crew'), so it is safe pre-migration.
	/*
	/* 'cohort' is a shared identity value — also read by the Gig Power crew
	/* app's verify-then-mint flow. This file decides only how THE GOAT maps the
	/* value to capability; keep the allow-list and lowercase spelling in sync.
	*/

	/*
	/* Crew API service key — kept in a gitignored file so the secret never
	/* enters the repo. Absent file -> service path disabled (no fatal), so the
	/* code is safe to deploy before the key lands.
	*/
	if (!defined('GOAT_SERVICE_KEY'))
	{
		$goat_key_file = dirname(__FILE__) . '/goat-service-key.php';
		if (file_exists($goat_key_file))
			include($goat_key_file);
	}

	if (!function_exists('goat_user_cohort'))
	{
		/*
		/* Resolve ANY userID to a cohort — the authority for the rule above.
		/* Two small PK lookups so the cohort column can be absent without
		/* breaking admin resolution (usergroupID always exists).
		*/
		function goat_cohort_for_user($userID)
		{
			$userID = (int) $userID;
			if ($userID <= 0)
				return 'crew';

			$res = mysql_query("SELECT usergroupID FROM users WHERE id = $userID LIMIT 1");
			if ($res === false || mysql_num_rows($res) == 0)
				return 'crew';

			$row = mysql_fetch_object($res);
			if ((int) $row->usergroupID == 1)
				return 'admin';

			/* cohort column: leadership/operations only; default crew.
			/* Tolerates the column being absent (query fails -> stays crew). */
			$cohort = 'crew';
			$cres = mysql_query("SELECT cohort FROM users WHERE id = $userID LIMIT 1");
			if ($cres !== false && mysql_num_rows($cres) > 0)
			{
				$crow = mysql_fetch_object($cres);
				if (isset($crow->cohort))
				{
					$c = strtolower(trim($crow->cohort));
					if ($c === 'leadership' || $c === 'operations')
						$cohort = $c;
				}
			}

			return $cohort;
		}

		/*
		/* Resolve the logged-in (session) user's cohort. null if no session.
		/* Delegates to goat_cohort_for_user() so the rule lives in one place.
		*/
		function goat_user_cohort()
		{
			global $user;

			if (!isset($user) || !$user->checkSession())
				return null;  /* not logged in */

			return goat_cohort_for_user((int) $_SESSION[SITE_KEY]['userID']);
		}

		/*
		/* True if the logged-in user may read ALL-crew data (admin, leadership,
		/* OR operations). Gate bulk read endpoints on this. WRITE endpoints keep
		/* their own strict usergroupID == 1 check.
		*/
		function goat_can_read_all()
		{
			/* 1. logged-in SmartStaff session with a read-all cohort
			/*    (admin / leadership / operations) — THE GOAT desktop path. */
			$c = goat_user_cohort();
			if ($c === 'admin' || $c === 'leadership' || $c === 'operations')
				return true;

			/* 2. service path: the trusted Gig Power crew portal backend presents
			/*    the Crew API service secret. The portal has already verified the
			/*    signed-in user is admin/leadership/operations server-side (via
			/*    requireCohort) BEFORE calling, and the key is a backend-only
			/*    secret that never reaches a browser — so the secret grants
			/*    read-all here, mirroring goat_acting_user_id()'s service path.
			/*    READ-only endpoints only; write endpoints keep their own strict
			/*    usergroupID == 1 check. */
			$key = isset($_SERVER['HTTP_X_GOAT_SERVICE_KEY'])
			     ? $_SERVER['HTTP_X_GOAT_SERVICE_KEY'] : '';
			if (goat_service_key_ok($key))
				return true;

			return false;
		}

		/*
		/* Constant-time string compare — hash_equals() where available (5.6+),
		/* manual fallback for 5.4/5.5.
		*/
		function goat_hash_equals($known, $provided)
		{
			if (function_exists('hash_equals'))
				return hash_equals($known, $provided);

			if (!is_string($known) || !is_string($provided))
				return false;
			$len = strlen($known);
			if (strlen($provided) !== $len)
				return false;
			$diff = 0;
			for ($i = 0; $i < $len; $i++)
				$diff |= (ord($known[$i]) ^ ord($provided[$i]));
			return $diff === 0;
		}

		/*
		/* True if the supplied value matches the Crew API service secret. False
		/* whenever the key file is absent or either side is empty — the service
		/* path stays closed unless explicitly enabled.
		*/
		function goat_service_key_ok($provided)
		{
			if (!defined('GOAT_SERVICE_KEY'))
				return false;
			$known = GOAT_SERVICE_KEY;
			if (!is_string($provided) || $provided === '' || $known === '')
				return false;
			return goat_hash_equals($known, $provided);
		}

		/*
		/* Resolve the acting userID for a self-scoped endpoint. Two trust paths:
		/*   1. a logged-in SmartStaff session -> THE GOAT desktop crew view
		/*   2. the Crew API service secret     -> the userID the backend asserts
		/*                                         (derived from a verified JWT)
		/* The CLIENT never supplies userID; only the trusted backend does, behind
		/* the secret. Emits JSON + status and exits on failure (callers set
		/* Content-Type: application/json first, as the self-endpoints already do).
		*/
		function goat_acting_user_id()
		{
			global $user;

			if (isset($user) && $user->checkSession())
				return (int) $_SESSION[SITE_KEY]['userID'];

			$key = isset($_SERVER['HTTP_X_GOAT_SERVICE_KEY'])
			     ? $_SERVER['HTTP_X_GOAT_SERVICE_KEY'] : '';

			if (goat_service_key_ok($key))
			{
				$uid = 0;
				if (isset($_GET['userID']))
					$uid = (int) $_GET['userID'];
				else if (isset($_POST['userID']))
					$uid = (int) $_POST['userID'];

				if ($uid <= 0)
				{
					http_response_code(400);
					die('{"error":"userID required"}');
				}
				return $uid;
			}

			http_response_code(401);
			die('{"error":"Not authorised"}');
		}
	}

	if (!function_exists('goat_json_error'))
	{
		/*
		/* Emit a JSON error body and exit. Use this instead of hand-building a
		/* JSON string around mysql_error().
		/*
		/* The idiom this replaces —
		/*     die('{"error":"write failed: ' . addslashes(mysql_error()) . '"}');
		/* — produces INVALID JSON whenever the message contains an apostrophe,
		/* because addslashes() emits \' and that is not a JSON escape sequence.
		/* Nearly every informative MySQL error quotes an identifier ("Duplicate
		/* entry '5925' for key 'PRIMARY'"), so the client's JSON.parse throws
		/* and the operator sees a parser complaint instead of the cause.
		/* Connection-level errors carry no apostrophe and pass through intact,
		/* so the failure is selective in the worst direction: the useless
		/* errors survive and the useful ones do not.
		/*
		/* json_encode() also escapes control characters and backslashes, which
		/* addslashes() leaves raw.
		/*
		/* Fails to a static valid body if json_encode() itself returns false
		/* (invalid UTF-8 in the message; PHP 5.5+). The client must ALWAYS be
		/* able to parse a failure — an unparseable error is worse than a vague
		/* one.
		/*
		/* http_response_code() rather than a local send_status(): it is the
		/* idiom in 71 of 91 endpoints, and this file already relies on it in
		/* goat_acting_user_id().
		/*
		/* PHP 5.x: array() syntax, no null-coalescing.
		*/
		function goat_json_error($code, $message)
		{
			http_response_code((int) $code);
			$body = json_encode(array('error' => $message));
			if ($body === false)
				$body = '{"error":"request failed"}';
			die($body);
		}
	}

	if (!function_exists('goat_ccm_cancel_paid_sql'))
	{
		/*
		/* THE PAID-CANCELLATION TEST (BRIEF-late-cancellation-pay, D13/D14).
		/* A call_crew_map row is paid (and charged) as if the call went ahead
		/* when it was CONFIRMED at the moment of cancelling and ops answered
		/* Yes to either question:
		/*
		/*   status = 9 AND prev_status = 5 AND (cancel_pay = 1 OR cancel_charge = 1)
		/*
		/* Derived, never stored. THIS IS THE ONLY COPY in THE GOAT's PHP --
		/* every endpoint that asks the question builds its SQL from here. The
		/* one deliberate duplicate is the widening in SmartStaff's own
		/* classes/class.accounting.php, which does not include this file; if
		/* the rule changes, change both.
		/*
		/* $alias is the table alias to qualify with ('ccm', 'call_crew_map'),
		/* or '' for unqualified columns. Returns a parenthesised fragment.
		/*
		/* cancel_pay exists only after MIGRATION-late-cancellation-pay.sql.
		/* Callers that must degrade before the migration check
		/* goat_ccm_have_cancel_pay() first; this function does not.
		*/
		function goat_ccm_cancel_paid_sql($alias)
		{
			$p = ($alias === '' || $alias === null) ? '' : ($alias . '.');

			return '(' . $p . 'status = 9 AND ' . $p . 'prev_status = 5 AND ('
			     . $p . 'cancel_pay = 1 OR ' . $p . 'cancel_charge = 1))';
		}

		/*
		/* MAY TIMES BE KEYED ON THIS ROW? The paid test, MINUS calls SETTLED
		/* UNDER THE OLD RULE.
		/*
		/* A call is settled under the old rule when
		/*   cancel_times_confirmed_at = cancelled_at
		/* -- the migration's backfill writes exactly that on every call
		/* cancelled before this feature, and cancel-call.php writes it on a
		/* legacy (chargeable-only) request. Those calls were never asked the
		/* pay question, but their Confirmed rows still pass the paid test
		/* through cancel_charge = 1. Nobody may key times on them that would
		/* pay crew under the new rule.
		/*
		/* Rich's own done-tick (update-call-times.php) writes time() at save,
		/* which is never the cancel instant, so a call he has checked stays
		/* editable for corrections.
		/*
		/* Needs calls joined: $ccmAlias for call_crew_map, $callAlias for
		/* calls. Use this for ANY "can times be entered / is this row unlocked"
		/* question -- update-call-times.php, get-call-times.php and
		/* get-booking.php's cancel_paid. The bare paid test stays for
		/* questions about the cancellation itself (uncancel-call.php's reset,
		/* the ops lane's count, class.accounting.php).
		*/
		function goat_ccm_cancel_editable_sql($ccmAlias, $callAlias)
		{
			$c = $callAlias . '.';

			return '(' . goat_ccm_cancel_paid_sql($ccmAlias)
			     . ' AND NOT (' . $c . 'cancel_times_confirmed_at IS NOT NULL AND '
			     . $c . 'cancel_times_confirmed_at = ' . $c . 'cancelled_at))';
		}

		/*
		/* Has the late-cancellation migration run here? Checks BOTH tables'
		/* new columns, because the paid test and the done-tick need all three.
		/* Static per request.
		*/
		function goat_ccm_have_cancel_pay()
		{
			static $has = null;

			if ($has === null)
			{
				$r1  = mysql_query("SHOW COLUMNS FROM call_crew_map LIKE 'cancel_pay'");
				$r2  = mysql_query("SHOW COLUMNS FROM calls LIKE 'cancel_times_confirmed_at'");
				$has = ($r1 !== false && mysql_num_rows($r1) > 0 &&
				        $r2 !== false && mysql_num_rows($r2) > 0);
			}

			return $has;
		}
	}

	if (!function_exists('goat_cancel_prefill_window'))
	{
		/*
		/* THE LATE-CANCELLATION PRE-FILL -- one implementation, used by
		/* cancel-call.php at cancel time and by oneoff-late-cancel-pay.php for
		/* the calls cancelled before it existed.
		/*
		/* goat_cancel_prefill_window($call) -> array('on' => 'HH:MM:SS',
		/* 'off' => 'HH:MM:SS'), or null when the call has no usable start.
		/* $call needs start_date, start_time and est_length.
		/*
		/* Same arithmetic as goat_call_window() (resolve-call-contact.php): the
		/* start instant through strtotime(), the end as start + seconds, so the
		/* two Melbourne DST transitions are handled by PHP rather than by SQL
		/* time arithmetic, which is an hour wrong on those days.
		/*
		/* est_length is DECIMAL HOURS (8.5 = 8h30m) and 0 occurs on prod, so the
		/* 4-hour minimum is a floor under it, not a top-up on top of it. `off`
		/* may roll past midnight; generateCallData reads off < on as next day.
		/*
		/* No start_date, no window: a guessed date is worse than none, and Rich
		/* keys the times by hand.
		*/
		function goat_cancel_prefill_window($call)
		{
			if ((int) $call->start_date <= 0)
				return null;

			$startTs = strtotime(date('Y-m-d', (int) $call->start_date) . ' ' . $call->start_time);

			if ($startTs === false)
				return null;

			$hours = max((double) $call->est_length, 4.0);
			$endTs = $startTs + (int) round($hours * 3600);

			return array('on' => date('H:i:s', $startTs), 'off' => date('H:i:s', $endTs));
		}

		/*
		/* Are a row's times still blank? `on` and `off` are `time NOT NULL`, so
		/* blank is 00:00:00; '' is tolerated for the same reason
		/* goat_time_is_set() tolerates it.
		*/
		function goat_ccm_times_blank($on, $off)
		{
			$on  = (string) $on;
			$off = (string) $off;

			return ($on  === '' || $on  === '00:00:00') &&
			       ($off === '' || $off === '00:00:00');
		}

		/*
		/* Write the pre-fill onto ONE stood-down row: on/off from the window,
		/* breaks zero. Times only -- callpaygradeID and the four rate columns
		/* are untouched, so the crew member is paid at the grade they were
		/* booked on.
		/*
		/* NEVER OVERWRITES. The blank test is in the WHERE as well as in the
		/* callers, so real times (a call stood down mid-shift, or times Rich
		/* keyed by hand) survive even a race. Reserved words backticked.
		/*
		/* Returns true when this call wrote the row, false when the row was
		/* not blank (or not status 9), or the mysql_error() string on failure.
		*/
		function goat_cancel_prefill_row($callID, $userID, $win)
		{
			$on  = mysql_real_escape_string($win['on']);
			$off = mysql_real_escape_string($win['off']);

			$ok = mysql_query(
				"UPDATE call_crew_map
				    SET `on` = '" . $on . "', `off` = '" . $off . "',
				        `break` = '00:00:00', `break_night` = '00:00:00'
				  WHERE callID = " . (int) $callID . "
				    AND userID = " . (int) $userID . "
				    AND status = 9
				    AND `on`  = '00:00:00'
				    AND `off` = '00:00:00'"
			);

			$err = mysql_error();

			if ($ok === false || $err !== '')
				return ($err !== '') ? $err : 'pre-fill write failed';

			return (mysql_affected_rows() > 0);
		}
	}

?>