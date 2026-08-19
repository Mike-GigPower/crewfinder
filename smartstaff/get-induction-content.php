<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* CREW-SCOPED endpoint — induction reference content for the crew portal.
	/*
	/* Sibling to get-induction-catalogue.php, deliberately NOT the same endpoint.
	/* That one is gated on goat_can_read_all(), whose service-key branch is
	/* justified by the portal having already verified the caller is
	/* admin/leadership/operations via requireCohort BEFORE calling. A crew-facing
	/* page breaks that invariant — the caller becomes any signed-in crew member.
	/* So this is gated on goat_acting_user_id() instead and returns strictly less.
	/*
	/* TWO DELIBERATE DIVERGENCES from get-induction-catalogue.php, both of which
	/* will read as bugs to anyone diffing the two files:
	/*
	/*   1. ops_note is NOT in the SELECT list. It is internal (design v0.5 §8.2
	/*      renders it visually distinct from the crew note precisely because crew
	/*      must not see it). Omitted at the query, not filtered later, so it
	/*      cannot leak through a downstream filter someone forgets to add.
	/*
	/*   2. published = 1 is filtered IN SQL. get-induction-catalogue.php returns
	/*      unpublished rows on purpose, so the Manage Inductions editor can list
	/*      them. Crew must never see an unpublished row — catalogue id 11
	/*      (Hanging Rock) is published = 0 today and is the live proof this
	/*      matters.
	/*
	/* Policy fields (validity_months, warn_days, show_in_checker, match_keywords,
	/* validity_changed_at) are also omitted. They are ops concerns; the crew page
	/* receives validity arithmetic already computed by my-induction-venues.php.
	*/

	$userID = (int) goat_acting_user_id();

	if ($userID <= 0)
	{
		http_response_code(403);
		die('{"error":"not authorised"}');
	}

	/*
	/* One set-based join, mirroring get-induction-catalogue.php. LEFT join to
	/* covers on purpose: a published row with no covered venues must still appear,
	/* so a mis-seeded row is visibly empty rather than silently missing.
	*/

	$sql = "SELECT c.id AS id,
	               c.code AS code,
	               c.title AS title,
	               c.crew_note AS crew_note,
	               c.links AS links,
	               c.sort_order AS sort_order,
	               cov.venue_id AS venue_id
	        FROM venue_induction_catalogue c
	        LEFT JOIN venue_induction_covers cov
	               ON cov.catalogue_id = c.id
	        WHERE c.published = 1
	        ORDER BY c.sort_order ASC, c.id ASC, cov.venue_id ASC";

	$res = mysql_query($sql);

	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"induction content query failed: ' . addslashes(mysql_error()) . '"}');
	}

	/*
	/* Group joined rows by catalogue id, keyed while accumulating, then
	/* array_values() at the end — without that, json_encode emits an OBJECT keyed
	/* by id ({"3":{...}}) instead of an array, and the consumer breaks on a shape
	/* it never asked for.
	*/

	$byId = array();

	while ($row = mysql_fetch_object($res))
	{
		$id = (int) $row->id;

		if (!isset($byId[$id]))
		{
			/*
			/* links is TEXT holding a JSON array, and the live table proves it
			/* arrives in four shapes: NULL (11 rows), '', '[]' (5 rows), and
			/* real JSON (1 row). json_decode(NULL) returns NULL, not an array —
			/* the same class of trap as the missing array_values(). Coerce here,
			/* once, so the consumer only ever handles a list.
			/*
			/* array_values() on the decoded value as well: a malformed row
			/* holding a JSON object rather than an array would otherwise
			/* re-encode as an object and break the consumer's list handling.
			*/

			$links = array();

			if ($row->links !== null && strlen(trim($row->links)) > 0)
			{
				$decoded = json_decode($row->links, true);

				if (is_array($decoded))
				{
					$links = array_values($decoded);
				}
			}

			$byId[$id] = array(
				'id'         => $id,
				'code'       => $row->code,
				'title'      => $row->title,
				'crew_note'  => ($row->crew_note !== null) ? $row->crew_note : '',
				'links'      => $links,
				'venue_ids'  => array(),
				'sort_order' => (int) $row->sort_order
			);
		}

		if ($row->venue_id !== null)
		{
			$byId[$id]['venue_ids'][] = (int) $row->venue_id;
		}
	}

	echo json_encode(array(
		'inductions' => array_values($byId)
	));

?>
