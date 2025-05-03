<?php

include(__DIR__ . '/../init.inc.php');

$to_numbers = function($n) {
    if ($n == '-') {
        return null;
    }
    $n = str_replace(',', '', $n);
    if (trim($n) == '') {
        return null;
    }
    if (preg_match('/^-?\d+$/', $n)) {
        return (int)$n;
    }
    throw new Exception("Invalid number: {$n}");
};

foreach ([
    '工作計畫' => 'proposed_budget_project',
    '分支計劃' => 'proposed_budget_branch_project',
    '子分支計劃' => 'proposed_budget_sub_branch_project',
    '歲入來源別預算表' => 'proposed_budget_income_by_source',  
    '歲出機關別預算表' => 'proposed_budget_expenditure_by_agency',
    '歲出政事別預算表' => 'proposed_budget_expenditure_by_policy',
    '各項費用彙計表' => 'proposed_budget_expenditure_by_item',
] as $t => $index) {
    $fp = fopen(__DIR__ . "/../outputs/{$t}.csv", "r");
    $cols = fgetcsv($fp);
    while ($rows = fgetcsv($fp)) {
        $values = array_combine($cols, $rows);
        $values['年度'] = intval($values['年度']);
        if ('工作計畫' == $t) {
            $id = "{$values['單位代碼']}-{$values['年度']}-{$values['單位']}-{$values['工作計畫編號']}";
            $values['預算金額'] = $to_numbers($values['預算金額']);
        } elseif ('分支計劃' == $t) {
            $id = "{$values['單位代碼']}-{$values['年度']}-{$values['單位']}-{$values['工作計畫編號']}-{$values['分支計畫編號']}";
            $values['金額'] = $to_numbers($values['金額']);
        } elseif ('子分支計劃' == $t) {
            $id = "{$values['單位代碼']}-{$values['年度']}-{$values['單位']}-{$values['工作計畫編號']}-{$values['分支計畫編號']}";
            $values['金額'] = $to_numbers($values['金額']);
        } elseif (in_array($t, [
            '歲入來源別預算表',
            '歲出機關別預算表',
            '歲出政事別預算表',
        ])) {
            $id = "{$values['單位代碼']}-{$values['年度']}-{$values['編號']}";
            try {
                $values['本年度預算數'] = $to_numbers($values['本年度預算數']);
                $values['上年度預算數'] = $to_numbers($values['上年度預算數']);
                $values['前年度決算數'] = $to_numbers($values['前年度決算數']);
                $values['本年度與上年度比較'] = $to_numbers($values['本年度與上年度比較']);
            } catch (Exception $e) {
                print_r($values);
                throw $e;
            }
        } else {
            print_r($values);
            print_r($t);
            exit;
        }
        Elastic::dbBulkInsert($index, $id, $values);
    }
    Elastic::dbBulkCommit();
}
