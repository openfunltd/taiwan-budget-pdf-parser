<?php

mkdir(__DIR__ . "/data/pdf");
$list_file = __DIR__ . "/list.csv";
if (!file_exists($list_file)) {
    $cmd = sprintf("wget %s -O %s",
        escapeshellarg("https://docs.google.com/spreadsheets/d/e/2PACX-1vT20VNvZN46Rm1BRD4wsH_x5gIz_l-UPLnsLzRPl1SlcDbug5e1yVqWtSXWQOmLW1d0A2-85Gb5aL_1/pub?gid=870779481&single=true&output=csv"),
        escapeshellarg($list_file)
    );
    system($cmd);
}

$fp = fopen($list_file, "r");
$cols = fgetcsv($fp, escape:",");
while ($rows = fgetcsv($fp, escape:",")) {
    $values = array_combine($cols, $rows);
    if (strpos($values['主管'], '主管') === false) {
        continue;
    }
    $name = str_replace('主管', '', $values['主管']);
    foreach ($values as $k => $v) {
        if (!preg_match('#^\d+$#', $k)) {
            continue;
        }
        if (strpos($v, 'https:') !== 0) {
            continue;
        }
        $pdf_file = __DIR__ . "/data/pdf/{$name}-{$k}.pdf";
        if (!file_exists($pdf_file)) {
            $cmd = sprintf("wget %s -O %s",
                escapeshellarg($v),
                escapeshellarg($pdf_file)
            );
            system($cmd);
        }
    }
}
