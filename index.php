<?php   

$units = [];

foreach (glob(__DIR__ . "/list/*.csv") as $csv_file) {
    $type = pathinfo($csv_file, PATHINFO_FILENAME);
    $fp = fopen($csv_file, 'r');
    $cols = fgetcsv($fp);
    while ($values = fgetcsv($fp)) {
        $data = array_combine($cols, $values);
        $unit_id = $data['機關編號'];
        if (!array_key_exists($unit_id, $units)) {
            $units[$unit_id] = (object)[
                '機關編號' => $unit_id,
                '機關名稱' => $data['機關名稱'],
                'files' => (object)[
                    '單位預算' => [],
                    '單位決算' => [],
                    '單位法定預算' => [],
                ],
                'csvs' => (object)[
                    '單位預算' => new StdClass,
                    '單位決算' => new StdClass,
                    '單位法定預算' => new StdClass,
                ],
            ];
        }
        foreach ($data as $k => $v) {
            if (!preg_match('#^(\d+)年$#', $k, $matches)) {
                continue;
            }

            if (strpos($v, 'http') !== 0) {
                continue;
            }
            $year = $matches[1];
            $units[$unit_id]->files->{$type}[] = (object)[
                'year' => $year,
                'url' => $v,
                'has_html' => file_exists(__DIR__ . "/html/{$type}-{$unit_id}-{$year}"),
                'has_txt' => file_exists(__DIR__ . "/txt/{$type}-{$unit_id}-{$year}.txt"),
            ];
        }
    }
}
foreach (glob(__DIR__ . "/outputs/*/*/*.csv") as $f) {
    if (!preg_match('#outputs/([^/]+)/([^/]+)/(\d+)-(\d+)\.csv$#', $f, $matches)) {
        continue;
    }
    $type = $matches[1];
    $csv_type = $matches[2];
    $unit_id = $matches[3];
    $year = $matches[4];
    if (!array_key_exists($unit_id, $units)) {
        continue;
    }
    if (!property_exists($units[$unit_id]->csvs->{$type}, $year)) {
        $units[$unit_id]->csvs->{$type}->$year = [];
    }
    $units[$unit_id]->csvs->{$type}->$year[] = $csv_type;
}
$units = array_values($units);
?>
<!doctype html>
<html lang="zh-tw">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PDF</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jstree/3.2.1/themes/default/style.min.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jstree/3.2.1/jstree.min.js"></script>
<style>
/* #list, #content 的高度固定 100% 高並且 overflow-y: auto */
#list, #content {
    height: 100vh;
    overflow-y: auto;
}
</style>
</head>
<body>
<div class="container container-fluid">
  <div class="row">
    <div id="list" class="col-3">
    <ul>
        <?php foreach ($units as $unit) { ?>
        <li class="unit-li <?= ($_GET['unit_id'] ?? false) == $unit->{'機關編號'} ? 'jstree-open': '' ?>" data-unit="<?= $unit->{'機關編號'} ?>">
        <?= $unit->{'機關編號'} ?>.
        <?= $unit->{'機關名稱'} ?>
            <ul>
                <?php foreach ($unit->files as $type => $files) { ?>
                <li class="type-li <?= ($_GET['type'] ?? false) == $type ? 'jstree-open' : '' ?>" data-type="<?= $type ?>"><?= $type ?>(<?= count($files) ?>)
                    <ul>
                        <?php foreach ($files as $file) { ?>
                        <li class="year-li <?= ($_GET['year'] ?? false) == $file->year ? 'jstree-open' : '' ?>" data-year="<?= $file->year ?>"><?= $file->year ?>年
                        <ul>
                            <li><a href="<?= htmlspecialchars($file->url) ?>" target="_blank">原始</a></li>
                            <li><a href="#" class="btn-pdf">PDF</a></li>
                            <?php if ($file->has_html) { ?>
                            <li><a href="#" class="btn-html">HTML</a></li>
                            <?php } ?>
                            <?php if ($file->has_txt) { ?>
                            <li><a href="#" class="btn-txt">TXT</a></li>
                            <?php } ?>
                            <?php foreach ($unit->csvs->{$type}->{$file->year} as $csv_type) { ?>
                            <li><a href="#" class="btn-csv" data-csvtype="<?= htmlspecialchars($csv_type) ?>"><?= htmlspecialchars($csv_type) ?>.csv</a></li>
                            <?php } ?>
                        </ul>
                        </li>
                        <?php } ?>
                    </ul>
                </li>
                <?php } ?>
            </ul>
        <?php } ?>
    </ul>
    </div>
    <div class="col-9" id="content">
      DATA
    </div>
  </div>
