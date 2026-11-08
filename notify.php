<?php
require_once __DIR__ . '/lib/epay.php';
require_once __DIR__ . '/lib/print.php';

$p = array_merge($_GET, $_POST);
list($ok, $err) = epay_verify($p);
if (!$ok) {
    log_line('epay', 'verify fail: ' . $err . ' ' . json_encode($p, JSON_UNESCAPED_UNICODE));
    exit('fail');
}
if (empty($p['out_trade_no'])) exit('fail');
if (isset($p['trade_status']) && $p['trade_status'] !== 'TRADE_SUCCESS') exit('success');

$order = one('SELECT * FROM orders WHERE order_no=?', [$p['out_trade_no']]);
if (!$order) {
    log_line('epay', 'order not found: ' . $p['out_trade_no']);
    exit('fail');
}
if ((int)$order['status'] !== 0) exit('success');

$money = isset($p['money']) ? (float)$p['money'] : 0;
if (abs($money - (float)$order['amount']) > 0.01) {
    log_line('epay', 'money mismatch: ' . $money . ' vs ' . $order['amount'] . ' ' . $order['order_no']);
    exit('fail');
}

q('UPDATE orders SET status=1, trade_no=?, pay_type=?, pay_time=? WHERE id=? AND status=0', [
    isset($p['trade_no']) ? (string)$p['trade_no'] : '',
    isset($p['type']) ? (string)$p['type'] : '',
    time(),
    $order['id'],
]);
log_line('epay', 'paid ' . $order['order_no'] . ' money=' . $money);

// IPP 直打：付款成功立刻把 PDF 交给打印机（同步执行，失败可后台重打）
if (print_mode() === 'ipp' && ipp_target() !== '') {
    @ignore_user_abort(true);
    @set_time_limit(300);
    list($pok, $pmsg) = run_print_job((int)$order['id']);
    log_line('print', 'ipp ' . $order['order_no'] . ' ' . ($pok ? 'ok' : 'fail') . ' ' . $pmsg);
}

echo 'success';
