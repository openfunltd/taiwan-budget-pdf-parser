<?php

include(__DIR__ . "/Parser.php");
$file = __DIR__ . "/sample.pdf";
$file = __DIR__ . '/內政部.pdf';
//Parser::toHTML($file);
$ret = Parser::parseHTML(__DIR__ . "/tmp/tmp-html.html");
$ret = Parser::parseData($ret);
print_r($ret);