</div>
<script>
$('#list').jstree();
$('#list').on('click', 'a.btn-pdf', function(e) {
    e.preventDefault();
    var unit_id = $(this).closest('li.unit-li').data('unit');
    var year = $(this).closest('li.year-li').data('year');
    var type = $(this).closest('li.type-li').data('type');
    var pdf_path = "/pdf/" + type + "-" + unit_id + "-" + year + ".pdf";
    // iframe in #content
    $('#content').html('<iframe src="' + pdf_path + '" style="width: 100%; height: 100%;"></iframe>');
    // pushState to history, add ?unit_id=xxx&year=xxx&type=xxx&format=pdf
    history.pushState(null, null, '?unit_id=' + unit_id + '&year=' + year + '&type=' + type + '&format=pdf');
});
$('#list').on('click', 'a.btn-csv', function(e) {
    e.preventDefault();
    var unit_id = $(this).closest('li.unit-li').data('unit');
    var year = $(this).closest('li.year-li').data('year');
    var type = $(this).closest('li.type-li').data('type');
    var csv_type = $(this).data('csvtype');
    var csv_path = "/outputs/" + type + "/" + csv_type + "/" + unit_id + "-" + year + ".csv";
    // iframe in #content
    $.get(csv_path, function(data) {
        $('#content').html('<pre>' + data + '</pre>');
    }, 'text');
    // pushState to history, add ?unit_id=xxx&year=xxx&type=xxx&format=csv&csv_type=xxx
    history.pushState(null, null, '?unit_id=' + unit_id + '&year=' + year + '&type=' + type + '&format=csv&csv_type=' + csv_type);
});
$('#list').on('click', 'a.btn-html', function(e) {
    e.preventDefault();
    var unit_id = $(this).closest('li.unit-li').data('unit');
    var year = $(this).closest('li.year-li').data('year');
    var type = $(this).closest('li.type-li').data('type');
    var html_path = "/html/" + type + "-" + unit_id + "-" + year + '/html-html.html';
    // iframe in #content
    $('#content').html('<iframe src="' + html_path + '" style="width: 100%; height: 100%;"></iframe>');
    // pushState to history, add ?unit_id=xxx&year=xxx&type=xxx&format=html
    history.pushState(null, null, '?unit_id=' + unit_id + '&year=' + year + '&type=' + type + '&format=html');
});
$('#list').on('click', 'a.btn-txt', function(e) {
    e.preventDefault();
    var unit_id = $(this).closest('li.unit-li').data('unit');
    var year = $(this).closest('li.year-li').data('year');
    var type = $(this).closest('li.type-li').data('type');
    var txt_path = "/txt/" + type + "-" + unit_id + "-" + year + '.txt';
    // iframe in #content
    $.get(txt_path, function(data) {
        $('#content').html('<pre>' + data + '</pre>');
    }, 'text');
    // pushState to history, add ?unit_id=xxx&year=xxx&type=xxx&format=txt
    history.pushState(null, null, '?unit_id=' + unit_id + '&year=' + year + '&type=' + type + '&format=txt');
});
</script>
</body>
</html>
