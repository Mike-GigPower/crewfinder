<?php

	/*
	/* Licence catalogue helpers — one read of `licence_catalogue`, shared.
	/*
	/* Replaces the hardcoded $allowedTypes array that admin-add-license.php,
	/* admin-edit-license.php and my-add-license.php each carried a copy of, and
	/* backs list-licence-catalogue.php and my-list-licenses.php as well. One
	/* implementation included by all of them, on the call-graph.php pattern —
	/* pasting the query five times is the problem this migration exists to solve.
	/*
	/* PHP 5.x — no ??, no short array syntax.
	*/

	if (!function_exists('goat_licence_catalogue_rows'))
	{

		/*
		/* The catalogue rows, or FALSE if the table can't be read.
		/*
		/* $includeUnpublished = false is the read path: unpublishing a code
		/* withdraws it from the Crew Finder chips, the entry dropdowns AND the
		/* writes. Rows already on file are untouched and keep rendering, because
		/* consumers fall back to their own name map before the bare code.
		/*
		/* TRUE is the Phase 2 editor, which cannot edit a row it can't see. The
		/* CALLER gates that branch — this helper does no auth.
		/*
		/* All numerics are cast: mysql_* returns every value as a string, so an
		/* uncast validity_months arrives as "36" and breaks arithmetic in the
		/* consumer silently. validity_months is NOT coerced to 0 — NULL means
		/* "this type has no period", which the editor must be able to tell apart
		/* from an explicit 0, so it stays null.
		/*
		/* Cached per flag in a static: one query per request however many callers.
		*/

		function goat_licence_catalogue_rows($includeUnpublished = false)
		{
			static $cache = array();

			$key = $includeUnpublished ? 'all' : 'published';

			if (isset($cache[$key]))
				return $cache[$key];

			$where = $includeUnpublished ? '' : ' WHERE `published` = 1';

			$res = mysql_query(
				"SELECT `code`, `name`, `grp`, `expiry_mode`, `validity_months`,
				        `require_certified`, `published`, `sort_order`, `notes`
				 FROM `licence_catalogue`" . $where . "
				 ORDER BY `sort_order` ASC, `code` ASC"
			);

			if ($res === false)
			{
				$cache[$key] = false;
				return false;
			}

			$rows = array();

			while ($row = mysql_fetch_object($res))
			{
				$rows[] = array(
					'code'              => $row->code,
					'name'              => $row->name,
					'grp'               => $row->grp,
					'expiry_mode'       => $row->expiry_mode,
					'validity_months'   => ($row->validity_months !== null) ? (int) $row->validity_months : null,
					'require_certified' => ((int) $row->require_certified === 1),
					'published'         => ((int) $row->published === 1),
					'sort_order'        => (int) $row->sort_order,
					'notes'             => ($row->notes !== null) ? $row->notes : ''
				);
			}

			/*
			/* An EMPTY catalogue is treated as unreadable. A seeded table always
			/* has rows, so zero means the seed never ran or the table was
			/* truncated — and silently rejecting every licence add would be
			/* diagnosed as a form bug, days later.
			*/

			if (count($rows) === 0)
			{
				$cache[$key] = false;
				return false;
			}

			$cache[$key] = $rows;
			return $rows;
		}

		/*
		/* The published codes, for the write endpoints' allow-list, or FALSE.
		/*
		/* FALSE, not an empty array. The caller must reject the write on FALSE:
		/*   - falling back to array() rejects EVERYTHING and reads to the operator
		/*     as a broken form rather than an unavailable catalogue;
		/*   - falling back to "permit anything" removes a safety boundary —
		/*     'Induction Certificate' must never pass, or a licence write lands on
		/*     an induction row (handover rule #2).
		/* A clean 500 saying the catalogue is unavailable is the honest failure.
		*/

		function goat_licence_allowed_types()
		{
			$rows = goat_licence_catalogue_rows(false);

			if ($rows === false)
				return false;

			$codes = array();

			foreach ($rows as $r)
				$codes[] = $r['code'];

			return $codes;
		}

	}

?>
