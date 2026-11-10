<?php

	/*
	/* CREW DIETARY REQUIREMENTS — the vocabulary, the "may see others" rule and
	/* the one response shape, in ONE place. See BRIEF-dietary-requirements.md
	/* and DESIGN-dietary-requirements-v0_3.md.
	/*
	/* include() this AFTER global.php and cohort.php (goat_user_cohort() needs
	/* $user, $_SESSION and the mysql connection global.php establishes).
	/*
	/* PHP 5.6 -- array(), no ??, no short arrays.
	*/

	include_once(dirname(__FILE__) . '/goat-db.php');

	/*
	/* The options, codes -> labels, IN DISPLAY ORDER. Stored options are sorted
	/* into this order, counts on the crew list print in this order, and the JS
	/* label map in templates/index.html (ceDietaryLabel) and the Crew Hub /
	/* apply-form copies must stay in step with it. Adding a code is a change to
	/* all of them at once.
	*/

	if (!function_exists('goat_dietary_options'))
	{
		function goat_dietary_options()
		{
			return array(
				'vegetarian'  => 'Vegetarian',
				'vegan'       => 'Vegan',
				'gluten_free' => 'Gluten-free',
				'dairy_free'  => 'Dairy-free',
				'halal'       => 'Halal',
				'kosher'      => 'Kosher',
				'allergy'     => 'Food allergy or intolerance',
				'other'       => 'Other'
			);
		}
	}

	/*
	/* Codes whose tick REQUIRES a note -- "allergy" without saying to what, or
	/* "other" without saying what, tells a caterer nothing.
	*/

	if (!function_exists('goat_dietary_note_required'))
	{
		function goat_dietary_note_required()
		{
			return array('allergy', 'other');
		}
	}

	/*
	/* MAY THE CALLER SEE (AND, AS OPS, WRITE) SOMEBODY ELSE'S DIETARY RECORD?
	/*
	/* Admin + operations only (D10). NOT leadership, and NOT goat_can_read_all(),
	/* which admits leadership and the service key as well.
	/*
	/* goat_user_cohort() and NOT goat_cohort_for_user(goat_acting_user_id()):
	/* the cohort must come from a real SmartStaff SESSION. On the Crew Hub
	/* service-key path the acting id is ASSERTED by the caller, and deriving a
	/* cohort from an asserted id would let that path claim operations reach by
	/* asserting an operator's id. goat_user_cohort() returns null without a
	/* session, so the service-key path can only ever be "self".
	*/

	if (!function_exists('goat_dietary_can_view_others'))
	{
		function goat_dietary_can_view_others()
		{
			$c = goat_user_cohort();
			return ($c === 'admin' || $c === 'operations');
		}
	}

	/*
	/* mbstring is not guaranteed on this box -- the goat_emergency_len()
	/* fallback: bytes when mb_strlen() is missing, which is stricter for
	/* multibyte input and never looser.
	*/

	if (!function_exists('goat_dietary_len'))
	{
		function goat_dietary_len($s)
		{
			return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
		}
	}

	/* Stored comma list -> array of known codes, in vocabulary order. */

	if (!function_exists('goat_dietary_split'))
	{
		function goat_dietary_split($stored)
		{
			$have = array();

			foreach (explode(',', (string) $stored) as $c)
			{
				$c = trim($c);

				if ($c !== '')
				{
					$have[$c] = true;
				}
			}

			$out = array();

			foreach (array_keys(goat_dietary_options()) as $code)
			{
				if (isset($have[$code]))
				{
					$out[] = $code;
				}
			}

			return $out;
		}
	}

	/*
	/* The response for ONE subject. $row is the crew_dietary row or null; no
	/* row is not_answered with everything else null (the brief's contract).
	*/

	if (!function_exists('goat_dietary_payload'))
	{
		function goat_dietary_payload($subjectID, $row)
		{
			$subjectID = (int) $subjectID;

			if (!is_array($row))
			{
				return array(
					'ok'                => true,
					'user_id'           => $subjectID,
					'state'             => 'not_answered',
					'options'           => array(),
					'note'              => null,
					'consent_at'        => null,
					'updated_at'        => null,
					'updated_source'    => null,
					'updated_by_office' => false
				);
			}

			$state = (string) $row['state'];

			return array(
				'ok'                => true,
				'user_id'           => $subjectID,
				'state'             => $state,
				'options'           => ($state === 'declared' ? goat_dietary_split($row['options']) : array()),
				'note'              => ($state === 'declared' && $row['note'] !== null && $row['note'] !== '') ? (string) $row['note'] : null,
				'consent_at'        => ($row['consent_at'] !== null) ? (string) $row['consent_at'] : null,
				'updated_at'        => ($row['updated_at'] !== null) ? (string) $row['updated_at'] : null,
				'updated_source'    => ($row['updated_source'] !== null) ? (string) $row['updated_source'] : null,
				'updated_by_office' => ((int) $row['updated_by'] !== $subjectID)
			);
		}
	}
