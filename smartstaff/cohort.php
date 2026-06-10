<?php

	/*
	/* THE GOAT — shared cohort resolution for role gating.
	/*
	/* include() this AFTER global.php (it relies on $user, $_SESSION, SITE_KEY
	/* and the active mysql connection that global.php establishes).
	/*
	/* Resolution rule — the SINGLE source of truth (whoami.php calls this):
	/*   usergroupID == 1 (admin login) -> 'admin'  (never read from the column)
	/*   otherwise                      -> users.cohort, restricted to
	/*                                     {leadership, operations, crew},
	/*                                     default 'crew'.
	/*
	/* The cohort column can grant Leadership or Operations but NEVER Admin: a
	/* usergroupID == 3 account can never escalate to full admin via the column.
	/* Tolerates the column being absent (returns 'crew' for any non-admin) so it
	/* is safe to deploy before the migration.
	/*
	/* NOTE: 'cohort' is a shared identity value — it is also read by the Gig
	/* Power website's verify-then-mint flow (via whoami.php). This function
	/* decides only how THE GOAT maps the value to capability; the website maps
	/* the same value to its own privileges. Keep the allow-list and the
	/* canonical (lowercase) spelling in sync across both consumers.
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
				if (isset($row->cohort))
				{
					$c = strtolower(trim($row->cohort));
					if ($c === 'leadership' || $c === 'operations')
						$cohort = $c;
				}
			}

			return $cohort;
		}

		/*
		/* True if the logged-in user may read ALL-crew data (admin, leadership,
		/* OR operations). Use this to gate the bulk read endpoints. WRITE
		/* endpoints must keep their own strict usergroupID == 1 check —
		/* leadership and operations are both read-only in THE GOAT.
		*/
		function goat_can_read_all()
		{
			$c = goat_user_cohort();
			return ($c === 'admin' || $c === 'leadership' || $c === 'operations');
		}
	}

?>