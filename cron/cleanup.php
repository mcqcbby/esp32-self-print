<?php
/**
 * 清理任务：删除 24 小时未支付的订单、清理 7 天前已完成订单的页面图片
 * 用法（宝塔计划任务，每天一次）：
 *   php D:/wwwroot/esp32/cron/cleanup.php
 * 或浏览器访问（建议加 token）：http://站点/cron/cleanup.php?token=你的设备令牌
 */
require_once dirname(__DIR__) . '/lib/print.php';

if (PHP_SAPI !== 'cli') {
    $token = isset($_GET['token']) ? (string)$_GET['token'] : '';
    if ($token === '' || !hash_equals((string)cfg('agent_token'), $token)) {
        http_response_code(403);
        echo 'forbidden';
        exit;
    }
}

$now = time();
$n = 0;

// 1) 24 小时未支付：删单并清文件
$stale = all('SELECT * FROM orders WHERE status=0 AND create_time < ?', [$now - 86400]);
foreach ($stale as $o) {
    purge_order_files($o);
    q('DELETE FROM orders WHERE id=?', [$o['id']]);
    $n++;
}

// 2) 7 天前已完成的订单：只清页面图片与 PCL，保留订单记录
$old = all('SELECT * FROM orders WHERE status=3 AND print_time < ? AND pages_dir <> ?', [$now - 7 * 86400, '']);
foreach ($old as $o) {
    $dir = pages_dir_of($o);
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if ($f !== '.' && $f !== '..') @unlink($dir . '/' . $f);
        }
        @rmdir($dir);
    }
    @unlink(PCL_DIR . '/' . $o['order_no'] . '.pcl');
    q('UPDATE orders SET pages_dir=? WHERE id=?', ['', $o['id']]);
    $n++;
}

echo "cleanup done: {$n}\n";
