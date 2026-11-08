<?php
require_once dirname(__DIR__) . '/lib/func.php';
$no = isset($_GET['no']) ? trim($_GET['no']) : '';
$o = $no === '' ? null : one('SELECT status,print_msg,pay_time FROM orders WHERE order_no=?', [$no]);
if (!$o) json_out(['ok' => false, 'msg' => 'not found']);
json_out([
    'ok' => true,
    'status' => (int)$o['status'],
    'status_text' => status_text((int)$o['status']),
    'print_msg' => $o['print_msg'],
    'pay_time' => (int)$o['pay_time'],
]);
