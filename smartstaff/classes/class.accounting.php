<?php

/**
 * accounting class
 *
 * @package     GigPower
 * @version     1.1.0
 *
 */
class accounting
{

    /**
     * instance of this class
     * @var object
     */
    private static $instance;

    /**
     * array of csv fields for MYOB user exports
     * @var array
     */
    private $user_csv_fields;
    private $invoice_csv_fields;

    private $myob_prefix = 'myob-';

    /**
     * construct
     *
     */
    private function __construct()
    {
        $this->db        = db::getInstance();
        $this->errors    = errors::getInstance();

        $this->user_csv_fields = array(
            'Employee Code',
            'Last Name',
            'First Names',
            'Address 1',
            'Address 2',
            'Suburb',
            'State',
            'Post Code',
            'Country',
            'Date of Birth',
            'Sex',
            'Phone #',
            'Position',
            'Department',
            'Location',
            'Tax File #',
            'Tax Scale',
            'Paid By',
            'BSB Code',
            'Account #',
            'Account Name',
            'Basis',
            'Status',
            'Medicare Rebate',
            '# Dependants',
            'Charge-Out Level',
            'Total Rebates',
            'Pay Frequency',
            'Hrs/Week',
            'Hrs/Day',
            'Annual Salary',
            'Pay Rate',
            'Frequency Rate',
            'Entitlement',
            'Start Date',
            'Termination Date',
            'Termination Memo',
            'Terminated By',
            'Termination Method',
            'Bona Fide Redundancy',
            'Calculate Leave',
            'Member Number',
            'Super Fund',
            'RFBA',
            'Phone #2',
            'E-mail',
            'Emergency Contact Name',
            'Emergency Contact Number',
            'Employment Basis'
        );

        $this->customer_csv_fields = array(
            'Co./Last Name',
            'First Name',
            'Card ID',
            'Card Status',
            'Currency Code',
            'Addr 1 - Line 1',
            'Addr 1 - Line 2',
            'Addr 1 - Line 3',
            'Addr 1 - Line 4',
            'Addr 1 - City',
            'Addr 1 - State',
            'Addr 1 - Postcode',
            'Addr 1 - Country',
            'Addr 1 - Phone # 1',
            'Addr 1 - Phone # 2',
            'Addr 1 - Phone # 3',
            'Addr 1 - Fax #',
            'Addr 1 - Email',
            'Addr 1 - WWW',
            'Addr 1 - Contact Name',
            'Addr 1 - Salutation',
            'Addr 2 - Line 1',
            'Addr 2 - Line 2',
            'Addr 2 - Line 3',
            'Addr 2 - Line 4',
            'Addr 2 - City',
            'Addr 2 - State',
            'Addr 2 - Postcode',
            'Addr 2 - Country',
            'Addr 2 - Phone # 1',
            'Addr 2 - Phone # 2',
            'Addr 2 - Phone # 3',
            'Addr 2 - Fax #',
            'Addr 2 - Email',
            'Addr 2 - WWW',
            'Addr 2 - Contact Name',
            'Addr 2 - Salutation',
            'Addr 3 - Line 1',
            'Addr 3 - Line 2',
            'Addr 3 - Line 3',
            'Addr 3 - Line 4',
            'Addr 3 - City',
            'Addr 3 - State',
            'Addr 3 - Postcode',
            'Addr 3 - Country',
            'Addr 3 - Phone # 1',
            'Addr 3 - Phone # 2',
            'Addr 3 - Phone # 3',
            'Addr 3 - Fax #',
            'Addr 3 - Email',
            'Addr 3 - WWW',
            'Addr 3 - Contact Name',
            'Addr 3 - Salutation',
            'Addr 4 - Line 1',
            'Addr 4 - Line 2',
            'Addr 4 - Line 3',
            'Addr 4 - Line 4',
            'Addr 4 - City',
            'Addr 4 - State',
            'Addr 4 - Postcode',
            'Addr 4 - Country',
            'Addr 4 - Phone # 1',
            'Addr 4 - Phone # 2',
            'Addr 4 - Phone # 3',
            'Addr 4 - Fax #',
            'Addr 4 - Email',
            'Addr 4 - WWW',
            'Addr 4 - Contact Name',
            'Addr 4 - Salutation',
            'Addr 5 - Line 1',
            'Addr 5 - Line 2',
            'Addr 5 - Line 3',
            'Addr 5 - Line 4',
            'Addr 5 - City',
            'Addr 5 - State',
            'Addr 5 - Postcode',
            'Addr 5 - Country',
            'Addr 5 - Phone # 1',
            'Addr 5 - Phone # 2',
            'Addr 5 - Phone # 3',
            'Addr 5 - Fax #',
            'Addr 5 - Email',
            'Addr 5 - WWW',
            'Addr 5 - Contact Name',
            'Addr 5 - Salutation',
            'Picture',
            'Notes',
            'Identifiers',
            'Custom List 1',
            'Custom List 2',
            'Custom List 3',
            'Custom Field 1',
            'Custom Field 2',
            'Custom Field 3',
            'Billing Rate',
            'Terms - Payment is Due',
            '           - Discount Days',
            '           - Balance Due Days',
            '           - % Discount',
            '           - % Monthly Charge',
            'Tax Code',
            'Credit Limit',
            'Tax ID No.',
            'Volume Discount %',
            'Sales/Purchase Layout',
            'Price Level',
            'Payment Method',
            'Payment Notes',
            'Name on Card',
            'Card Number',
            'Expiry Date',
            'BSB',
            'Account Number',
            'Account Name',
            'A.B.N. ',
            'A.B.N. Branch',
            'Account',
            'Salesperson',
            'Salesperson Card ID',
            'Comment',
            'Shipping Method',
            'Printed Form',
            'Freight Tax Code',
            'Use Customer\'s Tax Code',
            'Receipt Memo',
            'Invoice/Purchase Order Delivery',
            'Record ID'

            /* 'customer_name', */
            /* 'terms', */

            /* 'phone', */
            /* 'fax', */
            /* 'email', */

            /* 'address', */
            /* 'suburb', */
            /* 'state', */
            /* 'postcode', */

            /* 'address_billing', */
            /* 'suburb_billing', */
            /* 'state_billing', */
            /* 'postcode_billing' */
        );

        // The old MYOB fields
        /*$this->invoice_csv_fields = array(
            'Co./Last Name',
            'First Name',
            'Addr 1 - Line 1',
            '           - Line 2',
            '           - Line 3',
            '           - Line 4',
            'Inclusive',
            'Invoice #',
            'Date',
            'Customer PO',
            'Ship Via',
            'Delivery Status',
            'Description',
            'Account #',
            'Amount',
            'Inc-Tax Amount',
            'Job',
            'Comment',
            'Journal Memo',
            'Salesperson Last Name',
            'Salesperson First Name',
            'Shipping Date',
            'Referral Source',
            'Tax Code',
            'Non-GST Amount',
            'GST Amount',
            'LCT Amount',
            'Freight Amount',
            'Inc-Tax Freight Amount',
            'Freight Tax Code',
            'Freight Non-GST Amount',
            'Freight GST Amount',
            'Freight LCT Amount',
            'Sale Status',
            'Currency Code',
            'Exchange Rate',
            'Terms - Payment is Due',
            '           - Discount Days',
            '           - Balance Due Days',
            '           - % Discount',
            '           - % Monthly Charge',
            'Amount Paid',
            'Payment Method',
            'Payment Notes',
            'Name on Card',
            'Card Number',
            'Expiry Date',
            'Authorisation Code',
            'BSB',
            'Account Number',
            'Drawer/Account Name',
            'Cheque Number',
            'Category',
            'Card ID',
            'Record ID'
        );*/
        
        $this->invoice_csv_fields = array(
            'ContactName',
            'EmailAddress',
            'POAddressLine1',
            'POAddressLine2',
            'POAddressLine3',
            'POAddressLine4',
            'POCity',
            'PORegion',
            'POPostalCode',
            'POCountry',
            'InvoiceNumber',
            'Reference',
            'InvoiceDate',
            'DueDate',
            'InventoryItemCode',
            'Description',
            'Quantity',
            'UnitAmount',
            'Discount',
            'AccountCode',
            'TaxType',
            'TrackingName1',
            'TrackingOption1',
            'TrackingName2',
            'TrackingOption2',
            'Currency',
            'BrandingTheme',
        );

    }


