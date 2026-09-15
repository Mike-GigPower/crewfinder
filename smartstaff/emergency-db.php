<?php

	/*
	/* CREW EMERGENCY CARD — the PDO handle (by alias) and the declared-condition
	/* vocabulary.
	/*
	/* The connection settings, their rationale, the credential resolver and the
	/* (int)-cast note all moved to goat-db.php. They are NOT repeated here: two
	/* copies of a rationale is how one of them ends up describing code that no
	/* longer exists.
	*/

	/*
	/* THE HANDLE ITSELF NOW LIVES IN goat-db.php — extracted 14 Sep 2026 when
	/* the licence register became the second feature to want it. Behaviour is
	/* unchanged; goat_pdo() is the same function under an honest name, with the
	/* same credential resolver and the same null-not-exception contract.
	/*
	/* goat_emergency_pdo() is KEPT as an alias because four endpoints call it
	/* by that name -- emergency-get.php, emergency-flags.php,
	/* emergency-my-log.php and emergency-set.php. Renaming them is a bigger,
	/* riskier change than keeping one line, and this is a compliance surface.
	*/

	include(dirname(__FILE__) . '/goat-db.php');

	if (!function_exists('goat_emergency_pdo'))
	{
		function goat_emergency_pdo()
		{
			return goat_pdo();
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
