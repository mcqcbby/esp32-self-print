<?php
/**
 * 打印队列补打（历史脚本，保留兼容）
 * 出纸只有 ESP32 打印盒一条通道，订单由打印盒自己 poll 领取，
 * 所以这里不再需要任何服务端推送逻辑，直接退出。
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

echo "skip: 出纸由 ESP32 打印盒轮询领取，无需服务端补打\n";
