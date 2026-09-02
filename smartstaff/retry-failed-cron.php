<?php

	/* global file */

	include('global.php');
	include('lib/messagemedia/SmsInterface.php');

	$db->debug = 1;
	
	$si = new SmsInterface (false, false);

	if ( $si->connect( $settings->smsuser, $settings->smspass, true, false ) )  {
	
		$reminders = $db->select(
			'call_crew_map.crewmapID, call_crew_map.userID as cc_map_uid, calls.*, bookings.name, venues.venue, users.firstname, users.lastname, users.mobile, customers.customer_name', 
			'call_crew_map 
			LEFT JOIN calls ON call_crew_map.callID=calls.id
			LEFT JOIN bookings ON calls.bookingID=bookings.id
			LEFT JOIN venues ON bookings.venueID=venues.id
			LEFT JOIN users ON bookings.onsiteUserID=users.id
			LEFT JOIN customers ON bookings.customerID=customers.id', 
			'call_crew_map.status=5 AND sms_fail=1'
		);
		
		echo '[DEBUG] Previously failed Reminders: ' . count($reminders)  . ' <br />';

		if (count($reminders) == 0) {
			die('-- End Script --');
		}

		foreach($reminders as $reminder) {

			$varArray = array(
				'{start_date}' 		=> date('D-d-M', $reminder->start_date),
				'{start_time}' 		=> date('H:i', strtotime($reminder->start_time)),
				'{call_name}' 		=> $reminder->call_name,
				'{booking_name}'	=> $reminder->name,
				'{est_length}'		=> $reminder->est_length,
				'{venue}'			=> $reminder->venue,
				'{contact}'			=> $reminder->firstname .' '. $reminder->lastname,
				'{client}'			=> $reminder->customer_name,
				'{mobile}'			=> $reminder->mobile,
			);
			
			$smsMsg = str_replace(array_keys($varArray), array_values($varArray), $settings->reminder_template);
			
			/* grab user phone number */
			
			$smsUserID = $db->selectFirst('id,mobile', 'users', 'id='. $db->sc($reminder->cc_map_uid));

			$dataArray = array(
				'userID' 			=> $smsUserID->id,
				'callID'			=> $reminder->id,
				'phone'				=> "'". $db->sc(str_replace(' ', '', $smsUserID->mobile)) ."'",
				'message'			=> $db->sc($smsMsg),
				'date_sent'			=> $db->sc(strtotime('now'))
			);

			//
			// ALL THE DATA
			//
			$smsQueue[] = array( $smsUserID->mobile, $smsMsg, $reminder->crewmapID, $dataArray );

		}

		/* send out sms messages */
		
		if ( count($smsQueue) > 0 ) {
			
			echo '[DEBUG] smsQueue > 0<br />';

			foreach($smsQueue as $smsInfo) {

				$si->addMessage($smsInfo[0], $smsInfo[1]);

				echo '[DEBUG] si-add-message<br />';
			
			}
		
		}
			
		// Attempt to send and 
		// Check to make sure the messages were sent
		
		if ( !$si->sendMessages() ) {
			
			// SEND MESSAGE FAIL
			
			echo('<b class="negative">failed. Could not send message to server.</b>');

			// Iterate items in the SMS Queue
			foreach($smsQueue as $smsInfo) {

				// Update Call Crew Map set SMS Fail = 1
				$db->update( 'call_crew_map', array( 'sms_fail' => $db->sc(1) ), 'crewmapID='. $smsInfo[2] );

			}
			
			echo '<br /><br />[DEBUG] smsQueue: <br />' . var_dump($smsQueue) . '<br /><br />';

			if ( $si->getResponseMessage() ) {

				echo('<br />Reason: '. $si->getResponseMessage () .'</b>');
				
			}
				
		} else {
		
			// SEND MESSAGE SUCCESS

			echo('<b class="positive">SMS Notifications Sent</b>');

			// Iterate items in the SMS Queue
			foreach($smsQueue as $smsInfo) {

				// Update Call Crew Map set Reminder Sent = 1
				$db->update( 'call_crew_map', array( 'reminder_sent' => $db->sc(1) ), 'crewmapID='. $smsInfo[2] );
			
				echo '[DEBUG] db-update<br />';

				// Update Call Crew Map set SMS Fail = 0
				$db->update( 'call_crew_map', array( 'sms_fail' => $db->sc(0) ), 'crewmapID='. $smsInfo[2] );

				echo '[DEBUG] db-update<br />';

				// Insert the SMS sent entry in the DB
				$db->insert('sms_sent', $smsInfo[3]);
			
				echo '[DEBUG] db-insert<br />';

			}
		
		}
	
	} else {

		echo('<b class="negative">Error: Cannot Connect to SMS Server</b>');

	}

?>
