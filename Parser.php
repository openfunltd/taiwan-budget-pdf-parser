<?php

class Parser
{
    public static function toHTML($pdffile)
    {
        if (file_exists(__DIR__ . "/tmp")) {
            system("rm -rf " . escapeshellarg(__DIR__ . "/tmp"));
        }
        mkdir(__DIR__ . "/tmp");
        system("pdftohtml -c -s " . escapeshellarg($pdffile) . " " . escapeshellarg(__DIR__ . "/tmp/tmp"));
    }

    public static function parseHTML($file)
    {
        $contents = explode('<!DOCTYPE html>', file_get_contents($file));
        $type_lines = [];
        foreach ($contents as $html_content) {
            if (!$html_content) {
                continue;
            }
            if (!preg_match('#id="page([0-9]+)-div"#', $html_content, $matches)) {
                throw new Exception("找不到頁數");
            }
            $page = $matches[1];
            error_log("Page: $page");
            $doc = new DOMDocument();
            @$doc->loadHTML($html_content);
            $boxes = [];
            foreach ($doc->getElementsByTagName('p') as $p_dom) {
                $style = $p_dom->getAttribute('style');
                if (!preg_match('#position:absolute;top:([0-9]+)px;left:([0-9]+)px#', $style, $matches)) {
                    continue;
                }
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
                if ($top !== null && $top + 3 < $box['top']) {
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
                $gd = imagecreatefrompng(__DIR__ . "/tmp/" . basename($backgroup_image));
                // 檢查圖片中 top=300 中，從左到右有哪些 pixel 是有黑色的點
                $black = [];
                for ($x = 0; $x < imagesx($gd); $x++) {
                    $color = imagecolorat($gd, $x, 500);
                    $r = ($color >> 16) & 0xFF;
                    $g = ($color >> 8) & 0xFF;
                    $b = $color & 0xFF;
                    if ($r < 100 && $g < 100 && $b < 100) {
                        $black[] = $x;
                    }
                }
            }

            // 先一行一行抓到名稱與編號為止
            while (count($line_boxes)) {
                $line_box = array_shift($line_boxes);
                $line = $line_box['content'];
                if (strpos($line, '名稱及編號') !== false) {
                    break;
                }
            }
            if (!$line_box) {
                throw new Exception("找不到名稱及編號");
            }
            // 最後一個 line_box 的 content 檢查是否與頁碼相同
            if (!preg_match('#^\d+$#', $line_boxes[count($line_boxes) - 1]['content'])) {
                print_r($line_boxes[count($line_boxes) - 1]);
                print_r($page);
                throw new Exception("頁碼不符");
            }
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
                    $rows[$black_index] = $text;
                }
                if (!array_key_exists($type, $type_lines)) {
                    $type_lines[$type] = [];
                }
                $type_lines[$type][] = $rows;
            }
        }
        return $type_lines;
    }
}
