<?php
date_default_timezone_set('Asia/Shanghai');
require_once __DIR__ . '/db.php';

// 接口只返回 JSON：先把所有输出缓冲起来，避免 PHP 提示/警告的 HTML 混进 JSON 里
if (!ob_get_level()) {
    ob_start();
}

function json_out($data, $code = 200)
{
    // 扔掉之前任何杂项输出（notice、warning、BOM 之类），只输出纯 JSON
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function order_no()
{
    return date('YmdHis') . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function pick_code()
{
    $chars = '0123456789';
    $s = '';
    for ($i = 0; $i < 6; $i++) $s .= $chars[random_int(0, 9)];
    return $s;
}

function client_ip()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return $ip;
}

function site_url()
{
    // 后台填了“公网访问地址”就优先用它，保证易支付回调地址始终是公网可达的域名，
    // 不受当前是从内网 IP 还是域名打开页面影响（内网穿透场景必填）。
    $fixed = trim((string)cfg('site_domain', ''));
    if ($fixed !== '') {
        if (!preg_match('~^https?://~i', $fixed)) $fixed = 'http://' . $fixed;
        return rtrim($fixed, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '127.0.0.1';
    return ($https ? 'https' : 'http') . '://' . $host;
}

function log_line($name, $msg)
{
    if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0777, true);
    @file_put_contents(LOG_DIR . '/' . $name . '.log', date('Y-m-d H:i:s') . ' ' . $msg . "\r\n", FILE_APPEND);
}

/**
 * 计价
 * 单面：按"页"计，黑白 price_bw / 彩色 price_color
 * 双面：按"张"计（一张纸印两面），黑白 price_bw_duplex / 彩色 price_color_duplex，页数折半向上取整
 */
function price_of($pages, $copies, $color, $duplex = 0)
{
    $pages = max(0, (int)$pages);
    $copies = max(1, (int)$copies);
    if ($duplex) {
        $unit = $color ? (float)cfg('price_color_duplex', '0.80') : (float)cfg('price_bw_duplex', '0.60');
        $amount = (int)ceil($pages / 2) * $copies * $unit;
    } else {
        $unit = $color ? (float)cfg('price_color', '0.50') : (float)cfg('price_bw', '0.40');
        $amount = $pages * $copies * $unit;
    }
    $min = (float)cfg('min_amount', '0.10');
    if ($amount < $min) $amount = $min;
    return round($amount, 2);
}

function status_text($s)
{
    $map = [0 => '待支付', 1 => '已支付 排队中', 2 => '打印中', 3 => '已完成', 4 => '打印失败', 5 => '已退款'];
    return isset($map[$s]) ? $map[$s] : '未知';
}

function is_admin()
{
    return !empty($_SESSION['admin']);
}

function admin_guard()
{
    if (!is_admin()) {
        header('Location: login.php');
        exit;
    }
}

/** 打印设备是否在线：ESP32 打印盒按心跳时间判断，IPP 模式直接探 631 端口 */
function print_box_status()
{
    $mode = cfg('print_mode', 'esp32');
    if ($mode === 'ipp') {
        $url = cfg('ipp_url', '');
        $host = '';
        $port = 631;
        if (preg_match('~^https?://([^/:]+)(?::(\d+))?~i', $url, $m)) {
            $host = $m[1];
            if (!empty($m[2])) $port = (int)$m[2];
        }
        $online = false;
        if ($host !== '') {
            $fp = @fsockopen($host, $port, $errno, $errstr, 0.8);
            if ($fp) {
                $online = true;
                @fclose($fp);
            }
        }
        return [
            'mode' => 'ipp',
            'online' => $online,
            'text' => $online ? '打印机在线' : '打印机离线',
            'detail' => $host !== '' ? $host . ':' . $port : '打印机地址未配置',
        ];
    }

    $row = one("SELECT online,last_seen FROM printers WHERE type='esp32' ORDER BY id DESC LIMIT 1");
    $alive = false;
    if ($row && (int)$row['online'] === 1) {
        $alive = (time() - (int)$row['last_seen']) <= 30;
    }
    // 打印盒心跳带了打印机自己的在线状态：打印机离线时不要把「在线」误报给用户
    $st = [];
    $f = LOG_DIR . '/esp32_state.json';
    if (is_file($f)) {
        $tmp = json_decode((string)@file_get_contents($f), true);
        if (is_array($tmp)) $st = $tmp;
    }
    $prOk = isset($st['printer_online']) ? (bool)$st['printer_online'] : null;
    $online = $alive && ($prOk !== false);
    if (!$alive) {
        $text = '打印盒离线';
        $detail = '设备未上报，下单后可能无法立即出纸';
    } elseif ($prOk === false) {
        $text = '打印机离线';
        $detail = '打印盒已连上服务器，但连不到打印机，恢复后会自动领取任务';
    } elseif ($prOk === null) {
        $text = '打印盒在线';
        $detail = '正在等待打印任务';
    } else {
        $text = '打印机在线';
        $detail = '正在等待打印任务';
    }
    return [
        'mode' => 'esp32',
        'online' => $online,
        'text' => $text,
        'detail' => $detail,
    ];
}
