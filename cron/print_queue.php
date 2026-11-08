<?php
/**
 * 打印队列补打：处理所有 status=1（已支付待打印）的订单。
 * 用途：
 *   1) 易支付回调时打印机不在线/超时，稍后自动补打；
 *   2) 后台点“重打”后由计划任务接走；
 *   3) 手工触发一次。
 *
 * 用法（宝塔计划任务，每分钟）：
 *   php D:/wwwroot/esp32/cron/print_queue.php
 * 或：http://站点/cron/print_queue.php?token=设备令牌
 */
require_once dirname(__DIR__) . '/lib/print.php';

if (PHP_SAPI !== 'cli') {
    $token = isset($_GET['token']) ? (string)$_GET['token'] : '';
    if ($token === '' || !hash_equals((string)cfg('agent_token'), $token)) {
        http_response_code(403);
        echo 'forbidden';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}
ignore_user_abort(true);
set_time_limit(300);

if (print_mode() !== 'ipp') {
    echo "skip: 当前不是 IPP 模式（ESP32 模式由设备自己轮询领取）\n";
    exit;
}
if (ipp_target() === '') {
    echo "skip: 未配置打印机 IPP 地址\n";
    exit;
}

$ok = 0;
$fail = 0;
for ($i = 0; $i < 5; $i++) {
    $row = one('SELECT id FROM orders WHERE status=1 ORDER BY id ASC LIMIT 1');
    if (!$row) break;
    list($r, $msg) = run_print_job((int)$row['id']);
    if ($r) $ok++; else $fail++;
    log_line('print', 'queue #' . $row['id'] . ' ' . ($r ? 'ok' : 'fail') . ' ' . $msg);
}

echo "print queue done: ok={$ok} fail={$fail}\n";
