<?php

	/**
	 * SmartStaffSolutions class
	 *
	 * @package 	GigPower
	 * @version		1.1.0
	 * 
	 */
	 
	class sss
	{
		
		/**
		 * instance of this class
		 * @var object
		 */

		private static $instance;
		
		/**
		 * construct the user class
		 *
		 */
		
		private function __construct()
		{
			
			$this->db 		= db::getInstance();
			$this->errors 	= errors::getInstance();

		}
		
		/**
    	* get this classes instance
    	*
    	* @return object
    	*/
    
   		public static function getInstance()
		{
			
    		if(!self::$instance)
    		{
    		
    			$className = __CLASS__;
				self::$instance = new $className();
        
			}

			return self::$instance;
    	
		}

		public function callLocked($callID)
		{

			$res = $this->db->selectFirst('call_locked', 'calls', 'id=' . $this->db->sc($callID));
			return ($res) ? true : false;

		}

		public function isUserCallBoss($callID, $userID = null)
		{

			if ($userID === null)
				$userID = $_SESSION[SITE_KEY]['userID'];

			$res = $this->db->selectFirst(
				'is_call_boss',
				'call_crew_map',
				'callID='. $this->db->sc($callID) . ' AND userID=' . $this->db->sc($userID)
			);

			if ($res && $res->is_call_boss == 1)
				return true;

			return false;

		}

		public function isUserBookingBoss($bookingID, $userID = null)
		{

			$calls = $this->db->select('id', 'calls', 'bookingID='. $this->db->sc($bookingID));

			if(count($calls) > 0)
			{

				foreach ($calls as $call)
				{

					if ($this->isUserCallBoss($call->id, $userID))
						return true;

				}
			
			}

			return false;

		}
		
		/**
		 * add to call
		 *
		 * @param int $groupID		minimum id level
		 * @return void
		 *
		 */
		 
		function addToCall($callID, $crewID)
		{

			if($callID > 0 && $crewID > 0)
			{

				/*
				/* get call info and massage/format it */

				$callInfo = $this->db->selectFirst('`start_date`, TIME_TO_SEC(`start_time`) AS `start_time`, `est_length`', 'calls', 'id='. $this->db->sc($callID));
				$callInfo->start = $callInfo->start_date + $callInfo->start_time;
				$callInfo->end   = $callInfo->start + intval($callInfo->est_length * 60 * 60);
				$callInfo->start = date('Y-m-d G:i:s', $callInfo->start);
				$callInfo->end   = date('Y-m-d G:i:s', $callInfo->end);

				/*
				/* build where clauses for checking for clashes */

				$overlap = array(
					'`start` BETWEEN ' . $this->db->sc($callInfo->start) . ' AND ' . $this->db->sc($callInfo->end),
					'`end`   BETWEEN ' . $this->db->sc($callInfo->start) . ' AND ' . $this->db->sc($callInfo->end),
					$this->db->sc($callInfo->start) . ' BETWEEN `start` AND `end`',
					$this->db->sc($callInfo->end)   . ' BETWEEN `start` AND `end`'
				);
				$overlap = '((' . implode(') OR (', $overlap) . '))';

				/*
				/* count clashes
				/*
				/* A `calendars` row is written when a crew member confirms, and is
				/* DELIBERATELY left in place when they later decline, no-show or are
				/* stood down (see update-crew-status.php) - a declined entry at a given
				/* time is what tells the operator not to re-offer a clashing call.
				/*
				/* This check used to count EVERY overlapping row, so a shift the crew
				/* member had turned down still refused an add, reported to ops as
				/* "clashes with an existing confirmed shift". Only a shift they are
				/* actually holding should block.
				/*
				/* `type` <> 2 rather than `type` = 1 is load-bearing: unavailability
				/* rows (type 1) carry no `call_crew_map` row at all, so testing status
				/* alone would silently stop unavailability blocking anything. Written
				/* as <> 2 so any future or unknown calendar type keeps blocking by
				/* default.
				/*
				/* Blocking set is status 5 only. Backup (7) is excluded per ops
				/* decision (Rich, 5 Sep 2026): being a backup must not stop crew being
				/* booked for other calls. */

				$held = '`call` IN (SELECT `callID` FROM `call_crew_map`'
				      . ' WHERE `userID` = ' . $this->db->sc($crewID)
				      . ' AND `status` = 5)';

				$clashes = $this->db->selectFirst(
					'COUNT(*) AS `num`',
					'calendars',
					'user=' . $this->db->sc($crewID) . ' AND ' . $overlap
						. ' AND (`type` <> 2 OR ' . $held . ')'
				);

				if ($clashes->num)
					return false;
			
				/*
				/* get crew info */
			
				$getCrewInfo = $this->db->selectFirst('*', 'users', 'id='. $this->db->sc($crewID));
				
				if($getCrewInfo)
				{
			
					/*
					/* remove any current listings */
					
					$this->db->delete('call_crew_map', 'callID='. $this->db->sc($callID) .' AND userID='. $this->db->sc($crewID));
					
					/*
					/* add member */
					
					$crewmapArray = array(
						'callID' => $this->db->sc($callID),
						'userID' => $this->db->sc($crewID),
					);
					
					if(in_array($getCrewInfo->paygradeID, array(10,25,26)))
					{

						/*
						/* check if call was on a sunday/public holiday */

						$is_sunday = (strncasecmp('sun', date('D', $callInfo->start_date), 3) == 0);
						$res       = $this->db->selectFirst('`is_pubhol`', '`calls`', '`id` = ' . $this->db->sc($callID));
						$is_pubhol = $res->is_pubhol;

						if ($is_pubhol || $is_sunday)
						{

							if ($getCrewInfo->paygradeID == 10)
							{

								$getCrewInfo->paygradeID = 38;

							}
							else if ($getCrewInfo->paygradeID == 25)
							{

								$getCrewInfo->paygradeID = 40;

							}
							else if ($getCrewInfo->paygradeID == 26)
							{

								$getCrewInfo->paygradeID = 39;

							}

						}
					
						$crewmapArray += array(
							'callpaygradeID' => $this->db->sc($getCrewInfo->paygradeID),
						);
						
					}
						
					$this->db->insert('call_crew_map', $crewmapArray);
				
					/*
					/* recalculate member count */
					
					$mCount = $this->db->selectFirst('count(*) as count', 'call_crew_map', 'callID='. $this->db->sc($callID));
					$this->db->update('calls', array('ordered' => $this->db->sc($mCount->count)), 'id='. $this->db->sc($callID));

					return true;

				}
			
			}
		
		}
		
		
		/**
		 * add call to calendar
		 *
		 * @param int $callID		callD level
		 * @param int $callID		callD level
		 * @return								bool
		 *
		 */
		 
		function addToCalendar($callID, $crewID)
		{
		
			/*
			/* add call to crew member's calendar */

			$call    = $this->db->selectFirst('*', 'calls',    'id='. $this->db->sc($callID));
			$booking = $this->db->selectFirst('*', 'bookings', 'id='. $this->db->sc($call->bookingID));
			
			/*
			/* remove any existing calendar entries */
			
			$this->removeFromCalendar($callID, $crewID);
			
			/*
			/* add calendar entry */

			$calendarArray = array(
				'user'  => $this->db->sc($crewID),
				'title' => $this->db->sc($booking->name . ' - ' . $call->call_name),
				'start' => 'ADDTIME(FROM_UNIXTIME('. intval($call->start_date) .'), '. $this->db->sc($call->start_time) .')',
				'end'   => 'ADDTIME(FROM_UNIXTIME('. (intval($call->start_date) + (intval($call->est_length) * 3600)) .'), '. $this->db->sc($call->start_time) .')',
				'type'  => '2',
				'call'  => $this->db->sc($callID)
			);

			$this->db->insert('calendars', $calendarArray);
			
		}
		
		/**
		 * remove call from calendar
		 *
		 * @param int $groupID		minimum id level
		 * @return								bool
		 *
		 */
		 
		function removeFromCalendar($callID, $crewID)
		{
		
			$this->db->delete('calendars', '`call`='. $this->db->sc($callID) .' AND `user`='. $this->db->sc($crewID));
		
		}


		/**
		 * Delete a booking (and associated assets) from the system.
		 * @param id Unique ID of booking (in database)
		 * @returns void
		 */
		public function deleteBooking($id)
		{

			$db    = db::getInstance();
			$calls = $this->db->select('id', 'calls', 'bookingID='. intval($id));

			foreach ($calls as $call)
				$this->deleteCall($call->id);

			$this->db->delete('accounting',    'bookingID='. intval($id));
			$this->db->delete('invoice_lines', 'bookingID='. intval($id));
			$this->db->delete('invoices',      'bookingID='. intval($id));
			$this->db->delete('bookings',      'id='.        intval($id));

		}


		/**
		 * Delete a call (and associated assets) from the system.
		 * @param id Unique ID of call (in database)
		 * @returns void
		 */
		public function deleteCall($id)
		{

			$db = db::getInstance();
			$this->db->delete('calendars',     'call='.   intval($id));
			$this->db->delete('call_crew_map', 'callID='. intval($id));
			$this->db->delete('calls',         'id='.     intval($id));

		}


		/**
		 * Delete a crew member (and associated assets) from the system.
		 * @param id Unique ID of crew member (in database)
		 * @returns void
		 */
		public function deleteCrew($id)
		{

			$db = db::getInstance();
			$this->db->delete('customer_map', 'userID='. intval($id));
			$this->db->delete('users',        'id='.     intval($id) .' AND usergroupID=3');

		}


		/**
		 * Delete a customer (and associated assets) from the system.
		 * @param id Unique ID of customer (in database)
		 * @returns void
		 */
		public function deleteCustomer($id)
		{

			$db = db::getInstance();
			$this->db->delete('customer_map', 'customerID='. intval($id));
			$this->db->delete('customers',    'id='.         intval($id));

		}


		/**
		 * Delete a contact from the system.
		 * @param id Unique ID of contact (in database)
		 * @returns void
		 */
		public function deleteContact($id)
		{

			$db = db::getInstance();
			$this->db->delete('users', 'id='. intval($id). ' AND usergroupID=4');

		}


		/**
		 * Delete a venue from the system.
		 * @param id Unique ID of venue (in database)
		 * @returns void
		 */
		public function deleteVenue($id)
		{

			$db = db::getInstance();
			$this->db->delete('venues', 'id='. intval($id));

		}
		
		/**
		 * Delete an agreement from the system
		 * @param id Unique ID of venue (in database)
		 * @returns void
		 */
		public function deleteAgreement($id)
		{

			$db = db::getInstance();
			$this->db->delete('agreements', 'id='. intval($id));

		}

	}
	
?>
