<?php
require_once dirname(__DIR__) . '/lib/func.php';
header('Cache-Control: no-cache, must-revalidate');
json_out(print_box_status());
