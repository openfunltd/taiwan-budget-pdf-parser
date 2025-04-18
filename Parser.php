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
        $cmd = sprintf("pdftohtml -q -c -s %s %s 2>&1 > /dev/null", escapeshellarg($pdffile), escapeshellarg($target));
        system($cmd, $ret);
    }

    public static function parseHTML($file)
    {
        if (!file_exists($file)) {
            throw new Exception("找不到檔案 $file");
        }
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
            $html_content = iconv('utf-8', 'utf-8//IGNORE', $html_content);
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
                    'text' => self::getTextFromNode($p_dom, $doc),
                ];
            }
            if (!$boxes) {
                continue;
            }
            usort($boxes, function ($a, $b) {
                if (abs($a['top'] - $b['top']) <= 8) {
                    return $a['left'] <=> $b['left'];
                }
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
                if ($top !== null && abs($top - $box['top']) > 7) {
                    $content .= "\n";
                    $line_boxes[] = $line_box;
                    $line_box = [
                        'top' => $box['top'],
                        'left' => $box['left'],
                        'boxes' => [],
                        'content' => '',
                    ];
                }
                $line_box['boxes'][] = $box;
                $line_box['content'] .= $box['text'];
                $content .= $box['text'];
                $top = max($top, $box['top']);
            }
            $line_boxes[] = $line_box;

            $lines = explode("\n", $content);
            $types = [
                '歲入來源別預算表',
                '歲出政事別預算表',
                '歲出機關別預算表',
                '各機關各項費用彙計表',
                '各項費用彙計表',
                '歲出計畫提要及分支計畫概況表',
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
                $vertical_black = [];
                for ($x = 0; $x < imagesx($gd); $x++) {
                    $color = imagecolorat($gd, $x, 500);
                    $r = ($color >> 16) & 0xFF;
                    $g = ($color >> 8) & 0xFF;
                    $b = $color & 0xFF;
                    if ($r < 100 && $g < 100 && $b < 100) {
                        if (count($vertical_black) and abs($x - $vertical_black[count($vertical_black) - 1] ?? 0) < 7) {
                            continue;
                        }
                        $hit = 0;
                        for ($y = 0; $y < imagesy($gd); $y++) {
                            $color = imagecolorat($gd, $x, $y);
                            $r = ($color >> 16) & 0xFF;
                            $g = ($color >> 8) & 0xFF;
                            $b = $color & 0xFF;
                            if ($r < 100 && $g < 100 && $b < 100) {
                                $hit++;
                            }
                        }
                        if ($hit / imagesy($gd) < 0.5) {
                            continue;
                        }
                        $vertical_black[] = $x;
                    }
                }
                $horizontal_black = [];
                for ($y = 0; $y < imagesy($gd); $y++) {
                    $color = imagecolorat($gd, imagesx($gd) / 2, $y);
                    $r = ($color >> 16) & 0xFF;
                    $g = ($color >> 8) & 0xFF;
                    $b = $color & 0xFF;
                    if ($r < 100 && $g < 100 && $b < 100) {
                        if (count($horizontal_black) and abs($y - $horizontal_black[count($horizontal_black) - 1] ?? 0) < 7) {
                            continue;
                        }
                        $hit = 0;
                        for ($x = 0; $x < imagesx($gd); $x ++) {
                            $color = imagecolorat($gd, $x, $y);
                            $r = ($color >> 16) & 0xFF;
                            $g = ($color >> 8) & 0xFF;
                            $b = $color & 0xFF;
                            if ($r < 100 && $g < 100 && $b < 100) {
                                $hit ++;
                            }
                        }
                        if ($hit / imagesx($gd) < 0.8) {
                            continue;
                        }
                        $horizontal_black[] = $y;
                    }
                }

                // 如果最右邊一條線距離右邊太近，表示可能是縣市格式，把最左右邊線拿掉
                if (imagesx($gd) - $vertical_black[count($vertical_black) - 1] < 100) {
                    $vertical_black = array_slice($vertical_black, 1, count($vertical_black) - 2);
                }
                if ($type == '歲出計畫提要及分支計畫概況表') {
                    // 表格比較複雜，另外處理
                    $ret = self::parseHTML_歲出計畫提要及分支計畫概況表($gd, $line_boxes);
                    if (!($type_lines[$type] ?? false)) {
                        $type_lines[$type] = [];
                    }
                    $type_lines[$type][] = [
                        'type' => $type,
                        'page' => $page,
                        'data' => $ret,
                    ];
                    continue;
                }
            }

            // 先一行一行抓到名稱與編號為止
            $header_text = '';
            $headLineer_boxes = [];
            if (in_array($type, [
                '各項費用彙計表',
                '各機關各項費用彙計表',
            ])) {
                // 如果是各項費用彙計表，要包含上面第一欄
                $checking_top = $horizontal_black[0] ?? 0;
            } else {
                $checking_top = $horizontal_black[1] ?? 0;
            }
            while (count($line_boxes)) {
                $line_box = array_shift($line_boxes);
                if ($line_box['top'] > $checking_top) {
                    // 如果在黑線上面，表示是頁碼或是備註
                    array_unshift($line_boxes, $line_box);
                    break;
                }
                $line = $line_box['content'];
                $header_text .= $line;
                if (self::clean_space($line)) {
                    $header_boxes[] = $line;
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
            foreach ($line_boxes as $line_box) {
                $rows = array_fill(0, count($vertical_black) + 1, '');
                foreach ($line_box['boxes'] as $box) {
                    $left = $box['left'];
                    $top = $box['top'];
                    $text = $box['text'];
                    if (strpos($text, '附註') !== false) {
                        print_r($box);
                        print_r($horizontal_black);
                    }
                    // 如果是在最後一個 $horizontal_black 的下面的話，表示可能是頁碼或是備註
                    if (count($horizontal_black) and $top > $horizontal_black[count($horizontal_black) - 1]) {
                        continue;
                    }
                    $black_index = null;
                    foreach ($vertical_black as $index => $x) {
                        if ($left < $x - 3) { // 多留一個 pixel ，有時文字會剛好壓到
                            $black_index = $index;
                            break;
                        }
                    }
                    if ($black_index === null) {
                        $black_index = count($vertical_black);
                    }
                    $rows[$black_index] = $rows[$black_index] ?? '';
                    $rows[$black_index] .= str_replace('　', '', $text);
                }
                // 抓取單位名稱
                $organization = null;
                foreach ($header_boxes as $idx => $header_box) {
                    if (strpos($header_box, $type) === 0) {
                        $organization = $header_boxes[$idx - 1];
                        if (!self::clean_space($organization)) {
                            print_r($header_boxes);
                            throw new Exception("找不到單位名稱");
                        }
                        break;
                    }
                }

                if (!array_key_exists($type, $type_lines)) {
                    $type_lines[$type] = [
                        'type' => $type,
                        'header_text' => $header_text,
                        'organizations' => [],
                        'rows' => [],
                        'page' => $page,
                        'header_boxes' => [$page => $header_boxes],
                        'vertical_black' => [$page => $vertical_black],
                        'horizontal_black' => [$page => $horizontal_black],
                    ];
                }
                $type_lines[$type]['rows'][] = $rows;
                $type_lines[$type]['organizations'][] = $organization;
                $type_lines[$type]['vertical_black'][$page] = $vertical_black;
                $type_lines[$type]['horizontal_black'][$page] = $horizontal_black;
                $type_lines[$type]['header_boxes'][$page] = $header_boxes;
            }
        }
        return $type_lines;
    }

    public static function parseHTML_歲出計畫提要及分支計畫概況表($gd, $line_boxes)
    {
        // 這個表格的格式比較複雜，另外處理
        $horizontal_black = [];
        for ($y = 0; $y < imagesy($gd); $y++) {
            $color = imagecolorat($gd, imagesx($gd) / 2, $y);
            $r = ($color >> 16) & 0xFF;
            $g = ($color >> 8) & 0xFF;
            $b = $color & 0xFF;
            if ($r < 100 && $g < 100 && $b < 100) {
                if (count($horizontal_black) and abs($y - $horizontal_black[count($horizontal_black) - 1] ?? 0) < 7) {
                    continue;
                }
                $hit = 0;
                for ($x = 0; $x < imagesx($gd); $x ++) {
                    $color = imagecolorat($gd, $x, $y);
                    $r = ($color >> 16) & 0xFF;
                    $g = ($color >> 8) & 0xFF;
                    $b = $color & 0xFF;
                    if ($r < 100 && $g < 100 && $b < 100) {
                        $hit ++;
                    }
                }
                if ($hit / imagesx($gd) < 0.8) {
                    continue;
                }
                $horizontal_black[] = $y;
            }
        }

        $areas = [];
        foreach ($line_boxes as $line_box) {
            $top = $line_box['top'];
            for ($i = 0; $i < count($horizontal_black); $i ++) {
                if ($top > $horizontal_black[$i]) {
                    continue;
                }
                break;
            }
            if (!array_key_exists($i, $areas)) {
                $areas[$i] = [];
            }
            $areas[$i][] = $line_box;
        }

        $ret = new StdClass;
        // 最上面一區一定是單位名稱和表格名稱
        $area = array_shift($areas);
        foreach ($area as $idx => $line_box) {
            if (strpos($line_box['content'], '歲出計畫提要及分支計畫概況表') === 0) {
                $ret->unit = $area[$idx - 1]['content'];
                break;
            }
        }

        // 第二區一定是「工作計畫名稱及編號」和「預算金額」
        $area = array_shift($areas);
        if (!preg_match('#工作計畫名稱及編號(\d+)(.*)預算金額([0-9,]+)$#', $area[0]['content'], $matches)) {
            print_r($area);
            throw new Exception("找不到工作計畫名稱及編號");
        }
        $ret->工作計畫名稱 = trim($matches[2]);
        $ret->工作計畫編號 = trim($matches[1]);
        $ret->預算金額 = str_replace(',', '', $matches[3]);
        $ret->計畫內容 = [];

        // 如果有 5 條線表示有第三區，
        if (count($horizontal_black) == 3 or count($horizontal_black) == 5) {
            $area = array_shift($areas);
            // 計畫內容區最多只會有兩欄，通常左邊是計畫內容，右邊是預期成果
            $showed_pos = [];
            foreach ($area[0]['boxes'] as $box) {
                $pos = $box['left'] . '-' . $box['top'];
                if ($showed_pos[$pos] ?? false) {
                    continue;
                }
                $showed_pos[$pos] = true;
                if ($box['left'] < imagesx($gd) / 2) {
                    if ($ret->計畫內容[0] ?? false) {
                        print_r($box);
                        throw new Exception("計畫內容有兩欄");
                    }
                    $ret->計畫內容[0] = $box['text'];
                } else {
                    if ($ret->計畫內容[1] ?? false) {
                        print_r($box);
                        throw new Exception("計畫內容有兩欄");
                    }
                    $ret->計畫內容[1] = $box['text'];
                }
            }
            if (count($horizontal_black) == 3) {
                // 如果只有三欄直接結束
                return $ret;
            }
        } else if (count($horizontal_black) == 4) {
        } else {
            print_r($horizontal_black);
            throw new Exception("TODO: 找不到計畫內容或預期成果");
        }

        // 下一欄是欄位名稱
        $area = array_shift($areas);
        if (!in_array(self::clean_space($area[0]['content']), [
            '分支計畫及用途別科目金額承辦單位說明',
            '分支計畫及用途別科目預算金額承辦單位說明',
        ])) {
            print_r($area);
            print_r(self::clean_space($area[0]['content']));
            throw new Exception("找不到分支計畫及用途別科目金額承辦單位說明");
        }
        // 最後一欄是完整資料
        $area = array_shift($areas);

        // 先抓垂直線位置
        $vertical_black = [];
        if (count($horizontal_black) == 5) {
            $edges = [$horizontal_black[2], $horizontal_black[4]];
        } else {
            $edges = [$horizontal_black[1], $horizontal_black[3]];
        }

        for ($x = 0; $x < imagesx($gd); $x++) {
            $color = imagecolorat($gd, $x, floor(($edges[0] + $edges[1]) / 2));
            $r = ($color >> 16) & 0xFF;
            $g = ($color >> 8) & 0xFF;
            $b = $color & 0xFF;
            if ($r < 150 && $g < 150 && $b < 150) {
                if (count($vertical_black) and abs($x - $vertical_black[count($vertical_black) - 1] ?? 0) < 7) {
                    continue;
                }
                $hit = 0;
                for ($y = $edges[0]; $y < $edges[1]; $y++) {
                    $color = imagecolorat($gd, $x, $y);
                    $r = ($color >> 16) & 0xFF;
                    $g = ($color >> 8) & 0xFF;
                    $b = $color & 0xFF;
                    if ($r < 150 && $g < 150 && $b < 150) {
                        $hit++;
                    }
                }
                if ($hit / abs($edges[1] - $edges[0]) < 0.5) {
                    continue;
                }
                $vertical_black[] = $x;
            }
        }

        // 重新處理 boxes 排序，因為最後一欄說明和承辦單位的行高跟前面不一定一致
        $td_groups = [
            0 => [], // 分支計畫及用途別科目、金額
            1 => [], // 承辦單位
            2 => [], // 說明
        ];
        foreach ($area as $boxes) {
            foreach ($boxes['boxes'] as $box) {
                for ($i = 0; $i < count($vertical_black); $i ++) {
                    if ($box['left'] > $vertical_black[$i]) {
                        continue;
                    }
                    break;
                }
                // 如果 nbsp 在最前面，則表示是空白
                $box['text'] = preg_replace_callback('#^(' . preg_quote(chr(0xc2) . chr(0xa0), '#') . ' ?)+#', function($matches) {
                    return str_repeat(' ', strlen($matches[0]) - 1);
                }, $box['text']);

                if (strpos($box['text'], chr(0xc2) . chr(0xa0)) !== false) {
                    foreach (explode(chr(0xc2) . chr(0xa0), $box['text']) as $idx => $text) {
                        $tds[$i + $idx][] = $text;
                    }
                    continue;
                }

                if ($i < 2) {
                    $td_groups[0][] = [$i, $box];
                } elseif ($i == 2) {
                    if (strpos($box['text'], chr(0xc2) . chr(0xa0)) !== false) {
                        // 如果有 nbsp 在中間，表示會被拆成多行
                        $box1 = $box;
                        $box2 = $box;
                        $box1['text'] = explode(chr(0xc2) . chr(0xa0), $box['text'])[0];
                        $box2['text'] = explode(chr(0xc2) . chr(0xa0), $box['text'])[1];
                        $td_groups[1][] = $box1;
                        $td_groups[2][] = $box2;
                    } else {
                        $td_groups[1][] = $box;
                    }
                } else {
                    $td_groups[2][] = $box;
                }
            }
        }

        usort($td_groups[0], function($a, $b) {
            // 如果 top 在 7px 內，看 left
            if (abs($a[1]['top'] - $b[1]['top']) <= 7) {
                return $a[1]['left'] <=> $b[1]['left'];
            }
            return $a[1]['top'] <=> $b[1]['top'];
        });

        $main_plan_top = [];
        // 先將分支計畫及用途別科目、金額的資料整理好
        
        $lines = [];
        foreach ($td_groups[0] as $td) {
            if ($td[0] == 0 and preg_match('#^(\d+)#', $td[1]['text'], $matches)) {
                $main_plan_top[$matches[1]] = $td[1]['top'];
            }
            if (count($lines) == 0) {
                $lines[] = [$td];
            } else {
                $last_line = $lines[count($lines) - 1];
                if (abs($last_line[count($last_line) - 1][1]['top'] - $td[1]['top']) <= 7) {
                    $lines[count($lines) - 1][] = $td;
                } else {
                    $lines[] = [$td];
                }
            }
        }
        $ret->lines = $lines;
        $ret->承辦單位 = [];
        $ret->說明 = [];

        foreach ($td_groups[1] as $td) {
            $main_plan_id = 'miss';
            foreach ($main_plan_top as $plan_id => $top) {
                if ($td['top'] < $top - 7) {
                    break;
                }
                $main_plan_id = $plan_id;
            }
            if (!$ret->承辦單位) {
                $ret->承辦單位[] = [$main_plan_id, [$td]];
            } else {
                $last_line = $ret->承辦單位[count($ret->承辦單位) - 1];
                if ($last_line[0] == $main_plan_id) {
                    $ret->承辦單位[count($ret->承辦單位) - 1][1][] = $td;
                } else {
                    $ret->承辦單位[] = [$main_plan_id, [$td]];
                }
            }
        }

        foreach ($td_groups[2] as $td) {
            $main_plan_id = 'miss';
            foreach ($main_plan_top as $plan_id => $top) {
                if ($td['top'] < $top - 7) {
                    break;
                }
                $main_plan_id = $plan_id;
            }
            if (!$ret->說明) {
                $ret->說明[] = [$main_plan_id, [$td]];
            } else {
                $last_line = $ret->說明[count($ret->說明) - 1];
                if ($last_line[0] == $main_plan_id) {
                    $ret->說明[count($ret->說明) - 1][1][] = $td;
                } else {
                    $ret->說明[] = [$main_plan_id, [$td]];
                }
            }
        }
        return $ret;
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

    public static function parse歲出計畫提要及分支計畫概況表($type_line, $callback, $type)
    {
        $plans = [];
        $id_說明 = null;
        $id_承辦單位 = null;
        foreach ($type_line as $page) {
            $data = $page['data'];
            if ($data->工作計畫名稱 ?? false) {
                $plan_id = $data->工作計畫編號;
                if (!($plans[$plan_id] ?? false)) {
                    $plans[$plan_id] = [
                        '單位' => $data->unit,
                        '工作計畫編號' => $data->工作計畫編號,
                        '工作計畫名稱' => $data->工作計畫名稱,
                        '預算金額' => $data->預算金額,
                        '計畫內容' => trim($data->計畫內容[0] ?? ''),
                        '預期成果' => trim($data->計畫內容[1] ?? ''),
                        'page' => $page['page'],
                        'lines' => [],
                        '承辦單位' => [],
                        '說明' => [],
                    ];
                } else {
                    if ($data->計畫內容[0] ?? false) {
                        $plans[$plan_id]['計畫內容'] .= "\n" . trim($data->計畫內容[0]);
                    }
                    if ($data->計畫內容[1] ?? false) {
                        $plans[$plan_id]['預期成果'] .= "\n" . trim($data->計畫內容[1]);
                    }
                }
            }

            foreach ($page['data']->lines as $lines) {
                $plans[$plan_id]['lines'][] = $lines;
            }

            foreach ($page['data']->承辦單位 as $line) {
                list($unit_id, $tds) = $line;
                if ($unit_id == 'miss') {
                    $unit_id = $id_承辦單位;
                    $plans[$plan_id]['承辦單位'][$unit_id] .= implode('', array_map(function($td) {
                        return $td['text'];
                    }, $tds));
                } else {
                    $id_承辦單位 = $unit_id;
                    $plans[$plan_id]['承辦單位'][$unit_id] = implode('', array_map(function($td) {
                        return $td['text'];
                    }, $tds));
                }
            }

            foreach ($page['data']->說明 as $line) {
                list($id, $tds) = $line;
                if ($id == 'miss') {
                    $id = $id_說明;
                    $plans[$plan_id]['說明'][$id] .= "\n" . implode("\n", array_map(function($td) {
                        return $td['text'];
                    }, $tds));
                } else {
                    $id_說明 = $id;
                    $plans[$plan_id]['說明'][$id] = implode("\n", array_map(function($td) {
                        return $td['text'];
                    }, $tds));
                }
            }
        }

        foreach ($plans as $plan_id => $plan_data) {
            if (strpos($plan_data['計畫內容'], '計畫內容：') !== 0) {
                print_r($plan_data['計畫內容']);
                throw new Exception("計畫內容格式錯誤");
            }
            if (strpos($plan_data['預期成果'], '預期成果：') !== 0) {
                print_r($plan_data['預期成果']);
                throw new Exception("預期成果格式錯誤");
            }
            $plan_data['計畫內容'] = trim(substr($plan_data['計畫內容'], strlen('計畫內容：')));
            $plan_data['預期成果'] = trim(substr($plan_data['預期成果'], strlen('預期成果：')));

            $callback('工作計畫', [
                '單位' => $plan_data['單位'],
                '工作計畫編號' => $plan_data['工作計畫編號'],
                '工作計畫名稱' => $plan_data['工作計畫名稱'],
                '預算金額' => $plan_data['預算金額'],
                '計畫內容' => $plan_data['計畫內容'],
                '預期成果' => $plan_data['預期成果'],
            ], $plan_data['page']);
            $ret = new StdClass;
            $ret->分支計劃 = new StdClass;

            $plan_id = null;
            $plan_name = null;
            $main_plan_id = null;
            $parent_plan_id_stack = [];
            $name_width = null;

            foreach ($plan_data['lines'] as $tds) {
                $tds[0][1]['text'] = str_replace('　', '  ', $tds[0][1]['text']);
                if (preg_match('#^(\s*)(\d+)$#', $tds[0][1]['text'], $matches)) {
                    $plan_id = $matches[2];
                    $plan_name = trim($tds[1][1]['text']);
                    $amount = str_replace(',', '', $tds[2][1]['text']);
                } elseif (preg_match('#^(\s*)(\d+)(.*)$#', $tds[0][1]['text'], $matches)) {
                    $plan_id = $matches[2];
                    $plan_name = trim($matches[3]);
                    $amount = str_replace(',', '', $tds[1][1]['text']);
                } elseif (mb_strlen($plan_name) >= 11 or in_array("{$plan_id}-{$plan_name}", [
                    '12-跨領域大樓基本行政工作維持',
                    '04-關鍵基礎設施防護運作展示介',
                    '04-辦理縣市政府新聞聯繫及輿情',
                    '03-政府內部控制監督機制規劃及',
                    '02-地方政府主計業務之督導與查',
                    '04-地方政府公務統計業務之推動',
                ])) {
                    // 遇到斷行的部份全部條列出來白名單處理，以防止誤判
                    $plan_name .= trim($tds[0][1]['text']);
                } else {
                    print_r($tds);
                    print_r("{$plan_id}-{$plan_name}");
                    throw new Exception("找不到計畫編號");
                }
                $space = $matches[1];
                if ($space == '') {
                    $main_plan_id = $plan_id;
                    $parent_plan_id_stack = [$plan_id];
                } else if ($name_width == strlen($space)) {
                    // 如果空格數沒有變，則表示 parent_plan_id 沒有變
                } else if ($name_width > strlen($space)) {
                    // 如果空格數變少，則表示 parent_plan_id 變了
                    array_pop($parent_plan_id_stack);
                } else {
                    // 如果空格數變多，則表示 parent_plan_id 變了
                    $parent_plan_id_stack[] = $plan_id;
                }
                $name_width = strlen($space);

                if (is_null($plan_id)) {
                    throw new Exception("找不到計畫名稱及編號");
                }

                if (!($ret->分支計劃->{$plan_id} ?? false)) {
                    $ret->分支計劃->{$plan_id} = new StdClass;
                    $ret->分支計劃->{$plan_id}->編號 = $plan_id;
                    $ret->分支計劃->{$plan_id}->科目 = $plan_name;
                    if ($main_plan_id == $plan_id) {
                        $ret->分支計劃->{$plan_id}->承辦單位 = '';
                        $ret->分支計劃->{$plan_id}->說明 = '';
                    } else {
                        $ret->分支計劃->{$plan_id}->母科目= $parent_plan_id_stack[count($parent_plan_id_stack) - 2];
                    }
                }

                $ret->分支計劃->{$plan_id}->金額 = $amount;
            }
            foreach ($plan_data['承辦單位'] as $main_plan_id => $text) {
                if (!($ret->分支計劃->{$main_plan_id} ?? false)) {
                    print_r($plan_data);
                    throw new Exception("找不到計畫編號");
                }
                $ret->分支計劃->{$main_plan_id}->承辦單位 = $text;
            }
            foreach ($plan_data['說明'] as $main_plan_id => $text) {
                $ret->分支計劃->{$main_plan_id}->說明 = $text;
            }

            foreach ($ret->分支計劃 as $plan_id => $sub_plan_data) {
                if ($sub_plan_data->母科目 ?? false) {
                    $callback('子分支計劃', [
                        '單位' => $plan_data['單位'],
                        '工作計畫編號' => $plan_data['工作計畫編號'],
                        '工作計畫名稱' => $plan_data['工作計畫名稱'],
                        '分支計畫編號' => $sub_plan_data->編號,
                        '分支計畫名稱' => $sub_plan_data->科目,
                        '母科目編號' => $sub_plan_data->母科目,
                        '金額' => $sub_plan_data->金額,
                    ], $page['page']);
                } else {
                    $callback('分支計劃', [
                        '單位' => $plan_data['單位'],
                        '工作計畫編號' => $plan_data['工作計畫編號'],
                        '工作計畫名稱' => $plan_data['工作計畫名稱'],
                        '分支計畫編號' => $sub_plan_data->編號,
                        '分支計畫名稱' => $sub_plan_data->科目,
                        '金額' => $sub_plan_data->金額,
                        '承辦單位' => $sub_plan_data->承辦單位,
                        '說明' => $sub_plan_data->說明,
                    ], $page['page']);
                }
            }
        }
    }

    public static function parse各項費用彙計表($type_line, $callback, $type)
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

        $header_content = null;
        foreach ($type_line['rows'] as $idx => $row) {
            // 處理 第一、二級用途別科目名稱及編號 被斷成兩行
            if (($type_line['rows'][$idx + 1] ?? false) and self::clean_space($row[0] . $type_line['rows'][$idx + 1][0]) == '第一、二級用途別科目名稱及編號') {
                $type_line['rows'][$idx][0] = '第一、二級用途別科目名稱及編號';
                $type_line['rows'][$idx + 1][0] = '';
            }

            // 如果 header 完全相同，可以忽略 header（處理資料被分到兩頁的情況）
            if (strpos($row[0], '工作計畫名稱及編號') !== false) {
                $current_header_content = implode('', $row)
                    . implode('', $type_line['rows'][$idx + 1])
                    . implode('', $type_line['rows'][$idx + 2]);
                $current_header_content = self::clean_space($current_header_content);
                if ($header_content == $current_header_content) {
                    unset($type_line['rows'][$idx]);
                    unset($type_line['rows'][$idx + 1]);
                    unset($type_line['rows'][$idx + 2]);
                    unset($type_line['organizations'][$idx]);
                    unset($type_line['organizations'][$idx + 1]);
                    unset($type_line['organizations'][$idx + 2]); 
                    continue;
                }
                $header_content = $current_header_content;
            }

        }

        // 如果這一行是「工作計畫名稱及編號」，但是「第一、二級用途別科目名稱及編號」在下下一行，把他拉上來
        foreach ($type_line['rows'] as $idx => $row) {
            if (strpos($row[0], '工作計畫名稱及編號') !== false) {
                if ($type_line['rows'][$idx + 2][0] == '第一、二級用途別科目名稱及編號' and $type_line['rows'][$idx + 1][0] == '') {
                    $type_line['rows'][$idx + 2][0] = '';
                    $type_line['rows'][$idx + 1][0] = '第一、二級用途別科目名稱及編號';
                }
            }
        }

        // 處理全空白
        foreach ($type_line['rows'] as $idx => $row) {
            if (implode('', $row) == '') {
                unset($type_line['rows'][$idx]);
                unset($type_line['organizations'][$idx]);
                continue;
            }
        }

        $type_line['rows'] = array_values($type_line['rows']);
        $type_line['organizations'] = array_values($type_line['organizations']);

        file_put_contents(__DIR__ . "/tmp.json", json_encode($type_line, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        while ($row = array_shift($type_line['rows'])) {
            $organization = array_shift($type_line['organizations']);
            // 處理全空白
            if (self::clean_space(implode('', $row)) == '') {
                continue;
            }
            // 處理第一行 工作計畫名稱及編號
            if (in_array(self::clean_space($row[0]), [
                '工作計畫名稱及編號',
                '機關(構)',
            ])) {
                $project_list = [];

                // 如果第一行後面都空白，第二行第一格是空白，表示被拆成兩行
                if (implode('', array_slice($row, 1)) == '' and $type_line['rows'][0][0] == '') {
                    $row = array_shift($type_line['rows']);
                    $organization = array_shift($type_line['organizations']);
                }

                // 處理空格
                for ($i = 1; $i < count($row); $i ++) {
                    // nbsp to space
                    $row[$i] = str_replace(chr(0xc2) . chr(0xa0), ' ', $row[$i]);
                    if (!preg_match('#^[0-9A ]*$#', $row[$i])) {
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
                if ($row[0] != '第一、二級用途別科目名稱及編號') {
                    throw new Exception("工作計畫名稱及編號下一行必需要是「第一、二級用途別科目名稱及編號」，結果是 " . $row[0]);
                }
                for ($i = 1; $row[$i] ?? false; $i ++) {
                    $project_list[$i - 1]['工作計畫名稱'] = self::clean_space($row[$i]);
                }
                // 如果後面還有文字，表示字太多要追加
                while ($type_line['rows'][0][0] == '') {
                    $row = array_shift($type_line['rows']);
                    $organization = array_shift($type_line['organizations']);
                    for ($i = 1; $i < count($row); $i ++) {
                        if ($row[$i]) {
                            $project_list[$i - 1]['工作計畫名稱'] .= self::clean_space($row[$i]);
                        }
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
                    $callback($type, self::outputData($cols, $values), $type_line['page']);
                }
                continue;
            }

            // 處理數字
            $row[0] = str_replace(chr(0xa0) . ' ', chr(0xa0), $row[0]);
            if (preg_match('#^\xa0*(\d+|※)\s*(.*)$#u', $row[0], $matches)) {
                $no = $matches[1];
                $name = $matches[2];

                while (true) {
                    // 處理名稱被擠到下一行
                    if (count($type_line['rows'])
                        and preg_match('#^[^0-9]+#', $type_line['rows'][0][0]) 
                        and !in_array(self::clean_space($type_line['rows'][0][0]), ['工作計畫名稱及編號', '機關(構)'])
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

                if ($no == '※' or $no % 100 == 0) {
                    $parent_id_no = [$no, $name, '', ''];
                } else {
                    $parent_id_no[2] = $no;
                    $parent_id_no[3] = $name;
                }

                for ($i = 1; $i < count($row); $i ++) {
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
                        '費用' => self::clean_space(str_replace(',', '', $row[$i])),
                    ];
                    $callback($type, self::outputData($cols, $values), $type_line['page']);
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
            if (in_array($type, [
                '各項費用彙計表',
                '各機關各項費用彙計表',
            ])) {
                return self::parse各項費用彙計表($type_line, $callback, $type);
            }

            if (in_array($type, [
                '歲出計畫提要及分支計畫概況表',
            ])) {
                return self::parse歲出計畫提要及分支計畫概況表($type_line, $callback, $type);
            }

            // 如果前面整行只有說明，就刪掉
            if (self::clean_space(implode('', $type_line['rows'][0])) == '說明') {
                array_shift($type_line['rows']);
                array_shift($type_line['organizations']);
            }
            $type_line['rows'] = array_values($type_line['rows']);
            $type_line['organizations'] = array_values($type_line['organizations']);

            // 先處理如果代碼跟名稱在 $row[4] 的，把他拆成兩行
            foreach ($type_line['rows'] as $idx => $row) {
                if ($idx == count($type_line['rows']) - 1) {
                    continue;
                }
                if ($type_line['rows'][$idx + 1][4] != '') {
                    continue;
                }
                if (preg_match('#^([0-9a-z]+)(.+)$#', self::clean_space($row[4]), $matches)) {
                    $type_line['rows'][$idx][4] = $matches[1];
                    $type_line['rows'][$idx + 1][4] = $matches[2];
                    continue;
                }
            }
            file_put_contents(__DIR__ . "/tmp.json", json_encode($type_line, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $no = null;
            $values = null;
            $prev_values = [];
            while ($row = array_shift($type_line['rows'])) {
                $organization = array_shift($type_line['organizations']);
                if (in_array(self::clean_space($row[4]), [
                    '合計',
                    '(1.一般政務支出)',
                ])) {
                    if (!is_null($values)) {
                        $callback($type, self::outputData($cols[$type], $values), $type_line['page']);
                    }
                    $values = [
                        '款名' => self::clean_space($row[4]),
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
                    // 確認只有第四欄有值的狀況
                    if (implode('', $clone_row) == '') {
                        $row[4] = trim($row[4]);
                        // 如果裡面不是 3100000000總統府 之類的格式，表示是名稱，寫入名稱繼續
                        if (!preg_match('#^([0-9a-z]+)(.*)#', self::clean_space($row[4]), $matches)) {
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
                            '編號' => self::clean_space($matches[1]),
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

    public static function getTextFromNode($node, $doc)
    {
        if (in_array($node->nodeName, ['p', 'b'])) {
            $t = '';
            foreach ($node->childNodes as $cnode) {
                $t .= self::getTextFromNode($cnode, $doc);
            }
            return $t;
        }

        if ('#text' == $node->nodeName) {
            return $node->textContent;
        }

        if ('br' == $node->nodeName) {
            return "\n";
        }
        if ('a' == $node->nodeName) {
            return $node->textContent;
        }
        echo "不知道的內容: ";
        print_r($doc->saveHTML($node));
        exit;
    }
}
