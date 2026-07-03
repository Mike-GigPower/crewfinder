<?

	include('global.php');
	include('lib/messagemedia/SmsInterface.php');
	$si = new SmsInterface (false, false);
	
	/*
	/* settings */
	$db->debug = 1;
	//$settings = $db->selectFirst('*', 'settings', 'vendorID=1');
				
	if($si->connect($settings->smsuser, $settings->smspass, true, false))
	{
	
		if($srl = $si->checkReplies())
		{

			if(count($srl) > 0)
			{
			
				foreach($srl as $sr)
				{
				
					/*
					/* try and find userID */
					
					$incomingNumber = str_replace('+61', '0', $sr->phoneNumber);
					$smsUserID = $db->selectFirst('id,mobile', 'users', 'REPLACE(mobile, " ", "") ='. $db->sc($incomingNumber));

					/*
					/* store raw reply */
				
					$dataArray = array(
						'smsID'			=> $db->sc($sr->messageID),
						'phone'			=> "'". $db->sc($incomingNumber, 0) ."'",
						'reply'			=> $db->sc($sr->message),
						'date_reply'	=> $db->sc(strtotime('now')),
						'userID'		=> $db->sc($smsUserID->id)
					);
					
					$db->insert('sms_reply', $dataArray);
					
					/*
					/* extract call details */
					
					$msgParts 	= array();
					$msgParts[] = substr(htmlspecialchars(strip_tags($sr->message)), 0, 1);
					$msgParts[] = substr(htmlspecialchars(strip_tags($sr->message)), 1, strlen($sr->message));
					
					$callInfo = $db->selectFirst(
						'call_crew_map.*, calls.*', 
						'call_crew_map LEFT JOIN calls ON call_crew_map.callID=calls.id', 
						'userID='. $smsUserID->id .' AND callID='. $msgParts[1]
					);
					
					$bDetails = $db->selectFirst(
						'bookings.*, venues.venue, venues.address as vaddress, venues.suburb as vsuburb, users.firstname, users.lastname, users.mobile, customers.customer_name', 
						'bookings
						LEFT JOIN venues ON bookings.venueID=venues.id
						LEFT JOIN users ON bookings.onsiteUserID=users.id
						LEFT JOIN customers ON bookings.customerID=customers.id', 
						'bookings.id='. intval($callInfo->bookingID)
					);

					/*
					/* update call status */
					
					$updateStatus	= 0;					
					
					if(strtolower($msgParts[0]) == 'y')
						$updateStatus = 5;
						
					if(strtolower($msgParts[0]) == 'n')
						$updateStatus = 6;
						
					/*
					/* only do something if status is 1 (awaiting reply) */
					
					if($callInfo->status == 1)
					{
					
						/*
						/* ensure call isn't already full */
						
						$getCrewStats = $db->selectFirst(
							'COUNT(call_crew_map.status) as count', 
							'call_crew_map',
							'status=5 AND callID='. $db->sc($callInfo->callID) .' GROUP BY status'
						);
						
						/*
						/* sent "too full" message if so */
						
						if($getCrewStats->count >= $callInfo->required && $updateStatus == 5)
						{

							$varArray = array(
								'{start_date}' 		=> date('D-d-M', $callInfo->start_date),
								'{start_time}' 		=> date('H:i', strtotime($callInfo->start_time)),
								'{call_name}' 		=> $callInfo->call_name,
								'{booking_name}'	=> $bDetails->name,
								'{est_length}'		=> $callInfo->est_length,
								'{venue}'			=> $bDetails->venue,
								'{contact}'			=> $bDetails->firstname .' '. $bDetails->lastname,
								'{client}'			=> $bDetails->customer_name,
								'{confirm}'			=> 'y'. $callInfo->callID,
								'{cancel}'			=> 'n'. $callInfo->callID
							);
							
							/*
							/* load up sms */
							
							$smsMsg = str_replace(array_keys($varArray), array_values($varArray), $settings->toofull_template);
							$smsQueue[] = array($smsUserID->mobile,$smsMsg);
							
							$dataArray = array(
								'userID' 			=> $smsUserID->id,
								'callID'			=> $callInfo->callID,
								'phone'				=> "'". $db->sc(str_replace(' ', '', $smsUserID->mobile)) ."'",
								'message'			=> $db->sc($smsMsg),
								'date_sent'			=> $db->sc(strtotime('now'))
							);
									
							$db->insert('sms_sent', $dataArray);
							
							/*
							/* sent "too full" message if so */
						
							$updateStatus = 7;
						
						}
						
						/*
						/* add crew member to call and notify */
						
						else
						{	

							if($updateStatus == 5)
							{
	
								$dataArray = array(
									'userID' 			=> $smsUserID->id,
									'callID'			=> $callInfo->callID,
									'phone'				=> "'". $db->sc(str_replace(' ', '', $smsUserID->mobile)) ."'",
									'message'			=> $db->sc($smsMsg),
									'date_sent'			=> $db->sc(strtotime('now'))
								);
									
								$db->insert('sms_sent', $dataArray);
								
								/*
								/* send confirmation sms */
								
								$varArray = array(
									'{start_date}' 		=> date('D-d-M', $callInfo->start_date),
									'{start_time}' 		=> date('H:i', strtotime($callInfo->start_time)),
									'{call_name}' 		=> $callInfo->call_name,
									'{booking_name}'	=> $bDetails->name,
									'{est_length}'		=> $callInfo->est_length,
									'{venue}'			=> $bDetails->venue,
									'{venue_address}'	=> $bDetails->vaddress,
									'{venue_suburb}'	=> $bDetails->vsuburb,
									'{contact}'			=> $bDetails->firstname .' '. $bDetails->lastname,
									'{client}'			=> $bDetails->customer_name,
									'{mobile}'			=> $bDetails->mobile,
									'{confirm}'			=> 'y'. $callInfo->callID,
									'{cancel}'			=> 'n'. $callInfo->callID
								);
							
								$smsMsg = str_replace(array_keys($varArray), array_values($varArray), $settings->confirm_template);
								
								$smsQueue[] = array($smsUserID->mobile,$smsMsg);
								
								/*
								/* add to calendar */
								
								$sss->addToCalendar($callInfo->callID, $smsUserID->id);
								
							}
						
						}
						
						$db->update('call_crew_map', array('status' => $updateStatus), 'userID='. $smsUserID->id .' AND callID='. $msgParts[1]);

					}
				
				}
			
			}
		
		}
		
	}
	
	unset($si);
	
	/*
	/* only send if msgs in queue */
	
	if(count($smsQueue) > 0)
	{
	
		$si = new SmsInterface (false, false);
				
		if($si->connect($settings->smsuser, $settings->smspass, true, false))
		{
	
			foreach($smsQueue as $smsInfo)
				$si->addMessage($smsInfo[0], $smsInfo[1]);
				
			if(!$si->sendMessages ())
			{
				
				echo('<b class="negative">failed. Could not send message to server.</b>');
				
				if ($si->getResponseMessage () !== NULL)
					echo('<br />Reason: '. $si->getResponseMessage () .'</b>');
					
			}
			else
				echo('<b class="positive">SMS Notifications Sent</b>');
				
		}
	
	}

?>