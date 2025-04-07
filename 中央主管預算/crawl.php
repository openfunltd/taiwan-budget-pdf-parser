<?php

include(__DIR__ . "/../Parser.php");
@mkdir(__DIR__ . "/data/pdf");
@mkdir(__DIR__ . "/data/csv");
$list_file = __DIR__ . "/list.csv";
if (!file_exists($list_file)) {
    $cmd = sprintf("wget %s -O %s",
        escapeshellarg("https://docs.google.com/spreadsheets/d/e/2PACX-1vT20VNvZN46Rm1BRD4wsH_x5gIz_l-UPLnsLzRPl1SlcDbug5e1yVqWtSXWQOmLW1d0A2-85Gb5aL_1/pub?gid=870779481&single=true&output=csv"),
        escapeshellarg($list_file)
    );
    system($cmd);
}

$tables = [
    '歲入來源別預算表',
    '歲出政事別預算表',
    '歲出機關別預算表',
];

$outputs = [];
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
            system($cmd, $ret);
            if ($ret !== 0) {
                unlink($pdf_file);
                throw new Exception("Failed to download PDF: {$pdf_file}");
            }
        }

        if ($name == '環境部' and ($k == 113 or $k == 112)) {
            // 掃描版PDF
            continue;
        }
        $csv_dir = __DIR__ . "/data/csv/{$name}-{$k}";
        if (!file_exists($csv_dir)) {
            error_log("{$csv_dir} {$pdf_file}");
            system(sprintf("rm -rf %s", escapeshellarg(__DIR__ . "/data/tmp")));
            mkdir(__DIR__ . "/data/tmp");
            Parser::toHTML($pdf_file, __DIR__ . "/data/tmp/html");
            if (!file_exists(__DIR__ . "/data/tmp/html-html.html")) {
                throw new Exception("HTML file not found: {$pdf_file}");
            }
            $type_lines = Parser::parseHTML(__DIR__ . "/data/tmp/html-html.html");
            file_put_contents(__DIR__ . "/data/tmp/type_lines.json", json_encode($type_lines, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $fps = [];
            $ret = Parser::parseData($type_lines, function($type, $rows) use ($csv_dir, &$fps) {
                if (!($fps[$type] ?? false)) {
                    if (!file_exists($csv_dir)) {
                        mkdir($csv_dir, 0777, true);
                    }
                    $fps[$type] = fopen("{$csv_dir}/{$type}.csv", 'w');
                    fputcsv($fps[$type], array_keys($rows), escape:",");
                }
                fputcsv($fps[$type], array_values($rows), escape:",");
            });
            foreach($fps AS $fp_csv) {
                fclose($fp_csv);
            }
        }

        foreach ($tables as $t) {
            if (!file_exists("{$csv_dir}/{$t}.csv")) {
                continue;
            }
            $fp_table = fopen("{$csv_dir}/{$t}.csv", 'r');
            $table_cols = fgetcsv($fp_table, escape:",");

            if (!($outputs[$t] ?? false)) {
                $outputs[$t] = fopen(__DIR__ . "/data/{$t}.csv", 'w');
                fputcsv($outputs[$t], array_merge([
                    '主管', '年度',
                ], $table_cols), escape:",");
            }
            while ($rows = fgetcsv($fp_table, escape:",")) {
                fputcsv($outputs[$t], array_merge([
                    $name, $k,
                ], $rows), escape:",");
            }
            fclose($fp_table);
        }
    }
}
