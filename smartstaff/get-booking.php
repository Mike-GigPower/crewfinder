<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');
	include('call-graph.php');

	/*
	/* JSON response */

	header('Content-Type: application/json');

	/*
	/* READ-ALL (admin / leadership / operations).
	/*
	/* Booking detail backs the in-GOAT view dialog. Includes contact + on-site
	/* phone/mobile and a per-call crew roster (with mobile + confirmation status)
	/* because crew bosses (leadership) need to reach people when crew run late —
	/* so this uses the same goat_can_read_all() gate as list-venues-bulk.php, not
	/* the admin-only gate.
	/*
	/* NOTE: align with the exact helper the other read-all endpoints call.
	*/

	if (!goat_can_read_all())
	{
		http_response_code(403);
		die('{"error":"Admin, Leadership or Operations only"}');
	}

	$bookingID = isset($_GET['id']) ? (int) $_GET['id'] : 0;
	if ($bookingID <= 0)
	{
		http_response_code(400);
		die('{"error":"missing or invalid booking id"}');
	}

	/*
	/* booking + customer + venue + contact + on-site contact
	/*
	/* Schema notes (mirrors view-booking.php's join):
	/*   bookings.userID       -> the booking CONTACT (a users row)
	/*   bookings.onsiteUserID -> the ON-SITE contact (a users row)
	/*   bookings.reference    -> invoice reference (not selected by view-booking)
	/* Raw mysql_* accessor for consistency with list-venues-bulk.php.
	*/

	$sql = "SELECT b.id, b.name, b.creation_date, b.status, b.notes, b.reference,
	               b.customerID, b.venueID, b.userID, b.onsiteUserID,
	               c.customer_name,
	               v.venue, v.address, v.suburb, v.state,
	               ct.firstname AS ct_first, ct.lastname AS ct_last, ct.phone AS ct_phone, ct.mobile AS ct_mobile,
	               os.firstname AS os_first, os.lastname AS os_last, os.phone AS os_phone, os.mobile AS os_mobile
	        FROM bookings b
	        LEFT JOIN customers c ON b.customerID = c.id
	        LEFT JOIN venues v ON b.venueID = v.id
	        LEFT JOIN users ct ON b.userID = ct.id
	        LEFT JOIN users os ON b.onsiteUserID = os.id
	        WHERE b.id = " . $bookingID . "
	        LIMIT 1";

	$res = mysql_query($sql);
	if ($res === false)
	{
		http_response_code(500);
		die('{"error":"booking query failed: ' . addslashes(mysql_error()) . '"}');
	}

	$b = mysql_fetch_object($res);
	if (!$b)
	{
		http_response_code(404);
		die('{"error":"booking not found"}');
	}

	/*
	/* booking status: 0 = Active (open), 1 = Closed
	*/

	$bStatusMap = array(0 => 'Active', 1 => 'Closed');
	$bStatusStr = isset($bStatusMap[(int) $b->status]) ? $bStatusMap[(int) $b->status] : ('Status ' . (int) $b->status);

	/*
	/* call_crew_map.status -> label (matches the booked-crew scraper's keywords)
	/*   5 = confirmed, 1 = sent (SMS pending), 6 = declined, 8 = no-show,
	/*   anything else (incl. just-added) = unconfirmed
	*/

	$crewStatusMap = array(5 => 'confirmed', 1 => 'sent', 6 => 'declined', 8 => 'noshow', 7 => 'backup', 0 => 'unconfirmed');

	/*
	/* calls + per-call crew roster
	*/

	/*
	/* Feed edges for this booking, in one query. Built into two maps so the
	/* per-call emit below is a lookup, not a query.
	*/

	$feedsOf  = array();   /* call_id -> [target ids] */
	$fedByOf  = array();   /* call_id -> [source ids] */

	$fres = mysql_query("SELECT source_call, target_call FROM call_feeds
	                     WHERE booking_id = " . $bookingID);

	if ($fres !== false)
	{
		while ($frow = mysql_fetch_object($fres))
		{
			$s = (int) $frow->source_call;
			$t = (int) $frow->target_call;

			if (!isset($feedsOf[$s]))
			{
				$feedsOf[$s] = array();
			}

			if (!isset($fedByOf[$t]))
			{
				$fedByOf[$t] = array();
			}

			$feedsOf[$s][] = $t;
			$fedByOf[$t][] = $s;
		}
	}

	$calls = array();
	$cres = mysql_query("SELECT id, call_name, start_date, start_time, est_length, required, notes, link_group
	                     FROM calls
	                     WHERE bookingID = " . $bookingID . "
	                     ORDER BY start_date ASC, start_time ASC");

	if ($cres !== false)
	{
		while ($call = mysql_fetch_object($cres))
		{
			$callID    = (int) $call->id;
			$crew      = array();
			$booked    = 0;
			$confirmed = 0;

			$crres = mysql_query("SELECT users.id, users.firstname, users.lastname, users.mobile, users.phone,
			                             users.ein, users.email,
			                             call_crew_map.status, call_crew_map.is_call_boss,
			                             cca.id AS change_ack_id
			                      FROM call_crew_map
			                      LEFT JOIN users ON call_crew_map.userID = users.id
			                      LEFT JOIN call_change_ack cca
			                             ON cca.callID = call_crew_map.callID
			                            AND cca.userID = call_crew_map.userID
			                      WHERE call_crew_map.callID = " . $callID . "
			                      ORDER BY users.lastname ASC, users.firstname ASC");

			if ($crres !== false)
			{
				while ($cr = mysql_fetch_object($crres))
				{
					$st        = (int) $cr->status;
					$statusStr = isset($crewStatusMap[$st]) ? $crewStatusMap[$st] : 'unconfirmed';
					$booked++;
					if ($st === 5)
						$confirmed++;

					/*
					/* Ops re-confirm badge: a call_change_ack row means this crew
					/* member has an OUTSTANDING timing change. GUARDED to status 5/7
					/* so a stale row on an admin-declined person never renders a
					/* badge (the 5/7 confirmed/backup rows are the filling risk ops
					/* care about).
					*/
					$changePending = (($st === 5 || $st === 7) && $cr->change_ack_id !== null) ? true : false;

					$crew[] = array(
						'id'             => (int) $cr->id,
						'name'           => trim($cr->firstname . ' ' . $cr->lastname),
						'firstname'      => html_entity_decode($cr->firstname, ENT_QUOTES),
						'lastname'       => html_entity_decode($cr->lastname, ENT_QUOTES),
						'ein'            => $cr->ein,
						'email'          => $cr->email,
						'mobile'         => $cr->mobile,
						'phone'          => $cr->phone,
						'status'         => $statusStr,
						'is_call_boss'   => (int) $cr->is_call_boss,
						'change_pending' => $changePending,
					);
				}
			}

			$fc = goat_call_feed_counts($callID);

			$calls[] = array(
				'call_id'    => $callID,
				'call_name'  => $call->call_name,
				'start_date' => (int) $call->start_date,
				'start_time' => $call->start_time,
				'est_length' => $call->est_length,
				'required'   => (int) $call->required,
				'notes'      => $call->notes,
				'link_group' => ($call->link_group === null ? null : (int) $call->link_group),
				'feeds'        => isset($feedsOf[$callID]) ? $feedsOf[$callID] : array(),
				'fed_by'       => isset($fedByOf[$callID]) ? $fedByOf[$callID] : array(),
				'committed'    => $fc['committed'],
				'reserved'     => $fc['reserved'],
				'free_to_fill' => $fc['free_to_fill'],
				'booked'     => $booked,
				'confirmed'  => $confirmed,
				'crew'       => $crew,
			);
		}
	}

	/*
	/* assemble response
	*/

	$out = array(
		'booking_id'    => $bookingID,
		'name'          => $b->name,
		'creation_date' => (int) $b->creation_date,
		'date_str'      => ($b->creation_date ? date('M j, Y', (int) $b->creation_date) : ''),
		'status'        => $bStatusStr,
		'status_id'     => (int) $b->status,
		'reference'     => $b->reference,
		'notes'         => $b->notes,
		'customer'      => array(
			'id'   => (int) $b->customerID,
			'name' => $b->customer_name,
		),
		'venue'         => array(
			'id'      => (int) $b->venueID,
			'name'    => $b->venue,
			'address' => $b->address,
			'suburb'  => $b->suburb,
			'state'   => $b->state,
		),
		'contact'       => array(
			'id'     => (int) $b->userID,
			'name'   => trim($b->ct_first . ' ' . $b->ct_last),
			'phone'  => $b->ct_phone,
			'mobile' => $b->ct_mobile,
		),
		'onsite'        => array(
			'id'     => (int) $b->onsiteUserID,
			'name'   => trim($b->os_first . ' ' . $b->os_last),
			'phone'  => $b->os_phone,
			'mobile' => $b->os_mobile,
		),
		'calls'         => $calls,
	);

	echo json_encode($out);

?>
