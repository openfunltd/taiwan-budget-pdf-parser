<?php

include(__DIR__ . "/Parser.php");
$file = __DIR__ . "/sample.pdf";
$file = __DIR__ . '/監察院.pdf';
$file = __DIR__ . '/原住民族委員會.pdf';
$file = __DIR__ . '/台北市.pdf';
$file = __DIR__ . '/內政部.pdf';
if ($_SERVER['argc'] > 1) {
    $file = $_SERVER['argv'][1];
}
Parser::toHTML($file);
$type_lines = Parser::parseHTML(__DIR__ . "/tmp/tmp-html.html");
$fps = [];
$ret = Parser::parseData($type_lines, function($type, $rows) use (&$fps) {
    if (!($fps[$type] ?? false)) {
        $fps[$type] = fopen(__DIR__ . "/outputs/{$type}.csv", 'w');
        fputcsv($fps[$type], array_keys($rows));
    }
    fputcsv($fps[$type], array_values($rows));
});
foreach($fps AS $fp) {
    fclose($fp);
}
