<?php
if ($_FILES['file'] ?? false) {
    include(__DIR__ . "/Parser.php");
    $filename = $_FILES['file']['name'];
    $tmpdir = tempnam(sys_get_temp_dir(), 'pdf') . ".dir";
    mkdir($tmpdir);
    Parser::toHTML($_FILES['file']['tmp_name'], $tmpdir . "/html");
    $type_lines = Parser::parseHTML($tmpdir . "/html-html.html");
    $fps = [];
    mkdir($tmpdir . "/csv");
    $ret = Parser::parseData($type_lines, function($type, $rows) use (&$fps, $tmpdir) {
        if (!($fps[$type] ?? false)) {
            $fps[$type] = fopen("{$tmpdir}/csv/{$type}.csv", 'w');
            fputcsv($fps[$type], array_keys($rows));
        }
        fputcsv($fps[$type], array_values($rows));
    });
    foreach($fps AS $fp) {
        fclose($fp);
    }
    $zip = new ZipArchive();
    $zip->open($tmpdir . "/csv.zip", ZipArchive::CREATE);
    foreach(glob($tmpdir . "/csv/*.csv") AS $csv) {
        $zip->addFile($csv, basename($csv));
    }
    $zip->close();
    header("Content-Type: application/zip");
    header("Content-Disposition: attachment; filename={$filename}.zip");
    readfile($tmpdir . "/csv.zip");
    unlink($tmpdir . "/csv.zip");
    foreach(glob($tmpdir . "/csv/*.csv") AS $csv) {
        unlink($csv);
    }
    rmdir($tmpdir . "/csv");
    rmdir($tmpdir);
    exit;
}
?>
預算 PDF 處理器，目前僅支援「歲入來源別預算表」「歲出政事別預算表」「歲出機關別預算表」「各項費用彙計表」，並且失敗率很高 XD
<form method="post" action="" enctype="multipart/form-data">
    <input type="file" name="file">
    <input type="submit" value="Upload">
</form>
