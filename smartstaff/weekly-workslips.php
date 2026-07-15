<?php

	/*
	/* global file */
	
	include('global.php');
	
	DEFINE('NAVBAR', 'workslips');

	/*
	/* check premissions */

	$user->checkPermissions(3);

	$crumbs[] = array(
		'url'  => 'workslips',
		'name' => 'Workslips'
	);

	/*
	/* get weekly payslips */
	
	if(isset($_GET['action']) && $_GET['action'] == 'print')
	{
	
		$weekending = intval($_GET['id']);

		$invoiceLines = $db->select(
			'accounting.*, calls.start_date, calls.call_name, users.ein, users.firstname, users.lastname, bookings.name, paygrades.day_desc, paygrades.night_desc, paygrades.day_myob, paygrades.night_myob, paygrades.specialrate',
			'accounting
			LEFT JOIN calls ON accounting.callID=calls.id
			LEFT JOIN bookings ON accounting.bookingID=bookings.id
			LEFT JOIN users ON users.id=accounting.userID
			LEFT JOIN paygrades ON accounting.paygradeID=paygrades.id', 
			'week_ending='. $weekending . ' AND accounting.userID = '. $_SESSION[SITE_KEY]['userID'],
			'users.lastname, accounting.userID, calls.start_date DESC'
		);

		foreach ($invoiceLines as $key => $invoiceline) {
			$invoiceLines[$key]->calculated = json_decode($invoiceline->calculated);
		}

		$smarty->assign('invoiceLines', $invoiceLines);
		$smarty->assign('body', 'print/workslips');
		$smarty->display('print/print-global.tpl');

		die();
		
	}

	/*
	/* get all user payruns */

	$workslipGroups = $db->select(
		'DISTINCT(week_ending) as weekEnding',
		'accounting',
		'userID='. $_SESSION[SITE_KEY]['userID'].' AND week_ending > 0', 
		'week_ending DESC'
	);
	$smarty->assign('workslips', $workslipGroups);

	$smarty->assign('crumbs',    $crumbs);
	$smarty->assign('page_name', 'Workslips');
	$smarty->assign('body',      'weekly-workslips');

	if (isset($mobile_browser))
	{

		$smarty->display('mobile/workslips.tpl');

	}
	else
	{

		$smarty->display('global.tpl');

	}

?>
