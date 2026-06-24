<?

	/*
	/* global file */
	
	include('global.php');

	DEFINE('NAVBAR', 'crew');
	
	$crumbs[] = array('url' => 'crew', 'name' => 'Crew');
	
	/*
	/* check permissions */
	
	$user->checkPermissions(1);

	/*
	/* select all crew groups */
	
	$crewGroups = $db->select('*', 'crew_groups');
	$smarty->assign('crewGroups', $crewGroups);
	
	/*
	/* get next EIN */
	
	$nextEIN = $db->selectFirst('max(ein) as maxEIN', 'users');
	$smarty->assign('nextEIN', $nextEIN->maxEIN+1);
	
	/*
	/* tax scales */
	
	$taxScales = $db->select('*', 'tax_scales');
	$smarty->assign('taxScales', $taxScales);
	
	/*
	/* paygrades */
	
	$paygrades = $db->select('*', 'paygrades', 'id IN(10,25,26)');
	$smarty->assign('paygrades', $paygrades);
	
	/*
	/* get user info if editing */
	
	$crewGroupList = array();
	
	if($_GET['action'] == 'edit')
	{
		
		$cDetails = $db->selectFirst('*', 'users', 'id='. intval($_GET['id']));
		$smarty->assign('cDetails', $cDetails);
		
		$crumbs[] = array('url' => 'crew/manage/'. intval($_GET['id']), 'name' => $cDetails->firstname .' '. $cDetails->lastname);
		
		/*
		/* grab crew groups */
		
		$getCrewGroups = $db->select('groupID', 'crew_groups_map', 'userID='. intval($cDetails->id));

		if(count($getCrewGroups) > 0)
			foreach($getCrewGroups as $row)
				$crewGroupList[] = $row->groupID;

		/*
		/* add flag to groups where needed */
		
		if(count($crewGroups) > 0)
			foreach($crewGroups as $row)
				if(in_array($row->id, $crewGroupList))
					$row->is_member = true;
	
	}
	else
	{
	
		$crumbs[] = array('url' => 'crew/add', 'name' => 'Add Crew');
	
	}

	/*
	/* adding a new crew member */
	
	if($_POST['action'] == 'add')
	{
	
		$errors = array();
	
		/*
		/* validate fields */
	
		$requiredFields = array();
		
		/*
		/* check username is ok */
		
		if($cDetails->username != $_POST['username'] || $_GET['action'] == "add")
		{
		
			$usercheck = $db->selectFirst('count(*) as count', 'users', 'username='. $db->sc($_POST['username']));
			if($usercheck->count > 0)
				$errors[] = 'Username in use - please try another.';
				
		}
		
		/*
		/* AJAX: return validation errors as plain text instead of re-rendering
		/* the page, so THE GOAT's Add User proxy gets a usable message. */
		
		if(isset($_POST['ajax']) && count($errors) > 0)
		{
			header('Content-type: text/plain');
			http_response_code(409);
			die('ERROR: '. implode(' ', $errors));
		}
		
		/*
		/* if no errors, add to database */
		
		if(count($errors) == 0)
		{
	
			$salt = sha1(microtime());
			
			
		
			$dataArray = array(
				'username' 		=> $db->sc(filter_var($_POST['username'], FILTER_SANITIZE_STRING)),
				'active'		=> $db->sc(filter_var($_POST['active'], FILTER_SANITIZE_STRING)),
				'rating'		=> $db->sc(filter_var($_POST['rating'], FILTER_SANITIZE_STRING)),
				'firstname'		=> $db->sc(filter_var($_POST['firstname'], FILTER_SANITIZE_STRING)),
				'lastname'		=> $db->sc(filter_var($_POST['lastname'], FILTER_SANITIZE_STRING)),
				'ein'			=> $db->sc(filter_var($_POST['ein'], FILTER_SANITIZE_STRING)),
				'mobile'		=> "'". $_POST['mobile'] ."'",
				'phone'			=> "'". $_POST['phone'] ."'",
				'phone_work'	=> "'". $_POST['phone_work'] ."'",
				'dob'			=> $db->sc(strtotime(filter_var($_POST['dob'], FILTER_SANITIZE_STRING))),
				'address'		=> $db->sc(filter_var($_POST['address'], FILTER_SANITIZE_STRING)),
				'suburb'		=> $db->sc(filter_var($_POST['suburb'], FILTER_SANITIZE_STRING)),
				'state'			=> $db->sc(filter_var($_POST['state'], FILTER_SANITIZE_STRING)),
				'postcode'		=> $db->sc(filter_var($_POST['postcode'], FILTER_SANITIZE_STRING)),
				'notes'			=> $db->sc(filter_var($_POST['notes'], FILTER_SANITIZE_STRING)),
				'email'			=> $db->sc(filter_var($_POST['email'], FILTER_SANITIZE_STRING)),
				
				'emergency_contact'	=> $db->sc(filter_var($_POST['emergency_contact'], FILTER_SANITIZE_STRING)),
				'emergency_phone'	=> $db->sc(filter_var($_POST['emergency_phone'], FILTER_SANITIZE_STRING)),
				
				'paygradeID'	=> $db->sc(filter_var($_POST['paygradeID'], FILTER_SANITIZE_STRING)),
				
				'tax_file_no_supplied'	=> $db->sc(filter_var($_POST['tax_file_no_supplied'], FILTER_SANITIZE_STRING)),
				'tax_free_threshold'	=> $db->sc(filter_var($_POST['tax_free_threshold'], FILTER_SANITIZE_STRING)),
				'tfn'					=> $db->sc(filter_var($_POST['tfn'], FILTER_SANITIZE_STRING)),
				//'union'					=> $db->sc(filter_var($_POST['union'], FILTER_SANITIZE_STRING)),
				
				//'super'					=> $db->sc(filter_var($_POST['super'], FILTER_SANITIZE_STRING)),
				'tax_scale'				=> $db->sc(filter_var($_POST['tax_scale'], FILTER_SANITIZE_STRING)),
				
				// old variables imported over
				//'subcontractor'			=> $db->sc(filter_var($_POST['subcontractor'], FILTER_SANITIZE_STRING)),
				//'new_employee'			=> $db->sc(filter_var($_POST['new_employee'], FILTER_SANITIZE_STRING)),
				//'pp_post'				=> $db->sc(filter_var($_POST['pp_post'], FILTER_SANITIZE_STRING)),
				//'pp_mod_employee'		=> $db->sc(filter_var($_POST['pp_mod_employee'], FILTER_SANITIZE_STRING)),
			);

			/*
			/* update password if set */
			
			if($_POST['password'] != '')
			{
			
				$dataArray += array(
					'password' 		=> $db->sc(filter_var(sha1($_POST['password'] . $salt), FILTER_SANITIZE_STRING)),
					'salt'			=> $db->sc($salt),
				);

			}
			
			/*
			/* generate hash of user information for triggering updates */

			$userInfo = array(
				$dataArray['firstname'],
				$dataArray['lastname'],
				$dataArray['mobile'],
				$dataArray['phone'],
				$dataArray['phone_work'],
				$dataArray['dob'],
				$dataArray['address'],
				$dataArray['suburb'],
				$dataArray['state'],
				$dataArray['postcode'],
				$dataArray['email'],
			);

			$oldInfoHash = $db->selectFirst('info_hash', 'users', 'id='. intval($_GET['id']))->info_hash;
			$newInfoHash = $db->sc(sha1(implode('', $userInfo)));

			/*
			/* initialise hashes */

			if($oldInfoHash == '')
			{

				$info = $db->selectFirst(
					'firstname, lastname, mobile,
					phone, phone_work, dob, address,
					suburb, state, postcode, email',
					'users',
					'id='. intval($_GET['id'])
				);

				$oldInfoHash = $db->sc(sha1(implode('', (array) $info)));
				$db->update('users', array('info_hash' => $oldInfoHash), 'id='. intval($_GET['id']));

			}
			
			/*
			/* editing */
			
			if($_GET['action'] == 'edit')
			{
			
				if($oldInfoHash != $newInfoHash)
				{

					$dataArray['info_hash'] = $newInfoHash;
					$dataArray['updated']   = TRUE;

				}
			
				$db->update('users', $dataArray, 'id='. intval($_GET['id']));
				$savedUserID = intval($_GET['id']);
				
			}
			
			/*
			/* adding a brand new user */
			
			else
			{
			
				$dataArray += array(
					'start_date'	=> strtotime('now'),
					'new_employee'	=> $db->sc('TRUE'),
					'usergroupID'	=> $db->sc(3),
					'info_hash'     => $newInfoHash,
					'updated'       => TRUE
				);
			
				$db->insert('users', $dataArray);
				$savedUserID = $db->insert_id();

			}
			
			/*
			/* flush groups table */
			
			$db->delete('crew_groups_map', 'userID='. $savedUserID);
			
			/*
			/* update crew group membership */
			
			if(is_array($_POST['groupsList']) && count($_POST['groupsList']) > 0)
			{

				/*
				/* add updated entries */
			
				foreach($_POST['groupsList'] as $groupID)
				{
				
					$db->insert('crew_groups_map', array('userID' => intval($savedUserID), 'groupID' => intval($groupID)));
				
				}	
			
			}
			
			/*
			/* save profile image */
			
			if(is_uploaded_file($_FILES['profilepic']['tmp_name']))
			{
				
				require_once('lib/phpThumb/phpthumb.class.php');
				$phpThumb = new phpThumb();

				$phpThumb->setParameter('config_output_format', 'jpeg');
				$phpThumb->setSourceFilename($_FILES['profilepic']['tmp_name']);
				$phpThumb->setParameter('zc', 1);
				$phpThumb->setParameter('w', 125);
				$phpThumb->setParameter('h', 138);
				$phpThumb->GenerateThumbnail();
				
				$res = $phpThumb->RenderToFile(BASEPATH .'/images/crewpics/crewimg_'. $savedUserID .'.jpg');

				//echo '<pre>'.implode("\n\n", $phpThumb->debugmessages).'</pre>';
			
			}

			/*
			/* if accessed via AJAX, return the new user id (mirrors add-venue.php
			/* / add-customer.php) instead of redirecting to the manage page */
			
			if(isset($_POST['ajax']))
			{
				header('Content-type: text/plain');
				die("{$savedUserID}");
			}
			
			/*
			/* redirect to editing page */
		
			header('location: '. BASEURL .'crew/manage/'. $savedUserID);
			
		}
		
	
	}
	
	
	/*
	/* output template */
	
	$smarty->assign('crumbs', $crumbs);
	$smarty->assign('body', 'add-crew');
	$smarty->display('global.tpl');

?>