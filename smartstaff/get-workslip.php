<?php

	/*
	/* global file */

	include('../../global.php');
	include('cohort.php');

	/*
	/* SELF endpoint — streams ONE of the acting user's workslips (a given pay
	/* week) as a PDF, for the crew app's "View PDF" button. Self-scoped via
	/* goat_acting_user_id() (session OR service key). The accounting query is
	/* hard-scoped to that userID, so a crew member can only ever fetch their
	/* OWN workslip — never someone else's.
	/*
	/* Mirrors the print/pdf path of weekly-workslips.php + workslips.php.
	/*
	/* Params:  ?week_ending=<unix>   (userID comes from goat_acting_user_id())
	/* Returns: application/pdf  (streamed inline)
	*/

	$userID = goat_acting_user_id();

	$weekending = isset($_GET['week_ending']) ? intval($_GET['week_ending']) : 0;

	if ($weekending <= 0)
	{
		http_response_code(400);
		die('missing week_ending');
	}

	/*
	/* this user's accounting lines for the week (scoped to $userID) */

	$invoiceLines = $db->select(
		'accounting.*, calls.start_date, calls.call_name, users.ein, users.firstname, users.lastname, bookings.name, paygrades.day_desc, paygrades.night_desc, paygrades.day_myob, paygrades.night_myob, paygrades.specialrate',
		'accounting
		LEFT JOIN calls ON accounting.callID=calls.id
		LEFT JOIN bookings ON accounting.bookingID=bookings.id
		LEFT JOIN users ON users.id=accounting.userID
		LEFT JOIN paygrades ON accounting.paygradeID=paygrades.id',
		'week_ending = ' . $weekending . ' AND accounting.userID = ' . (int) $userID,
		'users.lastname, accounting.userID, calls.start_date DESC'
	);

	if (count($invoiceLines) === 0)
	{
		http_response_code(404);
		die('no workslip for that week');
	}

	foreach ($invoiceLines as $key => $invoiceline)
	{
		$invoiceLines[$key]->calculated = json_decode($invoiceline->calculated);
	}

	$smarty->assign('invoiceLines', $invoiceLines);
	$smarty->assign('action', 'pdf');
	$smarty->assign('body', 'print/workslips');

	/*
	/* render to PDF via domPDF (same as workslips.php pdf action). Paths are
	/* ../../ because this endpoint lives in /ajax/crew/. */

	require_once '../../lib/dompdf/autoload.inc.php';

	$options = new Dompdf\Options();
	$options->set('isRemoteEnabled', true);

	$dompdf = new Dompdf\Dompdf($options);
	$dompdf->load_html($smarty->fetch('print/print-global.tpl'));
	$dompdf->set_paper('a4', 'landscape');
	$dompdf->set_base_path(BASEPATH);
	$dompdf->render();

	/* stream inline (Attachment => false) so the portal can proxy it through */
	$dompdf->stream('workslip_' . $weekending . '.pdf', array('Attachment' => false));

	die();

?>
