<?php

class Parser
{
    public static function toHTML($pdffile, $target = null)
    {
        if (file_exists("{$target}/html-html.html")) {
            return;
        }
        if (file_exists("{$target}-html.html")) {
            return;
        }
        $cmd = sprintf("pdftohtml -c -s %s %s 2>&1 > /dev/null", escapeshellarg($pdffile), escapeshellarg($target));
        system($cmd, $ret);
    }

    public static function parseHTML($file)
    {
        $contents = explode('<!DOCTYPE html>', file_get_contents($file));
        $type_lines = [];
        $page_showed = [];
        foreach ($contents as $html_content) {
            if (!$html_content) {
                continue;
            }
            if (!preg_match('#id="page([0-9]+)-div"#', $html_content, $matches)) {
                throw new Exception("找不到頁數");
            }
            $page = $matches[1];
            if (array_key_exists($page, $page_showed)) {
                continue;
            }
            $page_showed[$page] = true;

            //error_log("Page: $page");
            $doc = new DOMDocument();
            @$doc->loadHTML($html_content);
            $boxes = [];
            $prev_box = null;
            foreach ($doc->getElementsByTagName('p') as $p_dom) {
                $style = $p_dom->getAttribute('style');
                if (!preg_match('#position:absolute;top:([0-9]+)px;left:([0-9]+)px#', $style, $matches)) {
                    continue;
                }
                $current_box = "{$matches[1]}-{$matches[2]}-{$p_dom->textContent}";
                if ($prev_box == $current_box) {
                    continue;
                }
                $prev_box = $current_box;
                $boxes[] = [
                    'top' => $matches[1],
                    'left' => $matches[2],
                    'text' => $p_dom->textContent,
                ];
            }
            if (!$boxes) {
                continue;
            }
            usort($boxes, function ($a, $b) {
                return $a['top'] <=> $b['top'] ?: $a['left'] <=> $b['left'];
            });

            $content = '';
            $line_boxes = [];
            $line_box = [
                'top' => 0,
                'boxes' => [],
                'content' => '',
            ];
            $top = null;
            foreach ($boxes as $box) {
                if ($top !== null && $top + 5 < $box['top']) {
                    $content .= "\n";
                    $line_boxes[] = $line_box;
                    $line_box = [
                        'top' => $box['top'],
                        'boxes' => [],
                        'content' => '',
                    ];
                }
                $line_box['boxes'][] = $box;
                $line_box['content'] .= $box['text'];
                $content .= $box['text'];
                $top = $box['top'];
            }
            $line_boxes[] = $line_box;

            $lines = explode("\n", $content);
            $types = [
                '歲入來源別預算表',
                '歲出政事別預算表',
                '歲出機關別預算表',
                '各項費用彙計表',
            ];
            $type = null;
            foreach ($types as $t) {
                if (strpos(implode('', array_slice($lines, 0, 3)), $t) !== false) {
                    $type = $t;
                    break;
                }
            }
            if (is_null($type)) {
                continue;
            }

            $backgroup_image = null;
            if (preg_match('#<img .* src="([^"]+)" alt="background image"#', $html_content, $matches)) {
                $backgroup_image = $matches[1];
                $gd = imagecreatefrompng(dirname($file) . "/" . $backgroup_image);
                // 檢查圖片中 top=300 中，從左到右有哪些 pixel 是有黑色的點
                $black = [];
                for ($x = 0; $x < imagesx($gd); $x++) {
                    $color = imagecolorat($gd, $x, 500);
                    $r = ($color >> 16) & 0xFF;
                    $g = ($color >> 8) & 0xFF;
                    $b = $color & 0xFF;
                    if ($r < 100 && $g < 100 && $b < 100) {
                        if (count($black) and abs($x - $black[count($black) - 1] ?? 0) < 5) {
                            continue;
                        }
                        $black[] = $x;
                    }
                }

                // 如果最右邊一條線距離右邊太近，表示可能是縣市格式，把最左右邊線拿掉
                if (imagesx($gd) - $black[count($black) - 1] < 100) {
                    $black = array_slice($black, 1, count($black) - 2);
                }
            }

            // 先一行一行抓到名稱與編號為止
            $header_text = '';
            $header_boxes = [];
            while (count($line_boxes)) {
                $line_box = array_shift($line_boxes);
                $line = $line_box['content'];
                $header_text .= $line;
                $header_boxes[] = $line;
                
                if (in_array($type, ['各項費用彙計表'])) {
                    // 如果是各項費用彙計表，就處理到單位之後
                    if (strpos($line, '單位：') !== false) {
                        break;
                    }
                } else {
                    // 其他的話，就處理到名稱及編號為止
                    if (strpos($line, '名稱及編號') !== false) {
                        break;
                    }
                }
            }
            if (!$line_box) {
                throw new Exception("找不到名稱及編號");
            }
            // 最後一個 line_box 的 content 檢查是否與頁碼相同
            /*
            if (count($line_boxes) and !preg_match('#^\d+$#', $line_boxes[count($line_boxes) - 1]['content'])) {
                //print_r($line_boxes[count($line_boxes) - 1]);
                //print_r($page);
                continue;
                throw new Exception("頁碼不符");
            }
             */
            array_pop($line_boxes);

            foreach ($line_boxes as $line_box) {
                $rows = array_fill(0, count($black) + 1, '');
                foreach ($line_box['boxes'] as $box) {
                    $left = $box['left'];
                    $text = $box['text'];
                    $black_index = null;
                    foreach ($black as $index => $x) {
                        if ($left < $x) {
                            $black_index = $index;
                            break;
                        }
                    }
                    if ($black_index === null) {
                        $black_index = count($black);
                    }
                    $rows[$black_index] = $rows[$black_index] ?? '';
                    $rows[$black_index] .= str_replace('　', '', $text);
                }
                // 抓取單位名稱
                $organization = null;
                foreach ($header_boxes as $idx => $header_box) {
                    if (strpos($header_box, $type) === 0) {
                        $organization = $header_boxes[$idx - 1];
                        break;
                    }
                }

                if (!array_key_exists($type, $type_lines)) {
                    $type_lines[$type] = [
                        'type' => $type,
                        'header_text' => $header_text,
                        'header_boxes' => $header_boxes,
                        'organizations' => [],
                        'rows' => [],
                        'page' => $page,
                    ];
                }
                $type_lines[$type]['rows'][] = $rows;
                $type_lines[$type]['organizations'][] = $organization;
            }
        }
        return $type_lines;
    }

