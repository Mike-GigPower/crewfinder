<?php
    include('../../global.php');
    include('cohort.php');
    header('Content-Type: application/json');

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

    send_status(400, 'Bad action');
    die('{"error":"unknown action"}');
?>
