<?php
require_once dirname(__DIR__) . '/lib/print.php';

$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
if ($token === '' || !hash_equals((string)cfg('agent_token'), $token)) {
    json_out(['ok' => false, 'msg' => 'token invalid'], 403);
}

$action = isset($_GET['action']) ? $_GET['action'] : 'poll';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/** 打印盒最近上报里写着打印机离线，就别再给它派任务 */
function esp32_printer_offline_recent()
{
    $f = LOG_DIR . '/esp32_state.log';
    if (!is_file($f)) return false;
    $lines = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return false;
    $last = end($lines);
    if (strpos($last, 'printer=0') === false) return false;
    if (preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)/', $last, $m)) {
        return time() - strtotime($m[1]) < 30;
    }
    return true;
}

q('UPDATE printers SET online=1,last_seen=? WHERE type=?', [time(), 'esp32']);

// 设备每次请求都会带上自己的状态（打印机在不在线、IP、固件），合并写进 state 文件
$stateFile = LOG_DIR . '/esp32_state.json';
$prParam = isset($_GET['pr']) ? (int)$_GET['pr'] : -1;
$devState = isset($_GET['state']) ? substr((string)$_GET['state'], 0, 40) : '';
if ($prParam >= 0 || $devState !== '' || isset($_GET['ip']) || isset($_GET['fw'])) {
    $st = [];
    if (is_file($stateFile)) {
        $old = json_decode((string)@file_get_contents($stateFile), true);
        if (is_array($old)) $st = $old;
    }
    $st['ts'] = time();
    if ($prParam >= 0) $st['printer_online'] = ($prParam === 1);
    if ($devState !== '') $st['state'] = $devState;
    if (isset($_GET['ip'])) $st['ip'] = substr((string)$_GET['ip'], 0, 40);
    if (isset($_GET['fw'])) $st['fw'] = substr((string)$_GET['fw'], 0, 20);
    @file_put_contents($stateFile, json_encode($st, JSON_UNESCAPED_UNICODE));
}

if ($action === 'poll') {
    if (print_mode() === 'ipp') {
        // 当前走局域网 IPP 直打，ESP32 不参与，避免抢走任务
        json_out(['ok' => true, 'job' => null, 'note' => 'ipp mode']);
    }
    if (esp32_printer_offline_recent()) {
        // 打印机不在线，先不派任务，等打印盒下次上报在线
        json_out(['ok' => true, 'job' => null, 'note' => 'printer offline']);
    }
    $job = esp32_claim();
    if (!$job) json_out(['ok' => true, 'job' => null]);
    json_out(['ok' => true, 'job' => [
        'id' => (int)$job['id'],
        'order_no' => $job['order_no'],
        'file_name' => $job['file_name'],
        'pages' => (int)$job['pages'],
        'copies' => (int)$job['copies'],
        'duplex' => (int)$job['duplex'],
        'color' => (int)$job['color'],
    ]]);
}

if ($action === 'prepare') {
    $job = $id ? one('SELECT * FROM orders WHERE id=?', [$id]) : null;
    if (!$job) json_out(['ok' => false, 'msg' => 'job not found']);
    list($ok, $pcl) = esp32_prepare($job);
    if (!$ok) {
        finish_job($id, false, $pcl);
        json_out(['ok' => false, 'msg' => $pcl]);
    }
    json_out(['ok' => true, 'size' => filesize($pcl)]);
}

if ($action === 'file') {
    $job = $id ? one('SELECT * FROM orders WHERE id=?', [$id]) : null;
    if (!$job) json_out(['ok' => false, 'msg' => 'job not found'], 404);
    list($ok, $p) = job_print_file($job);
    if (!$ok) json_out(['ok' => false, 'msg' => $p], 500);
    $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($p));
    header('Content-Disposition: attachment; filename="' . $job['order_no'] . '.' . $ext . '"');
    @set_time_limit(0);
    readfile($p);
    exit;
}

if ($action === 'done') {
    $job = $id ? one('SELECT * FROM orders WHERE id=?', [$id]) : null;
    if (!$job) json_out(['ok' => false, 'msg' => 'job not found']);
    finish_job($id, true, 'ESP32 打印完成');
    json_out(['ok' => true]);
}

if ($action === 'error') {
    $msg = isset($_GET['msg']) ? (string)$_GET['msg'] : 'ESP32 打印失败';
    finish_job($id, false, $msg);
    json_out(['ok' => true]);
}

if ($action === 'pages') {
    $job = $id ? one('SELECT * FROM orders WHERE id=?', [$id]) : null;
    if (!$job) json_out(['ok' => false, 'msg' => 'job not found']);
    $dir = (string)$job['pages_dir'];
    $abs = ($dir !== '' && is_dir($dir)) ? $dir : APP_ROOT . '/' . ltrim($dir, '/');
    if ($dir === '' || !is_dir($abs)) {
        json_out(['ok' => false, 'msg' => 'no pages dir']);
    }
    $files = glob(rtrim($abs, '/') . '/p*.{jpg,jpeg,png}', GLOB_BRACE);
    if (!$files) json_out(['ok' => false, 'msg' => 'no page images']);
    sort($files);
    // 把真实文件名也给设备，避免服务器存的是 png 而设备拼死 p001.jpg
    $names = [];
    foreach ($files as $f) $names[] = basename($f);
    json_out([
        'ok' => true,
        'order_no' => $job['order_no'],
        'dir' => basename($abs),
        'pages' => count($files),
        'color' => (int)$job['color'],
        'duplex' => (int)$job['duplex'],
        'files' => $names,
    ]);
}

if ($action === 'log') {
    // 设备把串口日志推上来，后台「打印盒」页直接看
    $msg = isset($_GET['msg']) ? (string)$_GET['msg'] : '';
    $msg = trim(str_replace("\r", '', $msg));
    if ($msg !== '') {
        $file = LOG_DIR . '/esp32.log';
        $batch = '[' . date('Y-m-d H:i:s') . "] " . $msg . "\n";
        @file_put_contents($file, $batch, FILE_APPEND);
        // 超过 400KB 只留最后 200KB，别把虚拟主机塞满
        if (is_file($file) && filesize($file) > 400 * 1024) {
            $tail = substr((string)@file_get_contents($file), -200 * 1024);
            $nl = strpos($tail, "\n");
            if ($nl !== false) $tail = substr($tail, $nl + 1);
            @file_put_contents($file, $tail);
        }
    }
    json_out(['ok' => true]);
}

if ($action === 'ping') {
    if (isset($_GET['msg']) && $_GET['msg'] !== '') {
        $f = LOG_DIR . '/esp32_state.log';
        @file_put_contents($f, date('Y-m-d H:i:s') . ' ' . substr((string)$_GET['msg'], 0, 200) . "\n", FILE_APPEND);
        // 日志别无限涨，超过 80KB 只留最后 400 行
        if (is_file($f) && filesize($f) > 80 * 1024) {
            $keep = array_slice(@file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -400);
            @file_put_contents($f, implode("\n", $keep) . "\n");
        }
    }
    json_out(['ok' => true, 'time' => time()]);
}

json_out(['ok' => false, 'msg' => 'unknown action']);
