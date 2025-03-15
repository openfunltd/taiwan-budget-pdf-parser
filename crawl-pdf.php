<?php

include(__DIR__ . '/MyZipArchive.php');

$list = [
    '單位預算' => 'https://docs.google.com/spreadsheets/d/e/2PACX-1vRBWbZqagaNt8StRNOlPj2DiAM3QOdou2K6KwxM2g3zQu8PSPyHFGtUTYDdopJSXZiJb8p5GOxM4ksz/pub?gid=171432020&single=true&output=csv',
    '單位法定預算' => 'https://docs.google.com/spreadsheets/d/e/2PACX-1vRBWbZqagaNt8StRNOlPj2DiAM3QOdou2K6KwxM2g3zQu8PSPyHFGtUTYDdopJSXZiJb8p5GOxM4ksz/pub?gid=750094304&single=true&output=csv',
    '單位決算' => 'https://docs.google.com/spreadsheets/d/e/2PACX-1vRBWbZqagaNt8StRNOlPj2DiAM3QOdou2K6KwxM2g3zQu8PSPyHFGtUTYDdopJSXZiJb8p5GOxM4ksz/pub?gid=1978924006&single=true&output=csv',
];

foreach ($list as $type => $csv) {
    $fp = fopen(__DIR__ . "/list/{$type}.csv", 'r');
    $cols = fgetcsv($fp);
    while ($rows = fgetcsv($fp)) {
        $data = array_combine($cols, $rows);
        if (!($data['機關編號'] ?? null)) {
            throw new Exception("機關編號不存在");
        }
        $data['type'] = $type;
        foreach ($data as $key => $value) {
            if (!preg_match('#^(\d+)年$#', $key, $matches)) {
                continue;
            }
            if (strpos($value, 'http') !== 0) {
                continue;
            }
            // TODO: 法院有問題
            if (strpos($value, '.judicial.gov.tw')) {
                continue;
            }
            if (strpos($value, 'ncc.gov.tw')) {
                continue;
            }
            $year = $matches[1];
            $target = __DIR__ . "/pdf/{$type}-{$data['機關編號']}-{$year}.pdf";
            if (file_exists($target)) {
                continue;
            }
            if (preg_match('#^https://acc.sinica.edu.tw/pdfjs/full\?file=(.*)$#', $value, $matches)) {
                $value = 'https://acc.sinica.edu.tw' . ($matches[1]);
            }
            $cmd = sprintf("wget -4 --no-check-certificate -O tmp.pdf %s", escapeshellarg($value));
            system($cmd, $ret);
            if ($ret !== 0) {
                throw new Exception("Download failed: {$value} {$target}");
            }
            $mime = mime_content_type('tmp.pdf');
            // zip file
            if (in_array($mime, [
                'application/zip',
                'application/x-rar',
            ])) {
                rename("tmp.pdf", "tmp.zip");
                $zip = new MyZipArchive;
                // 如果裡面只有一個 pdf 檔案的話，就解開
                if ($zip->open('tmp.zip') === TRUE) {
                    $pdfs = [];
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $name = $zip->getNameIndex($i);
                        if (preg_match('#\.pdf$#', $name)) {
                            $pdfs[] = $i;
                        }
                    }
                    if (count($pdfs) === 1) {
                        // only archive one pdf file
                        file_put_contents('tmp.pdf', $zip->getFromIndex($pdfs[0]));
                        $mime = 'application/pdf';
                    }
                }
            }
            if ($mime !== 'application/pdf') {
                continue;
                throw new Exception("Not a PDF: {$target} {$value} {$mime}");
            } 
            rename('tmp.pdf', $target);
        }
    }
}