    /**
     * get this classes instance
     *
     * @return object
     */
    public static function getInstance()
    {
        if (! self::$instance) {
            $className      = __CLASS__;
            self::$instance = new $className();
        }

        return self::$instance;
    }


    /**
     * generate accounting data from a booking
     *
     * @param int $bookingID bookingID to generate
     * @param int $invoiceID
     *
     * @return bool
     */
    public function generateAccountingData($bookingID, $invoiceID=0)
    {
        if ($invoiceID == 0) {
            $invoiceArray = array(
                'bookingID'         => $this->db->sc($bookingID),
                'invoice_generated' => $this->db->sc(strtotime('now'))
            );

            $this->db->insert('invoices', $invoiceArray);
            $invoiceID = $this->db->insert_id();

            /*
            /* generate a list of all closed calls for bookings */

            $calls = $this->db->select('id', 'calls', 'bookingID='. $this->db->sc($bookingID) .' AND call_locked=1 AND invoiceID = 0');

            $callList = array();

            if (count($calls) > 0) {
                foreach ($calls as $call) {
                    $callList[] = $call->id;
                }
            }

            $callList = join(',', $callList);

            /*
            /* associate calls with invoice */
            $this->db->update('calls', array( 'invoiceID' => $this->db->sc($invoiceID), ), 'id IN (' . $callList . ')');

            /*
            /* update with invoice info */
            $this->db->update(
                'invoices',
                array(
                    'invoice_status'    => 1,
                    'invoice_generated' => $this->db->sc(strtotime('now')),
                    'call_list'         => $this->db->sc($callList)
                ),
                'id=' . $this->db->sc($invoiceID)
            );

            /*
            /* generate invoice lines */

            $invoiceLines = $this->db->select(
                'count(*) as noOfCrew, SUM(day_chargeout*day_hours+night_chargeout*night_hours) as lineTotal,
                 accounting.*, TIME_TO_SEC(call_crew_map.on) AS `on`, TIME_TO_SEC(call_crew_map.off) AS off, calls.start_date, paygrades.day_desc, paygrades.night_desc, paygrades.id AS paygrade,
                 bookings.name, calls.call_name, calls.id AS callID',
                'accounting
                 LEFT JOIN bookings ON accounting.bookingID=bookings.id
                 LEFT JOIN calls ON accounting.callID=calls.id
                 LEFT JOIN call_crew_map ON call_crew_map.crewmapID=accounting.callcrewmapID
                 LEFT JOIN paygrades ON paygrades.id=accounting.paygradeID',
                'calls.invoice_generated=0 AND calls.call_locked=1 AND (call_crew_map.status=5 OR (call_crew_map.status=9 AND call_crew_map.prev_status=5 AND (call_crew_map.cancel_pay=1 OR call_crew_map.cancel_charge=1)))
                 AND accounting.bookingID='. $this->db->sc($bookingID) .' GROUP BY accounting.callID,
                 call_crew_map.on, call_crew_map.off, CONCAT(call_crew_map.on, call_crew_map.off, call_crew_map.break, call_crew_map.break_night),
                 accounting.day_hours, accounting.day_chargeout'
            );
        } else {
            /*
            /* clear invoice data before starting */
            $this->db->delete('invoice_lines', 'invoiceID='. $this->db->sc($invoiceID));

            /*
            /* get list of calls used when invoice was last generated */
            $invoiceInfo = $this->db->selectFirst('call_list', 'id='. $this->db->sc($invoiceID));
            $callList    = $invoiceInfo->call_list;

            if (trim($callList) == '') {
                $callList = '1';
            } else {
                $callList = 'calls.id IN (' . $this->db->sc($callList) . ')';
            }

            /*
            /* generate invoice lines */
            $invoiceLines = $this->db->select(
                'count(*) as noOfCrew, SUM(day_chargeout*day_hours+night_chargeout*night_hours) as lineTotal,
                 accounting.*, TIME_TO_SEC(call_crew_map.on) AS `on`, TIME_TO_SEC(call_crew_map.off) AS off,
                 calls.start_date, paygrades.day_desc, paygrades.night_desc, paygrades.id AS paygrade,
                 bookings.name, calls.call_name, calls.id AS callID',
                'accounting
                 LEFT JOIN bookings ON accounting.bookingID=bookings.id
                 LEFT JOIN calls ON accounting.callID=calls.id
                 LEFT JOIN call_crew_map ON call_crew_map.crewmapID=accounting.callcrewmapID
                 LEFT JOIN paygrades ON paygrades.id=accounting.paygradeID',
                 $callList .' AND calls.call_locked=1
                 AND (call_crew_map.status=5 OR (call_crew_map.status=9 AND call_crew_map.prev_status=5 AND (call_crew_map.cancel_pay=1 OR call_crew_map.cancel_charge=1))) AND accounting.bookingID='. $this->db->sc($bookingID) .' GROUP BY accounting.callID,
                 call_crew_map.on, call_crew_map.off, CONCAT(call_crew_map.on, call_crew_map.off, call_crew_map.break, call_crew_map.break_night),
                 accounting.day_hours, accounting.day_chargeout'
            );
        }

        if (count($invoiceLines) > 0) {
            /*
            /* get sunday rates */
            $sunday_rates = $this->db->select('*', 'paygrades', 'id IN (27, 28, 29)', 'id');
            $sunday   = $sunday_rates[0];
            $sunday_a = $sunday_rates[1];
            $sunday_b = $sunday_rates[2];

            /*
            /* get holiday rates */
            $pubhol_rates = $this->db->select('*', 'paygrades', 'id IN (30, 31, 32)', 'id');
            $pubhol   = $pubhol_rates[0];
            $pubhol_a = $pubhol_rates[1];
            $pubhol_b = $pubhol_rates[2];

            foreach ($invoiceLines as $row) {
                if (! is_null($row->calculated)) {
                    $calculated_hours = json_decode($row->calculated);

                    foreach ($calculated_hours as $calculated) {
                        if ($calculated->timeTotal == 0) {
                            continue;
                        }

                        $break_time = '00:00';

                        if (intval($calculated->timeBreak) > 0) {
                            $break_time = gmdate('H:i', $calculated->timeBreak);
                        }

                        $iArray = array(
                            'bookingID'      => $this->db->sc($bookingID),
                            'invoiceID'      => $this->db->sc($invoiceID),
                            'callID'         => $this->db->sc($row->callID),
                            'start_date'     => $this->db->sc($calculated->timeStart),
                            'start_time'     => $this->db->sc(date('H:i', $calculated->timeStart)),
                            'end_time'       => $this->db->sc(date('H:i', $calculated->timeEnd)),
                            'hours'          => $this->db->sc($calculated->timeTotal),
                            'chargeout_rate' => $this->db->sc($calculated->chargeout),
                            'description'    => $this->db->sc(sprintf('%s <i style="font-size: 9px;">(%s/%s)</i>', $row->call_name, $this->getTimeOfDay($calculated->timeStart), $calculated->description)),
                            'qty'            => $this->db->sc($row->noOfCrew),
                            'break_time'     => is_null($break_time) ? 'NULL' : $this->db->sc($break_time),
                        );

                        $this->db->insert('invoice_lines', $iArray);
                    }

                    // Add a blank invoice line
                    $this->db->insert('invoice_lines', array(
                        'bookingID'   => $this->db->sc($bookingID),
                        'invoiceID'   => $this->db->sc($invoiceID),
                        'callID'      => $this->db->sc($row->callID),
                        'description' => $this->db->sc('<< BLANK_LINE >>'),
                        'qty'         => 1,
                    ));
                } else {
                    /*
                    /* check if call was on a sunday/public holiday */
                    $is_sunday = date('N', $row->start_date) == 7; // Not using === because date('N') returns a string
                    $res       = $this->db->selectFirst('is_pubhol', 'calls', 'id = ' . $this->db->sc($row->callID));
                    $is_pubhol = $res->is_pubhol;

                    // day hours
                    if ($row->day_hours > 0) {
                        /*
                        /* day hours start at 8am and run until 8pm, so cap this record */
                        $on  = max(8 * 60 * 60, $row->on);
                        $off = ($row->off < $row->on) ? 20 * 60 * 60 : min(20 * 60 * 60, $row->off);

                        /*
                        /* if call was on a sunday/public holiday, transform rates appropriately */
                        if ($is_pubhol) {
                            if ($row->paygrade == '10') {
                                /*
                                /* T1 -> PubHol */
                                $row->day_chargeout = $pubhol->co_rate;
                                $row->day_desc      = $pubhol->day_desc;
                            } elseif ($row->paygrade == '25') {
                                /*
                                /* T1A -> PubHol/A */
                                $row->day_chargeout = $pubhol_a->co_rate;
                                $row->day_desc      = $pubhol_a->day_desc;
                            } elseif ($row->paygrade == '26') {
                                /*
                                /* T1B -> PubHol/B */
                                $row->day_chargeout = $pubhol_b->co_rate;
                                $row->day_desc      = $pubhol_b->day_desc;
                            }
                        } elseif ($is_sunday) {
                            if ($row->paygrade == '10') {
                                /*
                                /* T1 -> Sunday */
                                $row->day_chargeout = $sunday->co_rate;
                                $row->day_desc      = $sunday->day_desc;
                            } elseif ($row->paygrade == '25') {
                                /*
                                /* T1A -> Sunday/A */
                                $row->day_chargeout = $sunday_a->co_rate;
                                $row->day_desc      = $sunday_a->day_desc;
                            } elseif ($row->paygrade == '26') {
                                /*
                                /* T1B -> Sunday/B */
                                $row->day_chargeout = $sunday_b->co_rate;
                                $row->day_desc      = $sunday_b->day_desc;
                            }
                        }

                        $iArray = array(
                            'bookingID'      => $this->db->sc($bookingID),
                            'invoiceID'      => $this->db->sc($invoiceID),
                            'callID'         => $this->db->sc($row->callID),
                            'start_date'     => $this->db->sc($row->start_date),
                            'start_time'     => 'SEC_TO_TIME(' . intval($on)  . ')',
                            'end_time'       => 'SEC_TO_TIME(' . intval($off) . ')',
                            'hours'          => $this->db->sc($row->day_hours),
                            'chargeout_rate' => $this->db->sc($row->day_chargeout),
                            'description'    => $this->db->sc($row->call_name .' <i style="font-size: 9px;">(Day/'. $row->day_desc .')</i>'),
                            'qty'            => $this->db->sc($row->noOfCrew),
                            'break_time'     => is_null($row->break_day) ? 'NULL' : $this->db->sc($row->break_day),
                        );

                        $this->db->insert('invoice_lines', $iArray);
                    }

                    // night hours
                    if ($row->night_hours > 0) {
                        /*
                        /* night hours start at 8pm and run until 8am, so cap this record */
                        if ($row->on >= (8 * 60 * 60) && $row->on < (20 * 60 * 60)) {
                            $row->on = 20 * 60 * 60;
                        }

                        if ($row->off >= (8 * 60 * 60) && $row->off < (20 * 60 * 60)) {
                            $row->off = 8 * 60 * 60;
                        }

                        $iArray = array(
                            'bookingID'      => $this->db->sc($bookingID),
                            'invoiceID'      => $this->db->sc($invoiceID),
                            'callID'         => $this->db->sc($row->callID),
                            'start_date'     => $this->db->sc($row->start_date),
                            'start_time'     => 'SEC_TO_TIME(' . intval($row->on)  . ')',
                            'end_time'       => 'SEC_TO_TIME(' . intval($row->off) . ')',
                            'hours'          => $this->db->sc($row->night_hours),
                            'chargeout_rate' => $this->db->sc($row->night_chargeout),
                            'description'    => $this->db->sc($row->call_name .' <i style="font-size: 9px;">(Night/'. $row->night_desc .')</i>'),
                            'qty'            => $this->db->sc($row->noOfCrew),
                            'break_time'     => is_null($row->break_night) ? 'NULL' : $this->db->sc($row->break_night),
                        );

                        $this->db->insert('invoice_lines', $iArray);
                    }
                }
            }
        }

        /*
        /* add call invoice data, set invoice generated = 1 */
        $callsUsed = $this->db->select(
            'DISTINCT(accounting.callID) as callID',
            'accounting LEFT JOIN calls ON accounting.callID=calls.id',
            'calls.call_locked=1 AND calls.invoice_generated=0 AND accounting.bookingID='. $this->db->sc($bookingID)
        );

        if (count($callsUsed) > 0) {
            foreach ($callsUsed as $call) {
                $this->db->update(
                    'calls',
                    array( 'invoice_generated' => $this->db->sc(1), ),
                    'calls.id=' . $this->db->sc($call->callID)
                );
            }
        }
    }