    public static function outputData($cols, $values)
    {
        return array_combine($cols, array_map(function($c) use ($values){
            return $values[$c] ?? '';
        }, $cols));
    }
    public static function clean_space($s)
    {
        $s = str_replace(' ', '', $s);
        $s = str_replace('　', '', $s);
        $s = str_replace(" ", '', $s);
        return $s;
    }

    public static function parse各項費用彙計表($type_line, $callback)
    {
        $cols = [
            '單位',
            '工作計畫編號',
            '工作計畫名稱',
            '第一級用途別科目編號',
            '第一級用途別科目名稱',
            '第二級用途別科目編號',
            '第二級用途別科目名稱',
            '費用',
        ];
        $project_list = null; // 工作計畫名稱及編號
        $parent_id_no = null; // 第一級用途別科目名稱及編號
        while ($row = array_shift($type_line['rows'])) {
            $organization = array_shift($type_line['organizations']);

            // 處理全空白
            if (self::clean_space(implode('', $row)) == '') {
                continue;
            }
            // 處理第一行 工作計畫名稱及編號
            if ($row[0] == '工作計畫名稱及編號') {
                $project_list = [];

                // 處理空格
                for ($i = 1; $i < count($row); $i ++) {
                    // nbsp to space
                    $row[$i] = str_replace(chr(0xc2) . chr(0xa0), ' ', $row[$i]);
                    if (!preg_match('#^[0-9 ]*$#', $row[$i])) {
                        print_r($row);
                        var_dump($row);
                        throw new Exception("工作計畫名稱及編號有問題");
                    }
                    if (preg_match('#^([0-9 ]+)$#u', $row[$i], $matches)) {
                        $ids = preg_split('# +#u', trim($matches[1]));
                        $row[$i] = '';
                        foreach ($ids as $idx => $id) {
                            if ($row[$i + $idx]) {
                                throw new Exception("工作計畫名稱及編號有問題");
                            }
                            $row[$i + $idx] = trim($id);
                        }
                    }

                    $project_list[] = [
                        '工作計畫編號' => $row[$i],
                        '工作計畫名稱' => '',
                    ];
                }

                // 處理下一行名稱
                $row = array_shift($type_line['rows']);
                $organization = array_shift($type_line['organizations']);
                for ($i = 1; $row[$i] ?? false; $i ++) {
                    $project_list[$i - 1]['工作計畫名稱'] = $row[$i];
                }
                if ($type_line['rows'][0][0] == '第一、二級用途別科目名稱及編號') {
                    array_shift($type_line['rows']);
                    array_shift($type_line['organizations']);
                }

                // 如果後面還有文字，表示字太多要追加
                while ($type_line['rows'][0][0] == '') {
                    $row = array_shift($type_line['rows']);
                    $organization = array_shift($type_line['organizations']);
                    for ($i = 1; $row[$i] ?? false; $i ++) {
                        $project_list[$i - 1]['工作計畫名稱'] .= $row[$i];
                    }
                }
                continue;
            }

            // 處理合計
            if (preg_replace('#[\xa0\s]#u', '', $row[0]) == '合計') {
                for ($i = 0; $i < count($project_list); $i ++) {
                    $values = [
                        '單位' => $organization,
                        '工作計畫編號' => $project_list[$i]['工作計畫編號'],
                        '工作計畫名稱' => $project_list[$i]['工作計畫名稱'],
                        '第一級用途別科目編號' => '',
                        '第一級用途別科目名稱' => '合計',
                        '第二級用途別科目編號' => '',
                        '第二級用途別科目名稱' => '',
                        '費用' => str_replace(',', '', $row[$i + 1]),
                    ];
                    $callback('各項費用彙計表', self::outputData($cols, $values), $type_line['page']);
                }
                continue;
            }

            // 處理數字
            $row[0] = str_replace(chr(0xa0) . ' ', chr(0xa0), $row[0]);
            if (preg_match('#^\xa0*(\d+)\s*(.*)$#u', $row[0], $matches)) {
                $no = $matches[1];
                $name = $matches[2];

                while (true) {
                    // 處理名稱被擠到下一行
                    if (count($type_line['rows'])
                        and preg_match('#^[^0-9]+#', $type_line['rows'][0][0]) 
                        and $type_line['rows'][0][0] != '工作計畫名稱及編號'
                        and implode('', array_slice($type_line['rows'][0], 1)) == ''
                    ) {
                        $row2 = array_shift($type_line['rows']);
                        $organization = array_shift($type_line['organizations']);
                        $name .= $row2[0];
                        continue;
                    }

                    // 處理如果欄位名跟資料被拆成兩行的情況
                    if ('' == implode('', array_slice($row, 1)) and $type_line['rows'][0][0] == '') {
                        $row = array_shift($type_line['rows']);
                        $organization = array_shift($type_line['organizations']);
                        continue;
                    }

                    // 跳過只有第一個欄位是「第一、二級用途別科目名稱及編號」的行
                    if (count($type_line['rows']) and '第一、二級用途別科目名稱及編號' == $type_line['rows'][0][0] and implode('', array_slice($type_line['rows'][0], 1)) == '') {
                        array_shift($type_line['rows']);
                        array_shift($type_line['organizations']);
                        continue;
                    }

                    // 如果現在這行沒數字，但是下行只有數字，把下行數字接過來
                    if (implode('', array_slice($row, 1)) == '' and count($type_line['rows']) and $type_line['rows'][0][0] == '' and preg_match('#^[0-9-]+$#', implode('', $type_line['rows'][0]))) {
                        $row = array_merge([$row[0]], array_slice($type_line['rows'][0], 1));
                        array_shift($type_line['rows']);
                        array_shift($type_line['organizations']);
                        continue;
                    }

                    break;
                }

                if ($no % 100 == 0) {
                    $parent_id_no = [$no, $name, '', ''];
                } else {
                    $parent_id_no[2] = $no;
                    $parent_id_no[3] = $name;
                }

                for ($i = 1; $row[$i] ?? false; $i ++) {
                    if (!array_key_exists(0, $parent_id_no)) {
                        print_R($no);
                        throw new Exception("沒有 parent_id_no");
                    }
                    $values = [
                        '單位' => $organization,
                        '工作計畫編號' => $project_list[$i - 1]['工作計畫編號'] ?? '',
                        '工作計畫名稱' => $project_list[$i - 1]['工作計畫名稱'] ?? '合計',
                        '第一級用途別科目編號' => $parent_id_no[0],
                        '第一級用途別科目名稱' => $parent_id_no[1],
                        '第二級用途別科目編號' => $parent_id_no[2],
                        '第二級用途別科目名稱' => $parent_id_no[3],
                        '費用' => str_replace(',', '', $row[$i]),
                    ];
                    $callback('各項費用彙計表', self::outputData($cols, $values), $type_line['page']);
                }
                continue;
            }
            echo json_encode([
                'organization' => $organization,
                'no' => $no,
                'name' => $name,
                'row' => $row,
            ]) . "\n";
            echo json_encode([
                'organization' => $organization,
                'no' => $no,
                'name' => $name,
                'row' => $row,
            ], JSON_UNESCAPED_UNICODE) . "\n";
            print_r(array_slice($type_line['rows'], 0, 2));
            throw new Exception("沒有符合的行");
        }
    }

