<?php
require_once __DIR__ . '/lib/epay.php';
$p = array_merge($_GET, $_POST);
$no = isset($p['out_trade_no']) ? trim($p['out_trade_no']) : '';
if ($no === '' && isset($p['out_trade_no'])) $no = trim($p['out_trade_no']);
if ($no === '') {
    header('Location: index.php');
    exit;
}
header('Location: order.php?no=' . urlencode($no));
exit;