    /**
     * generate accounting data for each call
     *
     * @return                        bool
     */
    public function generateCallData($callID)
    {
        $callID = intval($callID);

        /*
        /* remove any previous info */
        $this->purgeCall($callID);

        /*
        /* grab calls from booking */
        $callInfo = $this->db->select(
            'call_crew_map.*, calls.start_date, calls.call_name, calls.bookingID, calls.is_pubhol, calls.is_pubhol_tomorrow',
            'call_crew_map  LEFT JOIN calls ON call_crew_map.callID=calls.ID
                            LEFT JOIN users ON call_crew_map.userID = users.id',
            '(call_crew_map.status=5 OR (call_crew_map.status=9 AND call_crew_map.prev_status=5 AND (call_crew_map.cancel_pay=1 OR call_crew_map.cancel_charge=1))) AND calls.id=' . $this->db->sc($callID),
            'CONCAT(userID, `on`, break, break_night, off)'
        );

        if (count($callInfo) > 0) {
            foreach ($callInfo as $call) {
                /* calculate start time */
                $worked_time_start = strtotime(date('Y-m-d', $call->start_date) . ' ' . $call->on);

                /* on/off time checking - rollover to next day */
                if ($call->off < $call->on) {
                    $worked_time_end = strtotime('+1 day', strtotime(date('Y-m-d ', $call->start_date) . ' ' . $call->off));
                } else {
                    $worked_time_end = strtotime(date('Y-m-d', $call->start_date) . ' ' . $call->off);
                }

                /* calculate total hours worked */
                $totalHours = ($worked_time_end - $worked_time_start) / 3600;

                if ($totalHours > 0) {
                    $cumulative_hours    = 0;
                    $nightHours          = $this->getHours('night');
                    $dayHours            = $this->getHours('day');
                    $pubhol              = $call->is_pubhol == 1;
                    $pubhol_tomorrow     = $call->is_pubhol_tomorrow == 1;
                    $pubhol_day          = $pubhol ? date('N', $call->start_date) : false;
                    $pubhol_tomorrow_day = $pubhol_tomorrow ? date('N', strtotime('+ 1 day', $call->start_date)) : false;
                    $sunday              = date('N', $call->start_date) == 7;

                    unset($break_day_added, $break_night_added);

                    $invoice_hours = new stdClass;

                    $invoice_hours->day = new InvoiceHours('T1');
                    $invoice_hours->day->findRates($call, true, 'day', $sunday);

                    $invoice_hours->night = new InvoiceHours('T2');
                    $invoice_hours->night->findRates($call, true, 'night', $sunday);

                    $invoice_hours->timehalf = new InvoiceHours('T2');
                    $invoice_hours->timehalf->findRates($call, true, 'night', $sunday);

                    $invoice_hours->double = new InvoiceHours('T3');
                    $invoice_hours->double->findRates($call, false, 'day', $sunday);

                    $invoice_hours->sunday = new InvoiceHours('T3');
                    $invoice_hours->sunday->findRates($call, false);

                    $invoice_hours->pubhol = new InvoiceHours('T3', 'Public Holiday');
                    $invoice_hours->pubhol->findRates($call, false);
                    $invoice_hours->pubhol_tomorrow = new InvoiceHours('T3', 'Public Holiday');
                    $invoice_hours->pubhol_tomorrow->findRates($call, false);

                    // Calculate day break
                    $break_day = explode(':', $call->break);
                    $break_day = ($break_day[0] + ($break_day[1] / 60)) * 3600;

                    // Calculate night break
                    $break_night = explode(':', $call->break_night);
                    $break_night = ($break_night[0] + ($break_night[1] / 60)) * 3600;

                    while ($worked_time_start < $worked_time_end) {
                        $day       = date('N', $worked_time_start);
                        $hour      = date('G', $worked_time_start);
                        $time_left = $worked_time_end - $worked_time_start;

                        /*
                         * If we have more than 15 minutes left, always subtract in 15 minute increments, otherwise
                         * subtract whatever is left of the working time.
                         */
                        $time = ($time_left > 900) ? 900 : $time_left;

                        if (in_array($hour, $dayHours)) {
                            // If this call had a break and it is yet to be calculated, do so now
                            if (($break_day > 0) && ! isset($break_day_added)) {
                                if (($break_type = $this->getBreakType($day, $invoice_hours, $break_day, $pubhol_day, $pubhol_tomorrow_day, $worked_time_start)) !== false) {
                                    $invoice_hours->{$break_type}->addBreak($break_day);

                                    $time_left -= $break_day;
                                    $worked_time_start += $break_day;
                                    $break_day_added = true;

                                    unset($break_type);
                                }
                            }

                            $this->addTime('day', $cumulative_hours, $invoice_hours, $worked_time_start, $time, $pubhol_day, $pubhol_tomorrow_day);
                        } elseif (in_array($hour, $nightHours)) {
                            // If this call had a break and it is yet to be calculated, do so now
                            if (($break_night > 0) && ! isset($break_night_added)) {
                                if (($break_type = $this->getBreakType($day, $invoice_hours, $break_night, $pubhol_day, $pubhol_tomorrow_day, $worked_time_start)) !== false) {
                                    $invoice_hours->{$break_type}->addBreak($break_night);

                                    $time_left -= $break_night;
                                    $worked_time_start += $break_night;
                                    $break_night_added = true;

                                    unset($break_type);
                                }
                            }

                            $this->addTime('night', $cumulative_hours, $invoice_hours, $worked_time_start, $time, $pubhol_day, $pubhol_tomorrow_day);
                        }


                        $worked_time_start += $time;
                        $cumulative_hours += $time / 3600;
                    }

                    if ($invoice_hours->timehalf->getHours() > 0 && (! $pubhol && ! $pubhol_tomorrow && ! $sunday)) {
                        $invoice_hours->timehalf->setDescription('Overtime/x1.5');
                    }

                    if ($invoice_hours->double->getHours() > 0 && (! $pubhol && ! $pubhol_tomorrow && ! $sunday)) {
                        $invoice_hours->double->setDescription('Overtime/x2');
                    }

                    $output = array(
                        $invoice_hours->day->output(),
                        $invoice_hours->night->output(),
                        $invoice_hours->timehalf->output(),
                        $invoice_hours->double->output(),
                        $invoice_hours->sunday->output(),
                        $invoice_hours->pubhol->output(),
                        $invoice_hours->pubhol_tomorrow->output(),
                    );


                    // Sort records by start time so they wind up on the invoice in the right order
                    usort($output, array( $this, 'sortInvoices', ));

                    /*
                    /* invoice line array */
                    $invoiceArray = array(
                        'bookingID'       => $this->db->sc($call->bookingID),
                        'callID'          => $this->db->sc($call->callID),
                        'userID'          => $this->db->sc($call->userID),
                        'day_rate'        => $this->db->sc($call->callrate),
                        'night_rate'      => $this->db->sc($call->callrate_night),
                        'day_chargeout'   => $this->db->sc($call->callchargeout),
                        'night_chargeout' => $this->db->sc($call->callchargeout_night),
                        'week_ending'     => $this->db->sc(0),
                        'paygradeID'      => $this->db->sc($call->callpaygradeID),
                        'callcrewmapID'   => $this->db->sc($call->crewmapID),
                        'calculated'      => is_null($output) ? 'NULL' : $this->db->sc(json_encode($output)),
                        'break_day'       => $this->db->sc($break_day / 3600),
                        'break_night'     => $this->db->sc($break_night / 3600),
                    );

                    $this->db->insert('accounting', $invoiceArray);
                }
            }
        }
    }


