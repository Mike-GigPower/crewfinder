<?

	/*
	/* global file */

	include('global.php');

	DEFINE('NAVBAR', 'accounting');

	$workslipID = isset($_GET['id']) ? intval($_GET['id']) : 0;

	/*
	/* check permissions */

	$user->checkPermissions(1);

	/*
	/* grab all bookings */

	$crumbs[] = array('url' => 'accounting/workslips', 'name' => 'Workslips');

	/*
	/* if generating this weeks workslips */

	if(isset($_GET['action']) && $_GET['action'] == 'generate')
	{

		$accounting->generateWorkslips();

	}

	/*
	/* get weekly payslips */

	if(isset($_GET['action']) && ( $_GET['action'] == 'getworkslips' || $_GET['action'] == 'print' || $_GET['action'] == 'pdf') )
	{

		$weekending = intval($_GET['id']);

		$invoiceLines = $db->select(
			'accounting.*, calls.start_date, calls.call_name, users.ein, users.firstname, users.lastname, bookings.name, paygrades.day_desc, paygrades.night_desc, paygrades.day_myob, paygrades.night_myob, paygrades.specialrate',
			'accounting
			LEFT JOIN calls ON accounting.callID=calls.id
			LEFT JOIN bookings ON accounting.bookingID=bookings.id
			LEFT JOIN users ON users.id=accounting.userID
			LEFT JOIN paygrades ON accounting.paygradeID=paygrades.id',
			'week_ending='. $weekending,
			'users.lastname, accounting.userID, calls.start_date ASC'
		);

		foreach ($invoiceLines as $key => $invoiceline) {
			$invoiceLines[$key]->calculated = json_decode($invoiceline->calculated);
		}

	}

	/*
	/* payslips listed by week */

	if(isset($_GET['action']) && $_GET['action'] == 'getworkslips')
	{

		$invoiceArray = array(
			implode(",", array(
				//'Emp. Co./Last Name',
				//'Emp. First Name',
				'Pay category external ID',
				//'Job',
				//'Cust. Co./Last Name',
				//'Cust. First Name',
				//'Notes',
				'Date',
				'Units',
				'Employee External ID',
			)
		));

		if(count($invoiceLines) > 0)
		{

			foreach($invoiceLines as $iLine)
			{
				if ( ! is_null($iLine->calculated) ) {
					foreach ($iLine->calculated as $calculated) {
						if ( $calculated->timeTotal == 0 ) {
							continue;
						}

						$invoiceArray[] = implode(',', array(
							//$iLine->lastname,
							//$iLine->firstname,
							$calculated->myobCode,
							//' ',
							//' ',
							//' ',
							//' ',
							date('d/m/y', $iLine->week_ending),
							$calculated->timeTotal,
							$iLine->ein,
						));
					}
				} else {
					if($iLine->day_hours > 0)
					{

						$invoiceArray[] = implode(",", array(
							//$iLine->lastname,
							//$iLine->firstname,
							$iLine->day_myob,
							//' ',
							//' ',
							//' ',
							//' ',
							date('d/m/y', $iLine->week_ending),
							$iLine->day_hours,
							$iLine->ein,
						));

					}

					if($iLine->night_hours > 0)
					{

						$invoiceArray[] = implode(",", array(
							//$iLine->lastname,
							//$iLine->firstname,
							$iLine->night_myob,
							//' ',
							//' ',
							//' ',
							//' ',
							date('d/m/y', $iLine->week_ending),
							$iLine->night_hours,
							$iLine->ein,
						));

					}
				}

			}

		}

		/*
		/* output csv */

		header("Content-type: text/csv");
		header("Cache-Control: no-store, no-cache");
		header('Content-Disposition: attachment; filename="'. date("Ymd") .' Wages.txt"');

		echo implode("\r\n", $invoiceArray);

		die();

	}

	/*
	/* prepare for printing */

	if(isset($_GET['action']) && ( $_GET['action'] == 'print' || $_GET['action'] == 'pdf') )
	{

		$smarty->assign('invoiceLines', $invoiceLines);

	}

	/*
	/* search */

	$searchQuery  = '';
	$searchFields = array();

	if (isset($_GET['start']))
	{

		$searchFields[] = 'week_ending >= UNIX_TIMESTAMP(' . $db->sc($_GET['start']) . ')';
		$smarty->assign('start', $_GET['start']);
		$searchQuery .= '&start=' . $_GET['start'];

	}

	if (isset($_GET['end']))
	{

		$searchFields[] = 'week_ending <= UNIX_TIMESTAMP(' . $db->sc($_GET['end'])   . ')';
		$smarty->assign('end', $_GET['end']);
		$searchQuery .= '&end=' . $_GET['end'];

	}

	$smarty->assign('searchFields', $searchQuery);

	/*
	/* pagination */

	$start   = isset($_GET['p']) ? intval($_GET['p']) : 0;
	$perpage = 80;

	/*
	/* get all payruns in the system */

	$searchFields[] = 'week_ending > 0';
	$workslipGroups = $db->select(
		'DISTINCT(week_ending) as weekEnding',
		'accounting',
		'(' . implode(') AND (', $searchFields) . ')',
		'weekEnding DESC',
		$start * $perpage .','. $perpage
	);
	$smarty->assign('workslips', $workslipGroups);

	$smarty->assign('totalResults', count($workslipGroups));
	$smarty->assign('perPage', $perpage);
	$smarty->assign('pageCount', @range(0, count($workslipGroups) / $perpage));
	$smarty->assign('startPage', $start);

	/*
	/* if printing */

	if(isset($_GET['action']) && $_GET['action'] == 'print')
	{

		$smarty->assign('body', 'print/workslips');
		$smarty->display('print/print-global.tpl');

		die();

	}

	/*
	/* output to PDF */

	if(isset($_GET['action']) && $_GET['action'] == 'pdf')
	{

		$smarty->assign('action', 'pdf');
		$smarty->assign('body', 'print/workslips');

		/*
		/* load domPDF */
		
		// 09/04/19
		// new code to make this work with the latest DOMPdf
		
		require_once 'lib/dompdf/autoload.inc.php';
		
		$options = new Dompdf\Options();
		$options->set('isRemoteEnabled', true);
		
		$dompdf = new Dompdf\Dompdf($options);

		/*
		/* set pdf options */

		$dompdf->load_html($smarty->fetch('print/print-global.tpl'));

		$dompdf->set_paper('a4', 'landscape');
		$dompdf->set_base_path(BASEPATH);

		/*
		/* save file to invoice directory */

		$dompdf->render();
		$dompdf->stream('workslip_'. $workslipID .'.pdf');
		//$pdf = $dompdf->output();
		//file_put_contents(INVOICE_DIR . $invoiceInfo->code . sprintf("%'03s", $invoiceInfo->invoiceNumber) . '.pdf', $pdf);

		die();

	}

	/*
	/* grab call details */

	$smarty->assign('body', 'workslips');
	$smarty->display('global.tpl');

?>
