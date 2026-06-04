<?php

	/*
	/* THE GOAT — shared cohort resolution for role gating.
	/*
	/* include() this AFTER global.php (it relies on $user, $_SESSION, SITE_KEY
	/* and the active mysql connection that global.php establishes).
	/*
	/* Resolution rule — IDENTICAL to whoami.php:
	/*   usergroupID == 1 (admin login) -> 'admin'  (never read from the column)
	/*   otherwise                      -> users.cohort, restricted to
	/*                                     {leadership, crew}, default 'crew'.
	/*
	/* The cohort column can grant Leadership but NEVER Admin: a usergroupID == 3
	/* account can never escalate to full admin via the column. Tolerates the
	/* column being absent (returns 'crew' for any non-admin) so it is safe to
	/* deploy before the migration.
	*/

	if (!function_exists('goat_user_cohort'))
	{
		function goat_user_cohort()
		{
			global $user;

			if (!isset($user) || !$user->checkSession())
				return null;  /* not logged in */

			if ((int) $user->info->usergroupID == 1)
				return 'admin';

			$userID = (int) $_SESSION[SITE_KEY]['userID'];
			$cohort = 'crew';

			$res = mysql_query("SELECT cohort FROM users WHERE id = $userID LIMIT 1");
			if ($res !== false && mysql_num_rows($res) > 0)
			{
				$row = mysql_fetch_object($res);
				if (isset($row->cohort) && strtolower(trim($row->cohort)) == 'leadership')
					$cohort = 'leadership';
			}

			return $cohort;
		}

		/*
		/* True if the logged-in user may read ALL-crew data (admin OR
		/* leadership). Use this to gate the bulk read endpoints. WRITE
		/* endpoints must keep their own strict usergroupID == 1 check —
		/* Leadership is read-only.
		*/
		function goat_can_read_all()
		{
			$c = goat_user_cohort();
			return ($c === 'admin' || $c === 'leadership');
		}
	}

?>