    /**
     * Add time to the relevant InvoiceHours instance
     *
     * @access private
     *
     * @param  string $type             InvoiceHours type
     * @param  float  $cumulative_hours Total number of work hours
     * @param  object $invoice_hours    The invoice hours collection
     * @param  int    $start_time       Start timestamp
     * @param  float  $time             Number of hours worked
     *
     * @return void
     */
    private function addTime(
        $type,
        $cumulative_hours,
        &$invoice_hours,
        $start_time,
        $time,
        $pubhol_today = false,
        $pubhol_tomorrow = false
    ) {
        $this_day          = date('N', $start_time);
        $cumulative_hours += $time / 3600;

        if ($this_day == $pubhol_today) {
            $invoice_hours->pubhol->setStart($start_time);

            return $invoice_hours->pubhol->addTime($time);
        }

        if ($this_day == $pubhol_tomorrow) {
            $invoice_hours->pubhol_tomorrow->setStart($start_time);

            return $invoice_hours->pubhol_tomorrow->addTime($time);
        }

        if ($this_day == 7) {
            $invoice_hours->sunday->setStart($start_time);

            return $invoice_hours->sunday->addTime($time);
        }

        if ($cumulative_hours > 10) {
            $invoice_hours->double->setStart($start_time);

            return $invoice_hours->double->addTime($time);
        }

        if ($cumulative_hours > 8 && $cumulative_hours <= 10) {
            $invoice_hours->timehalf->setStart($start_time);

            return $invoice_hours->timehalf->addTime($time);
        }

        if ($cumulative_hours <= 8) {
            $invoice_hours->{$type}->setStart($start_time);

            return $invoice_hours->{$type}->addTime($time);
        }
    }


