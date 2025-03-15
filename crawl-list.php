<?php

$list = [
    '單位預算' => 'https://docs.google.com/spreadsheets/d/e/2PACX-1vRBWbZqagaNt8StRNOlPj2DiAM3QOdou2K6KwxM2g3zQu8PSPyHFGtUTYDdopJSXZiJb8p5GOxM4ksz/pub?gid=171432020&single=true&output=csv',
    '單位法定預算' => 'https://docs.google.com/spreadsheets/d/e/2PACX-1vRBWbZqagaNt8StRNOlPj2DiAM3QOdou2K6KwxM2g3zQu8PSPyHFGtUTYDdopJSXZiJb8p5GOxM4ksz/pub?gid=750094304&single=true&output=csv',
    '單位決算' => 'https://docs.google.com/spreadsheets/d/e/2PACX-1vRBWbZqagaNt8StRNOlPj2DiAM3QOdou2K6KwxM2g3zQu8PSPyHFGtUTYDdopJSXZiJb8p5GOxM4ksz/pub?gid=1978924006&single=true&output=csv',
];

foreach ($list as $type => $csv) {
    $cmd = sprintf("wget -O %s %s", escapeshellarg(__DIR__ . "/list/{$type}.csv"), escapeshellarg($csv . '&&v=' . time()));
    system($cmd);
}
