<?php

include(__DIR__ . '/Elastic.php');

// timezone Asia/Taipei
date_default_timezone_set('Asia/Taipei');

if (file_exists(__DIR__ . '/config.php')) {
    include(__DIR__ . '/config.php');
}