    /**
     * Return the relevant portion of the day's hours
     *
     * @access private
     *
     * @param  string $type Hour type to return
     *
     * @return array
     */
    private function getHours($type)
    {
        $night = array( 20, 21, 22, 23, 0, 1, 2, 3, 4, 5, 6, 7, );
        $day   = array_diff(range(0, 23), $night);

        switch ($type) {
            case 'night':
                return $night;
                break;
            case 'day':
                return $day;
                break;
            default:
                return $day;
                break;
        }
    }


    /**
     * Return the time of day for a given timestamp
     *
     * @param  int $timestamp Timestamp
     *
     * @return string
     */
    private function getTimeOfDay($timestamp)
    {
        $hour = date('G', $timestamp);

        if (in_array($hour, $this->getHours('day'))) {
            return 'Day';
        }

        if (in_array($hour, $this->getHours('night'))) {
            return 'Night';
        }
    }


    /**
     * purge call accounting data
     *
     * @return                        bool
     *
     */

    public function purgeCall($callID)
    {
        $this->db->delete('accounting', 'callID=' . $callID);
    }


    /**
     * delete invoice
     *
     * @return                        bool
     *
     */
    public function deleteInvoice($invoiceID)
    {
        /*
        /* remove call invoice data, set invoice generated = 0 */
        $callsUsed = $this->db->select('DISTINCT(callID) as callID', 'invoice_lines', 'invoiceID=' . $invoiceID);

        if (count($callsUsed) > 0) {
            foreach ($callsUsed as $call) {
                $this->db->update(
                    'calls',
                    array( 'invoice_generated' => $this->db->sc(0), 'invoiceID' => $this->db->sc(0) ),
                    'calls.id=' . $this->db->sc($call->callID)
                );
            }
        }

        $this->db->delete('invoice_lines', 'invoiceID=' . $this->db->sc($invoiceID));
        $this->db->delete('invoices', 'id=' . $this->db->sc($invoiceID));
    }


