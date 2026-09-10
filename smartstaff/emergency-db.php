<?php

	/*
	/* THE PDO HANDLE for the crew_emergency_* tables. ONE definition of the
	/* connection settings, because three of them are load-bearing and four
	/* copies only have to drift once to matter:
	/*
	/*   EMULATE_PREPARES => false   PHP 5.6's PDO MySQL driver emulates
	/*                               prepares BY DEFAULT, which interpolates
	/*                               client-side and throws away most of the
	/*                               reason for using PDO here.
	/*   ERRMODE_EXCEPTION           PDO's default on 5.6 is SILENT. A failed
	/*                               write to an audit table must not be
	/*                               something a caller has to remember to ask
	/*                               about.
	/*   charset=utf8mb4             the database default is latin1; without
	/*                               this the connection inherits it and the
	/*                               utf8mb4 tables are pointless.
	/*
	/* Proved on the test box 10 Sep 2026: PHP 5.6.40, nd_pdo_mysql, connected
	/* to 11.4.13-MariaDB, EMULATE_PREPARES reporting 0, connection charset
	/* utf8mb4, 4-byte UTF-8 surviving a bound parameter round trip.
	/*
	/* THE LEGACY mysql_* CONNECTION IS UNTOUCHED. global.php opens it, the
	/* session and cohort helpers use it, and this opens a second one. Two
	/* connections per request is fine on 5.6 and nothing here shares a
	/* transaction with call_crew_map.
	/*
	/* NOTE ON RETURNED TYPES: this driver returns STRINGS for numerics even
	/* with emulation off -- the spike got '42' back from a bound int. Cast
	/* every integer field with (int) on the way out, exactly as the mysql_*
	/* house rule already requires.
	*/

	/*
	/* Credential resolution. config.php holds them; its variable and constant
	/* names have not been pinned down, so the same candidate-name resolver the
	/* slice 0 spike used is reused here -- it is the logic that actually
	/* connected on this box, rather than names guessed from memory.
	/*
	/* TO COLLAPSE THIS TO THREE LINES: define GOAT_EMERGENCY_DB_USER,
	/* GOAT_EMERGENCY_DB_PASS and GOAT_EMERGENCY_DB_NAME (in the gitignored
	/* goat-service-key.php, alongside the service secret) and the resolver is
	/* skipped entirely. Worth doing once somebody has read config.php and can
	/* name them.
	*/

	if (!function_exists('goat_emergency_find_setting'))
	{
		function goat_emergency_find_setting($names)
		{
			foreach ($names as $n)
			{
				if (defined($n))
				{
					$v = constant($n);
					if (is_string($v) && $v !== '') return $v;
				}
			}

			foreach ($names as $n)
			{
				$k = strtolower($n);

				foreach (array($k, str_replace('_', '', $k)) as $cand)
				{
					if (isset($GLOBALS[$cand])
					    && is_string($GLOBALS[$cand])
					    && $GLOBALS[$cand] !== '')
					{
						return $GLOBALS[$cand];
					}
				}
			}

			return null;
		}
	}

	/*
	/* Returns a configured PDO handle, or null if it cannot connect.
	/*
	/* NULL, NOT AN EXCEPTION, and never a fatal: an endpoint that cannot reach
	/* these tables must return a clean JSON error, not a stack trace or a
	/* white page. The caller checks and calls goat_json_error().
	/*
	/* Memoised per request -- several endpoints read and then write.
	*/

	if (!function_exists('goat_emergency_pdo'))
	{
		function goat_emergency_pdo()
		{
			static $pdo = false;

			if ($pdo !== false)
			{
				return $pdo;   /* a previous null is cached too, deliberately */
			}

			$host = goat_emergency_find_setting(array(
				'GOAT_EMERGENCY_DB_HOST','DB_HOST','DBHOST','MYSQL_HOST','SQL_HOST','HOST'));
			$user = goat_emergency_find_setting(array(
				'GOAT_EMERGENCY_DB_USER','DB_USER','DBUSER','DB_USERNAME','MYSQL_USER','SQL_USER','USER'));
			$pass = goat_emergency_find_setting(array(
				'GOAT_EMERGENCY_DB_PASS','DB_PASS','DBPASS','DB_PASSWORD','DBPASSWORD','MYSQL_PASS','SQL_PASS','PASS','PASSWORD'));
			$name = goat_emergency_find_setting(array(
				'GOAT_EMERGENCY_DB_NAME','DB_NAME','DBNAME','MYSQL_DB','SQL_DB','DB_DATABASE','DATABASE'));

			if ($host === null) { $host = 'localhost'; }

			if ($user === null || $pass === null || $name === null)
			{
				error_log('emergency-db: could not resolve DB credentials from config.php');
				$pdo = null;
				return $pdo;
			}

			try
			{
				$pdo = new PDO(
					'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4',
					$user,
					$pass,
					array(
						PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
						PDO::ATTR_EMULATE_PREPARES   => false,
						PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
					)
				);
			}
			catch (PDOException $e)
			{
				/* The message can carry the DSN and therefore the db user.
				/* Logged server-side, never returned to a caller. */
				error_log('emergency-db: connect failed: ' . $e->getMessage());
				$pdo = null;
			}

			return $pdo;
		}
	}

	/*
	/* The declared-condition vocabulary, in ONE place. The CHECK constraint on
	/* crew_emergency_entry.category carries the same list; if these disagree
	/* the database wins and the caller gets a 500 it cannot explain, so they
	/* move together or not at all.
	*/

	if (!function_exists('goat_emergency_categories'))
	{
		function goat_emergency_categories()
		{
			return array('auto_injector','seizures','diabetes','asthma','cardiac','other');
		}
	}