    public static function parseData($type_lines, $callback)
    {
        $cols = [
            '歲入來源別預算表' => [
                '款', '項', '目', '節', '編號', '款名', '項名', '目名', '節名', '本年度預算數', '上年度預算數', '前年度決算數', '本年度與上年度比較', '說明',
            ],
            '歲出機關別預算表' => [
                '款', '項', '目', '節', '編號', '款名', '項名', '目名', '節名', '本年度預算數', '上年度預算數', '前年度決算數', '本年度與上年度比較', '說明',
            ],
            '歲出政事別預算表' => [
                '款', '項', '目', '節', '編號', '款名', '項名', '目名', '節名', '本年度預算數', '上年度預算數', '前年度決算數', '本年度與上年度比較',
            ],
        ];

        foreach ($type_lines as $type => $type_line) {
            file_put_contents(__DIR__ . "/tmp.json", json_encode($type_line, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            if ($type == '各項費用彙計表') {
                return self::parse各項費用彙計表($type_line, $callback);
            }
            $no = null;
            $values = null;
            $prev_values = [];
            while ($row = array_shift($type_line['rows'])) {
                $organization = array_shift($type_line['organizations']);
                if (self::clean_space($row[4]) == '合計') {
                    $values = [
                        '款名' => '合計',
                        '說明' => '',
                        '本年度預算數' => str_replace(',', '', $row[5]),
                        '上年度預算數' => str_replace(',', '', $row[6]),
                        '前年度決算數' => str_replace(',', '', $row[7]),
                        '本年度與上年度比較' => str_replace(',', '', $row[8]),
                    ];

                    continue;
                }

                // 如果只有第 4 個欄位有值，表示現在是編號，或者是多行的名稱
                if ($row[4] != '') {
                    $clone_row = $row;
                    $clone_row[4] = '';
                    if (implode('', $clone_row) == '') {
                        $row[4] = trim($row[4]);
                        if (!preg_match('#^[0-9a-z]+$#', self::clean_space($row[4]))) {
                            foreach (['節', '目', '項', '款'] as $c) {
                                if (($values[$c] ?? false) !== '') {
                                    break;
                                }
                            }
                            $values[$c . '名'] .= self::clean_space($row[4]);
                            continue;
                        }
                        if (!is_null($values)) {
                            $prev_values = $values;
                            $callback($type, self::outputData($cols[$type], $values), $type_line['page']);
                        }
                        $values = [
                            '編號' => self::clean_space($row[4]),
                            '款' => $prev_values['款'] ?? '',
                            '款名' => $prev_values['款名'] ?? '',
                            '項' => $prev_values['項'] ?? '',
                            '項名' => $prev_values['項名'] ?? '',
                            '目' => $prev_values['目'] ?? '',
                            '目名' => $prev_values['目名'] ?? '',
                            '節' => $prev_values['節'] ?? '',
                            '節名' => $prev_values['節名'] ?? '',
                            '說明' => '',
                        ];
                        continue;
                    }
                    // 如果是地方政府的話，第四欄會是 數字名稱接在一起
                    if (preg_match('#^([0-9a-z]+)(.+)$#', $row[4], $matches)) {
                        if (!is_null($values)) {
                            $prev_values = $values;
                            $callback($type, self::outputData($cols[$type], $values), $type_line['page']);
                        }
                        $values = [
                            '編號' => $matches[1],
                            '說明' => '',
                            '款' => $prev_values['款'] ?? '',
                            '款名' => $prev_values['款名'] ?? '',
                            '項' => $prev_values['項'] ?? '',
                            '項名' => $prev_values['項名'] ?? '',
                            '目' => $prev_values['目'] ?? '',
                            '目名' => $prev_values['目名'] ?? '',
                            '節' => $prev_values['節'] ?? '',
                            '節名' => $prev_values['節名'] ?? '',
                        ];
                        $row[4] = $matches[2];
                    }
                }

                if ($row[0] != '' and $row[4] != '') {
                    $values['款'] = $row[0];
                    $values['款名'] = $row[4];
                    $values['項'] = '';
                    $values['項名'] = '';
                    $values['目'] = '';
                    $values['目名'] = '';
                    $values['節'] = '';
                    $values['節名'] = '';
                    $row[0] = $row[4] = '';
                }

                if ($row[1] != '' and $row[4] != '') {
                    $values['項'] = $row[1];
                    $values['項名'] = $row[4];
                    $values['目'] = '';
                    $values['目名'] = '';
                    $values['節'] = '';
                    $values['節名'] = '';
                    $row[1] = $row[4] = '';
                }

                if ($row[2] != '' and $row[4] != '') {
                    $values['目'] = $row[2];
                    $values['目名'] = $row[4];
                    $values['節'] = '';
                    $values['節名'] = '';
                    $row[2] = $row[4] = '';
                }

                if ($row[3] != '' and $row[4] != '') {
                    $values['節'] = $row[3];
                    $values['節名'] = $row[4];
                    $row[3] = $row[4] = '';
                }

                if (strpos($type_line['header_text'], '前年度') !== false) {
                    for ($i = 5; $i < 8; $i ++) {
                        if (strpos($row[$i], chr(0xa0)) !== false) {
                            if ($row[$i + 1] != '') {
                                throw new Exception("前年度數字有問題");
                            }
                            $row[$i + 1] = explode(chr(0xa0), $row[$i], 2)[1];
                            $row[$i] = explode(chr(0xa0), $row[$i], 2)[0];
                        }
                    }

                    if ($row[5] != '' and $row[6] != '' and $row[7] != '' and $row[8] != '') {
                        $values['本年度預算數'] = str_replace(',', '', $row[5]);
                        $values['上年度預算數'] = str_replace(',', '', $row[6]);
                        $values['前年度決算數'] = str_replace(',', '', $row[7]);
                        $values['本年度與上年度比較'] = str_replace(',', '', $row[8]);
                        $row[5] = $row[6] = $row[7] = $row[8] = '';
                    }

                    if ($row[9] ?? false and $row[9] != '') {
                        if (!array_key_exists('說明', $values)) {
                            $values['說明'] = '';
                        }
                        $values['說明'] .= $row[9];
                        $row[9] = '';
                    }
                } else {
                    if ($row[5] != '' or $row[6] != '' or $row[7] != '') {
                        $values['本年度預算數'] = str_replace(',', '', $row[5]);
                        $values['上年度預算數'] = str_replace(',', '', $row[6]);
                        $values['本年度與上年度比較'] = str_replace(',', '', $row[7]);
                        $row[5] = $row[6] = $row[7] = '';
                    }

                    if ($row[8] ?? false and $row[8] != '') {
                        $values['說明'] .= $row[8];
                        $row[8] = '';
                    }
                }

                // 如果只剩下 row[4] 有值，有可能是沒有款項目節的資料
                if ($row[4]) {
                    $clone_row = $row;
                    $clone_row[4] = '';
                    if (implode('', $clone_row) == '') {
                        $values['目'] = '*';
                        $values['目名'] = $row[4];
                        $values['節'] = '';
                        $values['節名'] = '';
                        $row[4] = '';
                    }
                }

                if (implode('', $row) == '') {
                    continue;
                }

                if ($values['編號'] == '5926018000' and $row[8] == '0' and implode('', array_slice($row, 0, 8)) == '') {
                    $values['本年度與上年度比較'] .= '0';
                    continue;
                }

                print_r($values);
                echo "current = " . json_encode($row) . "\n";
                echo "current = " . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
                echo json_encode(array_slice($type_line['rows'], 0, 5), JSON_UNESCAPED_UNICODE) . "\n";
                throw new Exception("沒有符合的行");
            }
        }
    }
}