    /**
     * generate end of week payslips
     *
     * @return                        bool
     *
     */
    public function generateWorkslips()
    {
        $callsPaid = $this->db->select('*', 'calls', 'call_locked=1 AND payslips_generated=0');

        /*
        /* update all to payed */
        $callList = array();

        if (count($callsPaid) > 0) {
            foreach ($callsPaid as $callInfo) {
                $this->db->update('calls', array( 'payslips_generated' => 1 ), 'id=' . $callInfo->id);
                $callList[] = $callInfo->id;
            }

            $this->db->update(
                'accounting',
                array( 'week_ending' => $this->db->sc(strtotime('now')) ),
                'callID IN(' . implode(',', $callList) . ')'
            );
        }
    }

    public function generateNewCrewCSV()
    {
        $db        = db::getInstance();
        $entries   = $db->select('*', 'users', 'new_employee="TRUE" AND usergroupID=3');
        $csv_lines = array(implode("\t", $this->user_csv_fields));

        if (! is_null($entries)) {
            foreach ($entries as $entry) {
                $csv_lines[] = implode("\t", array(
                    $entry->ein,
                    $entry->firstname,
                    $entry->lastname,
                    $entry->address,
                    '',
                    $entry->suburb,
                    $entry->state,
                    $entry->postcode,
                    '',
                    date("j/m/y", $entry->dob),
                    '',
                    $entry->mobile,
                    '',
                    'CASUAL',
                    'CASUAL',
                    $entry->tfn,
                    $entry->tax_scale,
                    'Cheque',
                    '',
                    '',
                    '',
                    '',
                    'CASUAL',
                    '',
                    '',
                    '',
                    '',
                    'CASUAL',
                    '3',
                    '3',
                    '',
                    '',
                    '',
                    'CASUAL',
                    date("j/m/y", $entry->start_date),
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    $entry->super,
                    $entry->union,
                    '0',
                    $entry->phone,
                    $entry->email,
                    '',
                    '',
                    'Individual Non Business'
                ));
            }
        }

        /*
        /* write CSV to file */

        $filename = tempnam('/tmp', $this->myob_prefix);
        $fp = fopen($filename, 'w');
        fwrite($fp, implode(PHP_EOL, $csv_lines));
        fflush($fp);
        fclose($fp);

        /*
        /* set as downloaded */

        $db->update('users', array('new_employee' => $db->sc('FALSE')), 'new_employee="TRUE" AND usergroupID=3');
        return $filename;
    }

