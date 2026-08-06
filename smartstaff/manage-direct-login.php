<?php
    include('../../global.php');
    include('cohort.php');
    header('Content-Type: application/json');

    /* Local status helper. Every other endpoint in this directory defines its
    /* own copy (see call-feeds.php:30); this file called send_status() without
    /* ever defining it, so EVERY error path here fataled instead of returning
    /* JSON. Only get/set-as-admin were ever exercised, which is why it went
    /* unnoticed. function_exists guard in case global.php or cohort.php ever
    /* grows one. PHP 5.x -- no null-coalescing, no short array syntax.
    */
    if (!function_exists('send_status'))
    {
        function send_status($code, $msg)
        {
            $proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
            header($proto . ' ' . $code . ' ' . $msg);
        }
    }

    if (goat_user_cohort() !== 'admin')
    {
        send_status(403, 'Forbidden');
        die('{"error":"Admin only"}');
    }

    $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'get';

    if ($action === 'get')
    {
        $out = array('enabled' => false, 'updated_by' => null, 'updated_at' => null);
        $res = mysql_query("SELECT setting_value, updated_by, updated_at FROM goat_settings WHERE setting_key = 'direct_login_block' LIMIT 1");
        if ($res !== false && mysql_num_rows($res) > 0)
        {
            $row = mysql_fetch_object($res);
            $out['enabled']    = (trim($row->setting_value) === '1');
            $out['updated_at'] = $row->updated_at;
            if ((int) $row->updated_by > 0)
            {
                $ures = mysql_query("SELECT firstname, lastname FROM users WHERE id = " . (int) $row->updated_by . " LIMIT 1");
                if ($ures !== false && mysql_num_rows($ures) > 0)
                {
                    $u = mysql_fetch_object($ures);
                    $out['updated_by'] = trim($u->firstname . ' ' . $u->lastname);
                }
            }
        }
        echo json_encode($out);
        exit;
    }

    if ($action === 'set')
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            send_status(405, 'POST required');
            die('{"error":"POST required"}');
        }
        $val = (isset($_POST['enabled']) && $_POST['enabled'] === '1') ? '1' : '0';
        $uid = (int) $_SESSION[SITE_KEY]['userID'];

        $sql = "INSERT INTO goat_settings (setting_key, setting_value, updated_by, updated_at) "
             . "VALUES ('direct_login_block', '" . mysql_real_escape_string($val) . "', " . $uid . ", NOW()) "
             . "ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), "
             . "updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)";
        $ok = mysql_query($sql);
        if ($ok === false || mysql_error() !== '')
        {
            send_status(500, 'Write failed');
            die('{"error":"write failed: ' . addslashes(mysql_error()) . '"}');
        }
        echo json_encode(array('enabled' => ($val === '1')));
        exit;
    }

    if ($action === 'list-exempt')
    {
        /* Returns every row, including expired ones. Expired rows are retained  */
        /* deliberately: they are the audit trail of who was let through and     */
        /* when, and re-granting is then one click. Single query, no per-row     */
        /* lookups. On query failure we return an empty list with HTTP 200 — an  */
        /* empty manager UI beats a broken modal, and this endpoint grants       */
        /* nothing. The name subquery is pinned to usergroupID = 3 so it         */
        /* resolves the crew record, not a same-EIN admin row.                   */
        $out = array('exempt' => array());
        $sql = "SELECT e.ein, "
             . "e.note, "
             . "e.expires_at, "
             . "e.added_at, "
             . "(e.expires_at IS NOT NULL AND e.expires_at <= NOW()) AS expired, "
             . "(SELECT TRIM(CONCAT(TRIM(u.lastname), ', ', TRIM(u.firstname))) "
             . "   FROM users u "
             . "  WHERE u.ein = e.ein AND u.usergroupID = 3 "
             . "  LIMIT 1) AS name, "
             . "(SELECT TRIM(CONCAT(TRIM(a.firstname), ' ', TRIM(a.lastname))) "
             . "   FROM users a "
             . "  WHERE a.id = e.added_by "
             . "  LIMIT 1) AS added_by_name "
             . "FROM goat_direct_login_exempt e "
             . "ORDER BY expired ASC, name ASC, e.ein ASC";
        $res = mysql_query($sql);
        if ($res !== false)
        {
            while ($row = mysql_fetch_object($res))
            {
                $out['exempt'][] = array(
                    'ein'           => (int) $row->ein,
                    'name'          => $row->name,
                    'note'          => $row->note,
                    'expires_at'    => ($row->expires_at === null ? null : $row->expires_at),
                    'expired'       => (bool) $row->expired,
                    'added_by_name' => $row->added_by_name,
                    'added_at'      => $row->added_at
                );
            }
        }
        echo json_encode($out);
        exit;
    }

    if ($action === 'add-exempt')
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            send_status(405, 'POST required');
            die('{"error":"POST required"}');
        }

        /* ein must be a positive integer. */
        $ein = isset($_POST['ein']) ? (int) $_POST['ein'] : 0;
        if ($ein <= 0)
        {
            send_status(400, 'Bad request');
            die('{"error":"missing or invalid ein"}');
        }

        /* days is a strict server-side whitelist: 14, 30, 90 or 0 (0 = permanent). */
        /* Compared as strings BEFORE casting: (int)"abc" is 0, which would        */
        /* otherwise be accepted as a permanent grant.                             */
        $daysRaw = isset($_POST['days']) ? trim($_POST['days']) : '';
        if ($daysRaw !== '14' && $daysRaw !== '30' && $daysRaw !== '90' && $daysRaw !== '0')
        {
            send_status(400, 'Bad request');
            die('{"error":"days must be 14, 30, 90 or 0"}');
        }
        $days = (int) $daysRaw;

        /* note is optional; truncate to 255 chars BEFORE escaping. */
        $note = isset($_POST['note']) ? mysql_real_escape_string(substr($_POST['note'], 0, 255)) : '';

        /* The EIN must belong to a real crew record (usergroupID = 3). This    */
        /* stops a typo seeding a phantom EIN, and means an admin cannot be     */
        /* added — which is correct, admins are never blocked by the guard.     */
        $chk = mysql_query("SELECT id FROM users WHERE ein = " . $ein . " AND usergroupID = 3 LIMIT 1");
        if ($chk === false || mysql_num_rows($chk) === 0)
        {
            send_status(404, 'Not found');
            die('{"error":"no crew record with that EIN"}');
        }

        /* Expiry is a SQL expression chosen from the whitelist, never           */
        /* interpolated from input. MySQL computes the date so there is one      */
        /* clock and PHP and the DB cannot disagree.                             */
        if ($days === 0)
        {
            $expires = 'NULL';
        }
        else
        {
            $expires = 'DATE_ADD(NOW(), INTERVAL ' . $days . ' DAY)';
        }

        $uid = (int) $_SESSION[SITE_KEY]['userID'];

        /* Repeat grants replace, they do not extend: ON DUPLICATE KEY overwrites */
        /* note, expiry and attribution wholesale. PRIMARY KEY (ein) guarantees   */
        /* one row per person.                                                    */
        $sql = "INSERT INTO goat_direct_login_exempt (ein, note, expires_at, added_by, added_at) "
             . "VALUES (" . $ein . ", '" . $note . "', " . $expires . ", " . $uid . ", NOW()) "
             . "ON DUPLICATE KEY UPDATE note = VALUES(note), "
             . "expires_at = VALUES(expires_at), "
             . "added_by = VALUES(added_by), "
             . "added_at = VALUES(added_at)";
        $ok = mysql_query($sql);
        if ($ok === false || mysql_error() !== '')
        {
            send_status(500, 'Write failed');
            die('{"error":"write failed: ' . addslashes(mysql_error()) . '"}');
        }

        /* Re-select the stored row to report the actual expires_at rather than  */
        /* recomputing it in PHP.                                                 */
        $stored = null;
        $sres = mysql_query("SELECT expires_at FROM goat_direct_login_exempt WHERE ein = " . $ein . " LIMIT 1");
        if ($sres !== false && mysql_num_rows($sres) > 0)
        {
            $srow = mysql_fetch_object($sres);
            $stored = $srow->expires_at;
        }

        echo json_encode(array('ok' => true, 'action' => 'add-exempt', 'ein' => $ein, 'expires_at' => $stored));
        exit;
    }

    if ($action === 'remove-exempt')
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            send_status(405, 'POST required');
            die('{"error":"POST required"}');
        }

        /* ein must be a positive integer. No users lookup — removing a row for  */
        /* an EIN that no longer has a crew record must still work. Deleting a    */
        /* row that isn't there is a harmless no-op.                             */
        $ein = isset($_POST['ein']) ? (int) $_POST['ein'] : 0;
        if ($ein <= 0)
        {
            send_status(400, 'Bad request');
            die('{"error":"missing or invalid ein"}');
        }

        $ok = mysql_query("DELETE FROM goat_direct_login_exempt WHERE ein = " . $ein);
        if ($ok === false || mysql_error() !== '')
        {
            send_status(500, 'Write failed');
            die('{"error":"write failed: ' . addslashes(mysql_error()) . '"}');
        }

        echo json_encode(array('ok' => true, 'action' => 'remove-exempt', 'ein' => $ein));
        exit;
    }

    send_status(400, 'Bad action');
    die('{"error":"unknown action"}');
?>
