<?php

	include('global.php');
	
	$db->debug = 1;
	
	$css = array(
		'ydisplayarea'    => 'border: 1px solid #d1d1a3; background: #ebebbd; color: #595959; padding: 12px 17px 12px 17px; height: 35px;',
		'ydisplayarea-h2' => 'width: 600px; float: left; text-transform: uppercase; color: #ca1c1c; padding: 0px 0px 0px 25px; margin: 8px 0px 0px 0px; font-size: 17px;',
		'styledtable-th'  => 'text-transform: uppercase; font-size: 11px; color: #fff; text-align: left; padding: 12px 15px 12px 15px; background:  repeat-x top left #8a96ab;',
		'styledtable-td'  => 'font-size: 11px; padding: 9px 15px 9px 15px; background: #f1f2f4; color: #6b6f75;',
		'styledtable-td2' => 'font-size: 11px; padding: 9px 15px 9px 15px; background: #dbdffc; color: #6b6f75;'
	);

	$tomorrow = date('Y-m-d', strtotime('now + 24 hours'));

	$backups = $db->select(
		'users.firstname, users.lastname, users.phone, users.mobile,
		 calls.call_name AS `call`, calls.id AS call_id, calls.start_date,
		 calls.start_time, calls.notes AS call_notes, calls.est_length AS call_length,
		 calls.required, call_crew_map.status,
		 bookings.name AS `booking`, DATE_FORMAT(FROM_UNIXTIME(calls.start_date), "%Y-%m-%d")',
		'call_crew_map
		 LEFT JOIN `users`    ON (users.id = call_crew_map.userID)
		 LEFT JOIN `calls`    ON (calls.id = call_crew_map.callID)
		 LEFT JOIN `bookings` ON (bookings.id = calls.bookingID)',
		'bookings.status = 0',
		'calls.id'
	);

	/*
	/* empty calls don't show up in the above query so get them separately */

	$empty_calls = $db->select(
		'calls.call_name AS `call`, calls.id AS call_id, calls.start_date,
		 calls.start_time, calls.notes AS call_notes, calls.est_length AS call_length,
		 calls.required,
		 bookings.name AS `booking`, DATE_FORMAT(FROM_UNIXTIME(calls.start_date), "%Y-%m-%d")',
		'`calls`
		 LEFT JOIN `bookings` ON (`bookings`.`id` = `calls`.`bookingID`)',
		'bookings.status = 0',
		'calls.id'
	);

	/* remove calls that aren't empty */

	if (count($empty_calls) > 0) {

		foreach ($empty_calls as $i => $ec) {

			$res = $db->selectFirst('COUNT(*) AS count', 'call_crew_map', 'status = 5 AND callID = ' . $ec->call_id);

			$stats->booked = $res->count;

			if ( $stats->booked > 0 ) {

				unset( $empty_calls[$i] );

			}

		}

	}

	/*
	/* merge the arrays and sort them by call id */

	$backups = array_merge($backups, $empty_calls);

	function cmp($a, $b)
	{
		return $a->call_id - $b->call_id;
	}

	usort($backups, 'cmp');

	/*
	/* generate HTML and send via e-mail */

	if (count($backups) > 0)
	{

		$send = false;

		foreach ($backups as $backup)
		{

			$res = $db->selectFirst('COUNT(*) AS count', 'call_crew_map', 'status = 5 AND callID = ' . $backup->call_id);
			$stats->booked = $res->count;
			
			echo 'Booked: '. $stats->booked .' -- Required: '. $backup->required .' -- Status: '. $backup->status .'<br />';

			if ($backup->status == 7)
			{

				if ($backup->booking != $current_booking)
				{

					if ($current_booking)
						$message .= '</table>';

					$message .= '<br /><div style="width: 860px; margin-left: 2px; ' . $css['ydisplayarea'] .'"><h2 style="' . $css['ydisplayarea-h2'] . '">Booking: ' . $backup->booking . '</h2></div>';
					$message .= '<table style="width: 900px; '. $css['styledtable'] .'">';
					$message .= '<tr>';
					$message .= '  <th style="' . $css['styledtable-th'] . '">Call #</th>';
					$message .= '  <th style="' . $css['styledtable-th'] . '" colspan="3">Date / Time</th>';
					$message .= '  <th style="' . $css['styledtable-th'] . '">Call</th>';
					$message .= '  <th style="' . $css['styledtable-th'] . '">Length</th>';
					$message .= '  <th style="' . $css['styledtable-th'] . '">Booked</th>';
					$message .= '</tr>';

				}

				if ($backup->call_id != $current_call)
				{

					$message .= '<tr>';
					$message .= '  <td style="' . $css['styledtable-td2'] . '">#' . $backup->call_id . '</td>';
					$message .= '  <td style="width: 20px; ' . $css['styledtable-td2'] . '"><b>' . date('D', $backup->start_date) . '</b></td>';
					$message .= '  <td style="width: 40px; ' . $css['styledtable-td2'] . '"><b>' . date('d/m/y', $backup->start_date) . '</b></td>';
					$message .= '  <td style="width: 20px; ' . $css['styledtable-td2'] . '"><b>' . date('h:i', $backup->start_time) . '</b></td>';
					$message .= '  <td style="' . $css['styledtable-td2'] . '">' . htmlspecialchars($backup->call) . '</td>';
					$message .= '  <td style="' . $css['styledtable-td2'] . '">' . $backup->call_length . ' hrs</td>';
					$message .= '  <td style="' . $css['styledtable-td2'] . '">' . $stats->booked . ' / ' . $backup->required . '</td>';
					$message .= '</tr>';

					//$inhibit = false;

				}

				//if (!$inhibit)
				//{

					/*if ($stats->booked < $backup->required)
					{

						$message .= '<tr>';
						$message .= '  <td style="' . $css['styledtable-td'] . '" colspan="7">';
						$message .= $backup->required - $stats->booked . " more crew required for call.";
						$message .= '  </td>';
						$message .= '</tr>';

						$inhibit = true;
						$send    = true;

					}
					else if ($backup->status == 7)
					{*/

						$message .= '<tr>';
						$message .= '  <td style="' . $css['styledtable-td'] . '" colspan="7">';
						$message .= '<table>';
						$message .= '<tr>';
						$message .= '  <td style="width: 400px; ' . $css['styledtable-td'] . '">' . $backup->firstname . ' ' . $backup->lastname . '</td>';
						$message .= '  <td style="' . $css['styledtable-td'] . '"><b>Phone:</b></td>';
						$message .= '  <td style="' . $css['styledtable-td'] . '">Mob. ' . $backup->mobile . ' Ph. '. $backup->phone .'</td>';
						$message .= '</tr>';
						$message .= '</table>';
						$message .= '  </td>';
						$message .= '</tr>';

						$send = true;

					/*}*/

				//}

				/*
				/* update current booking / call */

				$current_booking = $backup->booking;
				$current_call    = $backup->call_id;

			}

		}

		$message .= '</table>';
		$message = '<div style="width: 900px; margin: auto;">' . $message . '</div>';
//echo $message;
		if ($send)
		{

			$title = "Backup crew for events on " . date('d/m/Y', strtotime('now + 24 hours'));
			$smarty->assign('title', $title);
			$smarty->assign('body',  $message);
			$message = $smarty->fetch('email.tpl');
	
			$mailer = new PHPMailer();
			
			$mailer->SetFrom($settings->from_email, $settings->title);
			//$mailer->AddAddress($settings->from_email, $settings->title);
			$mailer->AddAddress("gavin.benda@netforge.com.au", $settings->title);
			$mailer->AddAddress("joe@gigpower.com", "Joe");
			$mailer->AddAddress("weasle@gigpower.com", "Weasle");
			$mailer->AddAddress("gigpower@gmail.com", "GigPower Staff");

			$mailer->Subject = $title;
			$mailer->MsgHTML($message);

			$mailer->Send();
			
		}
	}

?>