    /**
     */
    public function generateCrewChangesCSV()
    {
        $db        = db::getInstance();
        $entries   = $db->select('*', 'users', 'updated=1 AND usergroupID=3');
        $csv_lines = array(implode("\t", $this->user_csv_fields));

        if (! is_null($entries)) {
            foreach ($entries as $entry) {
                $csv_lines[] = implode("\t", array(
                    '',
                    $entry->firstname,
                    $entry->lastname,
                    $entry->address,
                    '',
                    $entry->suburb,
                    $entry->state,
                    $entry->postcode,
                    '',
                    date("j/m/y", $entry->dob),
                    '',
                    $entry->mobile,
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    $entry->phone,
                    $entry->email,
                    '',
                    '',
                    ''
                ));
            }
        }

        /*
        /* write CSV to file */

        $filename = tempnam('/tmp', $this->myob_prefix);
        $fp = fopen($filename, 'w');
        fwrite($fp, implode("\r\n", $csv_lines));
        fflush($fp);
        fclose($fp);

        /*
        /* set as downloaded */

        $db->update('users', array('updated' => "'0'"), 'updated=1 AND usergroupID=3');
        return $filename;
    }

    private function buildCustomerCSV($entries)
    {
        $csv_lines = array(implode("\t", $this->customer_csv_fields));

        if (! is_null($entries)) {
            foreach ($entries as $entry) {
                $line = array(
                    'Co./Last Name'      => $entry->customer_name,

                    'Addr 1 - Line 1'    => $entry->address,
                    'Addr 1 - State'     => $entry->state,
                    'Addr 1 - Postcode'  => $entry->postcode,
                    'Addr 1 - Phone # 1' => $entry->phone,
                    'Addr 1 - Fax #'     => $entry->fax,
                    'Addr 1 - Email'     => $entry->email,

                    'Addr 2 - Line 1'    => $entry->address_billing,
                    'Addr 2 - State'     => $entry->state_billing,
                    'Addr 2 - Postcode'  => $entry->postcode_billing,

                    'Notes'              => $entry->notes,
                    'Card ID'            => $entry->myob_id
                );

                $csv = array();
                foreach ($this->customer_csv_fields as $key) {
                    if (isset($line[$key])) {
                        $csv[] = $line[$key];
                    } else {
                        $csv[] = '';
                    }
                }

                $csv_lines[] = implode("\t", $csv);
            }
        }

        return $csv_lines;
    }

    private function buildInvoiceCSV($entries)
    {
        $csv_lines = array(implode("\t", $this->invoice_csv_fields));

        if (count($entries) > 0) {
            foreach ($entries as $entry) {
                $amount = number_format($entry->cost, 2, '.', '');
                $line = array(
                    /*'Co./Last Name'       => $entry->customer_name,

                    'Addr 1 - Line 1'     => $entry->address,
                    '           - Line 2' => $entry->state,
                    '           - Line 3' => $entry->postcode,
                    '           - Line 4' => $entry->phone,

                    'Account #'           => '40092',
                    'Card ID'             => $entry->myob_id,
                    'Customer PO'         => $entry->reference,
                    'Description'         => $entry->bookingName,
                    'Journal Memo'        => $entry->bookingName,

                    'Invoice #'           => $entry->invoiceID,
                    'Date'                => date('Y-m-d', $entry->invoice_generated),
                    'Amount'              => number_format($entry->cost, 2, '.', ''),
                    'Inc-Tax Amount'      => number_format($entry->cost * 1.1, 2, '.', ''),
                    'GST Amount'          => number_format($entry->cost * 0.1, 2, '.', ''),
                    */
                    /* constants */
                    /*'Inclusive'                     => 'X',
                    'Delivery Status'               => 'P',
                    'Tax Code'                      => 'GST',
                    'Non-GST Amount'                => '0',
                    'Sale Status'                   => 'I',
                    'Terms - Payment is Due'        => '0',
                    '           - Discount Days'    => '0',
                    '           - Balance Due Days' => '0',
                    '           - % Discount'       => '0',
                    '           - % Monthly Charge' => '0',
                    'Amount Paid'                   => '0'*/
                    
                    'ContactName'         => $entry->customer_name,
                    
                    'POAddressLine1'     => $entry->address,
                    'POCity'             => $entry->state,
                    'POPostalCode'       => $entry->postcode,
                    
                    'InvoiceNumber'      => $entry->invoiceID,
                    'Reference'          => $entry->reference,
                    'DueDate'            => date('Y-m-d', $entry->invoice_generated),
                    'Description'        => $entry->bookingName,
                    
                    'UnitAmount'         => number_format($entry->cost, 2, '.', ''),
                    'TaxType'            => 'GST',
                    'AccountCode'        => '40092',
                );

                $csv = array();
                foreach ($this->invoice_csv_fields as $key) {
                    if (isset($line[$key])) {
                        $csv[] = $line[$key];
                    } else {
                        $csv[] = '';
                    }
                }

                $csv_lines[] = implode("\t", $csv);
            }
        }

        return $csv_lines;
    }


