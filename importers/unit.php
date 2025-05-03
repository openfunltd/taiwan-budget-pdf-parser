<?php

include(__DIR__ . '/../init.inc.php');

$fp = fopen(__DIR__ . "/../list.csv", 'r');
$cols = fgetcsv($fp);
while ($row = fgetcsv($fp)) {
    $values = array_combine($cols, $row);
    Elastic::dbBulkInsert('unit', $values['機關編號'], $values);
}
Elastic::dbBulkCommit();

