<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');
	include(dirname(__FILE__) . '/goat-db.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — EVERYONE's contract row from user_documents, in one
	/* query, for the Contract column on Crew › Records
	/* (BRIEF-records-contract-column.md). The per-person read is
	/* admin-get-documents.php; this is its bulk sibling so Records does not make
	/* one SmartStaff call per crew member (per-session PHP lock — they queue).
	/*
	/* ADMIN ONLY, the same gate as admin-get-documents.php: an employment
	/* agreement is admin-only wherever it surfaces. Records is already
	/* admin-only, so nothing new is hidden or exposed.
	/*
	/* UNIQUE(user, doc_type) means at most one contract row per person, so the
	/* map below never has to choose between two.
	/*
	/* Response: {"ok":true,"contracts":{"<user id>":{signed_at, version, has_pdf}}}
	/*   - keyed by user id; a person with no contract is simply absent
	/*   - signed_at and version pass through as-is INCLUDING null — a contract
	/*     with no recorded signing date is a real state and must not be dressed
	/*     up as a date (same rule as admin-get-documents.php)
	/*   - the filename is NEVER returned, only has_pdf (same rule, same reason:
	/*     user_uploads/ is flat and every name is guessable {user}_{time}.pdf)
	/*   - (object) on emit so an empty result is {} not [] (same note as
	/*     list-crew-bulk.php's induction_policy)
	/*
	/* Read-only. PHP 5.x — array(), no ??, no short arrays, tabs. PDO via
	/* goat_pdo().
	*/

	if (goat_user_cohort() !== 'admin')
	{
		goat_json_error(403, 'Admin only');
		exit;
	}

	$pdo = goat_pdo();

	if ($pdo === null)
	{
		goat_json_error(503, 'Database handle unavailable');
		exit;
	}

	$contracts = array();

	try
	{
		$stmt = $pdo->prepare(
			"SELECT `user`, `signed_at`, `version`,
			        (`pdf_file` IS NOT NULL AND `pdf_file` <> '') AS has_pdf
			 FROM `user_documents`
			 WHERE `doc_type` = 'contract'"
		);
		$stmt->execute();

		while ($r = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$contracts[(int) $r['user']] = array(
				'signed_at' => $r['signed_at'],
				'version'   => $r['version'],
				'has_pdf'   => ((int) $r['has_pdf'] === 1)
			);
		}

		$stmt->closeCursor();
	}
	catch (PDOException $e)
	{
		error_log('admin-list-contracts-bulk: ' . $e->getMessage());
		goat_json_error(500, 'contracts query failed');
		exit;
	}

	echo json_encode(array('ok' => true, 'contracts' => (object) $contracts));

?>