    public function generateNewCustomersCSV()
    {
        $db        = db::getInstance();
        $entries   = $db->select('customers.*', 'customers', 'new_customer=1');
        $csv_lines = $this->buildCustomerCSV($entries);

        /*
        /* write CSV to file */

        $filename = tempnam('/tmp', $this->myob_prefix);
        $fp = fopen($filename, 'w');
        fwrite($fp, implode("\r\n", $csv_lines));
        fflush($fp);
        fclose($fp);

        /*
        /* mark as downloaded */

        $db->update('customers', array('new_customer' => "'0'"), 'new_customer=1');
        return $filename;
    }

    public function generateNewInvoicesCSV()
    {
        $db      = db::getInstance();
        $entries = $db->select(
            'cs.customer_name, cs.address, cs.state, cs.postcode, cs.phone,
             invoices.id AS invoiceID, invoices.invoice_generated,
             IF(invoice_lines.flatrate > 0, SUM(invoice_lines.flatrate * invoice_lines.qty), SUM(invoice_lines.chargeout_rate * invoice_lines.hours * invoice_lines.qty)) AS cost,
             bookings.name AS bookingName, bookings.reference, calls.call_name AS callName, cs.myob_id',
            'invoice_lines
             LEFT JOIN invoices      ON (invoices.id             = invoice_lines.invoiceID)
             LEFT JOIN bookings      ON (invoice_lines.bookingID = bookings.id)
             LEFT JOIN calls         ON (invoice_lines.callID    = calls.id)
             LEFT JOIN customers cs  ON (bookings.customerID     = cs.id)',
            'invoices.invoice_exported = 0 GROUP BY invoices.id'
        );

        $csv_lines = $this->buildInvoiceCSV($entries);

        /*
        /* write CSV to file */

        $filename = tempnam('/tmp', $this->myob_prefix);
        $fp = fopen($filename, 'w');
        fwrite($fp, implode("\r\n\r\n", $csv_lines));
        fflush($fp);
        fclose($fp);

        /*
        /* mark as downloaded */

        $db->update('invoices', array('invoice_exported' => "'1'"), 'invoice_exported=0');
        return $filename;
    }

    public function generateCustomerChangesCSV()
    {
        $db        = db::getInstance();
        $entries   = $db->select('*', 'customers', 'updated=1');
        $csv_lines = $this->buildCustomerCSV($entries);

        /*
        /* write CSV to file */

        $filename = tempnam('/tmp', $this->myob_prefix);
        $fp = fopen($filename, 'w');
        fwrite($fp, implode("\r\n", $csv_lines));
        fflush($fp);
        fclose($fp);

        /*
        /* set as downloaded */

        $db->update('customers', array('updated' => "'0'"), 'updated=1');
        return $filename;
    }


    /**
     * @param $day
     * @param $invoice_hours
     * @param $break_length
     * @param $pubhol_day
     * @param $pubhol_tomorrow_day
     * @param $worked_time_start
     *
     * @return string
     */
    private function getBreakType($day, $invoice_hours, $break_length, $pubhol_day, $pubhol_tomorrow_day, $worked_time_start)
    {
        $hour          = date('G', $worked_time_start);
        $this_day      = date('N', $worked_time_start);
        $break_length /= 3600;

        if (($pubhol_day !== false) && ($pubhol_day == $day) && $invoice_hours->pubhol->getHours() >= $break_length) {
            return 'pubhol';
        }

        if (($pubhol_tomorrow_day !== false) && ($pubhol_tomorrow_day == $day) && $invoice_hours->pubhol_tomorrow->getHours() >= $break_length) {
            return 'pubhol_tomorrow';
        }

        if ($this_day == 7 && $invoice_hours->sunday->getHours() >= $break_length) {
            return 'sunday';
        }

        if ($invoice_hours->night->getHours() >= $break_length && in_array($hour, $this->getHours('night'))) {
            return 'night';
        }

        if ($invoice_hours->double->getHours() >= $break_length) {
            return 'double';
        }

        if ($invoice_hours->timehalf->getHours() >= $break_length) {
            return 'timehalf';
        }

        if ($invoice_hours->day->getHours() >= $break_length && in_array($hour, $this->getHours('day'))) {
            return 'day';
        }

        return false;
    }


    /**
     * Client server is running PHP 5.2.17, so closures aren't supported
     */
    private function sortInvoices($a, $b)
    {
        return $a['timeStart'] - $b['timeStart'];
    }
}
