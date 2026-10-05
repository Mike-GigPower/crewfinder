<?php

	/*
	/* Promotion GROUP helpers — DESIGN-backup-callout §3.2.
	/*
	/* Link-safe Promote (update-crew-status.php) writes every call_promo_ack
	/* row of one promotion with the same promoted_at. That shared timestamp is
	/* the group key: one promotion, one answer, one card in Crew Hub.
	/*
	/* Shared by respond-to-promotion.php (the answer), dismiss-promo-ack.php
	/* (ops answering by phone) and my-shifts.php (the decline warning), so the
	/* warning Crew Hub shows and what a decline actually does come from one
	/* code path and cannot disagree.
	/*
	/* Requires $db (global.php). Includes call-graph.php itself.
	/*
	/* PHP 5.x — mysql_*, no ??, no short array syntax.
	*/

	include_once('call-graph.php');

	if (!function_exists('goat_promo_group'))
	{

		/*
		/* The unanswered promotion group containing $callID for this user:
		/* every row with acked_at IS NULL sharing that row's promoted_at.
		/* Empty when $callID has no unanswered promotion.
		*/

		function goat_promo_group($userID, $callID)
		{
			global $db;

			$userID = (int) $userID;
			$callID = (int) $callID;

			$promo = $db->selectFirst(
				'promoted_at',
				'call_promo_ack',
				'callID=' . $db->sc($callID) . ' AND userID=' . $db->sc($userID) .
				' AND acked_at IS NULL'
			);

			if (!$promo)
			{
				return array();
			}

			$group = array();
			$gres  = mysql_query('SELECT callID FROM call_promo_ack
			                      WHERE userID=' . $userID . '
			                        AND acked_at IS NULL
			                        AND promoted_at=' . intval($promo->promoted_at) . '
			                      ORDER BY callID');

			if ($gres !== false)
			{
				while ($grow = mysql_fetch_object($gres))
				{
					$group[] = (int) $grow->callID;
				}
			}

			if (!in_array($callID, $group))
			{
				$group[] = $callID;
			}

			return $group;
		}

		/*
		/* What declining the group withdraws OUTSIDE it: Confirmed (5) rows
		/* upstream of any group call, the same rule a normal decline applies
		/* via goat_decline_scope.
		/*
		/* The UNION over every call in the group, not just the one tapped: the
		/* group is one decision, and which card was answered must not change
		/* what is withdrawn (brief C2, amended 5 Oct).
		/*
		/* Only 5 moves. Offered rows in a package (<= 1) are not a commitment;
		/* cancelled (9) is already excluded by goat_decline_scope.
		/*
		/* Unaffected by whether the group rows have already been flipped to 6:
		/* goat_decline_scope's package is built from offered rows only, and
		/* group calls are excluded from the result. So the preview my-shifts
		/* shows before a decline and the set respond-to-promotion withdraws
		/* after flipping the group are the same.
		*/

		function goat_promo_withdraw_ids($userID, $groupCalls)
		{
			$out = array();

			foreach ($groupCalls as $gc)
			{
				$scope = goat_decline_scope($userID, $gc);

				foreach ($scope as $sc => $st)
				{
					$sc = (int) $sc;

					if ((int) $st !== 5 || in_array($sc, $groupCalls) || in_array($sc, $out))
					{
						continue;
					}

					$out[] = $sc;
				}
			}

			return $out;
		}

		/*
		/* The same set with enough detail for Crew Hub to name each call —
		/* the shape my-call-offers.php emits as declining_withdraws
		/* (goat_declining_withdraws). Every row here is Confirmed, so
		/* `confirmed` is always true.
		*/

		function goat_promo_declining_withdraws($userID, $groupCalls)
		{
			$ids = goat_promo_withdraw_ids($userID, $groupCalls);

			if (!count($ids))
			{
				return array();
			}

			$res = mysql_query("SELECT id, call_name, start_date, start_time
			                    FROM calls WHERE id IN (" . implode(',', $ids) . ")
			                    ORDER BY start_date ASC, start_time ASC");

			$out = array();

			if ($res !== false)
			{
				while ($row = mysql_fetch_object($res))
				{
					$dateStr = date('Y-m-d', (int) $row->start_date);

					$out[] = array(
						'call_id'   => (int) $row->id,
						'call_name' => $row->call_name,
						'start'     => date('Y-m-d\TH:i:s', strtotime($dateStr . ' ' . $row->start_time)),
						'confirmed' => true
					);
				}
			}

			return $out;
		}

	}

?>
