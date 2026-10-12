<?php
define('SS_NO_SMARTY', true);
include('global.php');
header('Content-Type: text/plain');

$pass = 0; $fail = 0;
function t($label, $got, $want) {
    global $pass, $fail;
    $ok = ($got === $want);
    if ($ok) { $pass++; } else { $fail++; }
    printf("%-44s %s   got=%s want=%s\n", $label, $ok ? "PASS" : "**FAIL**",
           var_export($got, true), var_export($want, true));
}
function note($label, $v) { printf("%-44s %s\n", $label, var_export($v, true)); }

note("php version", phpversion());
note("db class", get_class($db));

/* sc(): the escaper — returns value WITH surrounding quotes */
$samples = array("plain", "O'Lack", "back\\slash", 'double"quote',
                 "100% sure", "Müller", "null\0byte");
foreach ($samples as $i => $s) note("sc[$i] " . json_encode($s), $db->sc($s));

/* reads */
$rows = $db->select('*', 'venues', '`active` = 1', 'id', '5');
t("select returns array", is_array($rows), true);
t("select count", count($rows), 5);
$first = $db->selectFirst('*', 'venues', '`active` = 1', 'id');
t("selectFirst is object", is_object($first), true);
t("selectFirst matches select[0]",
   (is_object($first) && is_array($rows) && isset($rows[0]) && $first->id === $rows[0]->id),
   true);
note("venue field count", count(get_object_vars($first)));

/* writes — values MUST be pre-escaped via sc() */
$db->insert('_harness_probe', array(
    'name' => $db->sc("probe O'Lack"),
    'qty'  => $db->sc(7),
));
$newId = (int)$db->insert_id();
t("insert_id > 0", $newId > 0, true);

$back = $db->selectFirst('*', '_harness_probe', 'id = ' . $newId);
t("name round-trips", ($back ? $back->name : null), "probe O'Lack");
t("qty round-trips", ($back ? (int)$back->qty : null), 7);

$db->update('_harness_probe', array('qty' => $db->sc(9)), 'id = ' . $newId);
$back2 = $db->selectFirst('*', '_harness_probe', 'id = ' . $newId);
t("update took effect", ($back2 ? (int)$back2->qty : null), 9);
note("affected_rows after selectFirst", $db->affected_rows);

$db->delete('_harness_probe', 'id = ' . $newId);
$gone = $db->select('*', '_harness_probe', 'id = ' . $newId);
t("delete removed the row", (is_array($gone) ? count($gone) : 0), 0);

printf("\n==== pass=%d fail=%d ====\n", $pass, $fail);
