<?php
/**
 * 上传单页图片：POST order_no + index + file
 * - ESP32/PCL 模式：浏览器上传 1 位 PNG
 * - IPP 直打模式：浏览器上传 JPEG（彩色保留）
 * 全部页面到齐后按真实页数复核金额
 */
require_once dirname(__DIR__) . '/lib/print.php';
set_time_limit(60);

$no = preg_replace('/[^0-9A-Za-z]/', '', (string)(isset($_POST['order_no']) ? $_POST['order_no'] : ''));
$index = (int)(isset($_POST['index']) ? $_POST['index'] : 0);
if ($no === '' || $index < 1 || $index > MAX_PAGES) json_out(['ok' => false, 'msg' => '参数错误']);

$order = one('SELECT * FROM orders WHERE order_no=?', [$no]);
if (!$order) json_out(['ok' => false, 'msg' => '订单不存在']);
if ($order['status'] != 0) json_out(['ok' => false, 'msg' => '订单已支付，不能再上传']);

if (empty($_FILES['file'])) json_out(['ok' => false, 'msg' => '没有收到页面图片']);
$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK) json_out(['ok' => false, 'msg' => '第 ' . $index . ' 页上传失败 (' . $f['error'] . ')']);
if ($f['size'] > MAX_PAGE_MB * 1024 * 1024) json_out(['ok' => false, 'msg' => '单页图片过大']);

$mode = print_mode();
$head = @file_get_contents($f['tmp_name'], false, null, 0, 8);
if ($mode === 'ipp' || ($mode === 'esp32' && printer_lang() === 'escpr')) {
    if ($head === false || substr($head, 0, 3) !== "\xFF\xD8\xFF") {
        json_out(['ok' => false, 'msg' => '第 ' . $index . ' 页不是有效的 JPEG 图片']);
    }
    $ext = 'jpg';
} else {
    if ($head === false || substr($head, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        json_out(['ok' => false, 'msg' => '第 ' . $index . ' 页不是有效的 PNG 图片']);
    }
    $ext = 'png';
}

$dir = pages_dir_of($order);
if (!is_dir($dir) && !@mkdir($dir, 0777, true)) json_out(['ok' => false, 'msg' => '目录创建失败']);

$dst = $dir . '/p' . str_pad((string)$index, 3, '0', STR_PAD_LEFT) . '.' . $ext;
if (!move_uploaded_file($f['tmp_name'], $dst)) json_out(['ok' => false, 'msg' => '保存第 ' . $index . ' 页失败']);

$files = page_images($dir);
$real = count($files);

json_out([
    'ok' => true,
    'index' => $index,
    'received' => $real,
    'pages' => $real,
    'amount' => number_format(price_of($real, (int)$order['copies'], (int)$order['color'], (int)$order['duplex']), 2, '.', ''),
]);
