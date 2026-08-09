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

?>