<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* READ-ALL endpoint — the major-venue induction catalogue, for THE GOAT.
	/*
	/* THE GOAT has no direct MySQL access, so this is how it reads the catalogue
	/* that replaces its five hardcoded dicts (INDUCTION_VENUE_MAP,
	/* INDUCTION_24_MONTH, INDUCTION_CODE_LABELS, MELB_PARK_MEMBERS, and
	/* eventually INDUCTION_EXCLUDE).
	/*
	/* Gated on goat_can_read_all() rather than admin-only: THE GOAT's Crew Finder
	/* applies induction gating for operations users too, so an admin-only gate
	/* would break induction checks for Ops.
	/*
	/* Returns EVERY catalogue row, including unpublished ones, with the flags
	/* intact. Consumers apply visibility; the design's column notes give the rule:
	/*   published        master switch — crew-page link visibility
	/*   show_in_checker  governs the ops Checkers only
	/* so the Checker includes a row iff (published AND show_in_checker), while
	/* dropdowns/labels include it iff published. Returning everything keeps this
	/* endpoint reusable by the Phase 2 editor, which must see unpublished rows.
	/*
	/* All numerics are cast before json_encode: mysql_* returns every value as a
	/* string, so an uncast validity_months arrives as "12" and breaks arithmetic
	/* in the consumer silently. warn_days is deliberately NOT coerced — NULL means
	/* "inherit the global default", which the editor must be able to tell apart
	/* from an explicit 14. The default travels separately as warn_days_default.
	/*
	/* crew_note, ops_note and links are returned for the Phase 2 Manage
	/* Inductions editor. DO NOT REMOVE THEM. The editor seeds its form from this
	/* payload and always POSTs all three keys back; save-induction-catalogue.php
	/* gates each on isset(), which is always true. So a payload missing any of
	/* the three makes the form open empty and the next Save silently blank that
	/* row's content — the field it wipes is the field it could not display.
	/*
	/* ops_note is included deliberately, despite being ops-only content: this
	/* endpoint is gated on goat_can_read_all() (admin, leadership, operations, or
	/* the service key), and the editor cannot round-trip the field otherwise. The
	/* consequence is real and stated here: anything holding the service key can
	/* read ops_note. Crew Hub does not call this endpoint — it calls
	/* get-induction-content.php, which omits ops_note by design — but the
	/* reachability exists.
	*/

	if (!goat_can_read_all())
	{
		http_response_code(403);
		die('{"error":"not authorised"}');
	}

	$WARN_DAYS_DEFAULT = 14;

	/*
	/* One set-based join — no correlated subquery (cf. the get-calls-bulk.php
	/* 20-second timeout). LEFT join to covers on purpose: a catalogue row with no
	/* covered venues must still appear, so a mis-seeded row shows up as obviously
	/* empty rather than silently missing.
	*/

	$sql = "SELECT c.id AS id,
	               c.code AS code,
	               c.title AS title,
	               c.crew_note AS crew_note,
	               c.ops_note AS ops_note,
	               c.links AS links,
	               c.validity_months AS validity_months,
	               c.warn_days AS warn_days,
	               c.show_in_checker AS show_in_checker,
	               c.published AS published,
	               c.match_keywords AS match_keywords,
	               c.sort_order AS sort_order,
	               c.validity_changed_at AS validity_changed_at,
	               cov.venue_id AS venue_id
	        FROM venue_induction_catalogue c
	        LEFT JOIN venue_induction_covers cov
	               ON cov.catalogue_id = c.id
	        ORDER BY c.sort_order ASC, c.id ASC, cov.venue_id ASC";

	$res = mysql_query($sql);

	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"induction catalogue query failed: ' . addslashes(mysql_error()) . '"}');
	}

	/*
	/* Group the joined rows by catalogue id. Keyed while accumulating, then
	/* array_values() at the end — without that, json_encode emits an OBJECT
	/* keyed by id ({"3":{...}}) instead of an array ([{...}]), and the consumer's
	/* list handling breaks on a shape it never asked for.
	*/

	$byId = array();

	while ($row = mysql_fetch_object($res))
	{
		$id = (int) $row->id;

		if (!isset($byId[$id]))
		{
			/*
			/* match_keywords is comma-separated TEXT. Split here, not in the
			/* consumer, so the matching rule lives in exactly one place. Trimmed
			/* and lowercased because consumers match case-insensitively by
			/* substring containment against a venue/induction label.
			*/

			$keywords = array();
			$rawKw    = ($row->match_keywords !== null) ? trim($row->match_keywords) : '';

			if (strlen($rawKw) > 0)
			{
				$parts = explode(',', $rawKw);

				foreach ($parts as $p)
				{
					$k = strtolower(trim($p));

					if (strlen($k) > 0)
					{
						$keywords[] = $k;
					}
				}
			}

			/*
			/* links is TEXT holding a JSON array and arrives in four shapes:
			/* NULL, '', '[]' and real JSON. Decode here so consumers only ever
			/* handle a list — json_decode(NULL) returns NULL, not an array.
			/* array_values() as well, so a row holding a JSON object rather than
			/* an array cannot re-encode as an object.
			/*
			/* The editor's seed accepts a JSON string OR an array
			/* (index.html ~11136); an array is the cleaner contract and matches
			/* get-induction-content.php.
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
				'id'                  => $id,
				'code'                => $row->code,
				'title'               => $row->title,
				'crew_note'           => ($row->crew_note !== null) ? $row->crew_note : '',
				'ops_note'            => ($row->ops_note !== null) ? $row->ops_note : '',
				'links'               => $links,
				'validity_months'     => ($row->validity_months !== null) ? (int) $row->validity_months : 12,
				'warn_days'           => ($row->warn_days !== null) ? (int) $row->warn_days : null,
				'show_in_checker'     => ((int) $row->show_in_checker === 1),
				'published'           => ((int) $row->published === 1),
				'match_keywords'      => $keywords,
				'venue_ids'           => array(),
				'sort_order'          => (int) $row->sort_order,
				'validity_changed_at' => $row->validity_changed_at ? $row->validity_changed_at : null
			);
		}

		if ($row->venue_id !== null)
		{
			$byId[$id]['venue_ids'][] = (int) $row->venue_id;
		}
	}

	echo json_encode(array(
		'warn_days_default' => (int) $WARN_DAYS_DEFAULT,
		'inductions'        => array_values($byId)
	));

?>
