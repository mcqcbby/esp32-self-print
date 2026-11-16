<?php
/**
 * 后台：读取订单的原始文件，支持在线查看与下载
 */
require_once __DIR__ . '/inc.php';
admin_guard();

$id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
$dl = isset($_GET['dl']) && $_GET['dl'] == '1';
$o = $id ? one('SELECT * FROM orders WHERE id=?', [$id]) : null;

if (!$o) {
    http_response_code(404);
    exit('文件不存在');
}

$path = order_src_path($o);
if ($path === '') {
    http_response_code(404);
    exit('文件不存在');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
// 文件实际内容和后缀不一致时，以真实内容为准
$ext = src_sniff_ext($path, $ext);
$mime = [
    'pdf'  => 'application/pdf',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];
$type = isset($mime[$ext]) ? $mime[$ext] : 'application/octet-stream';

$name = $o['file_name'] !== '' ? $o['file_name'] : ('order-' . $o['order_no'] . '.' . $ext);
// 文件名可能带着转换后的扩展名，这里按真实文件扩展名纠正
$base = pathinfo($name, PATHINFO_FILENAME);
if ($base === '') $base = 'order-' . $o['order_no'];
$name = $base . '.' . $ext;

header('Content-Type: ' . $type);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
if ($dl) {
    header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
} else {
    header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($name));
}
header('Cache-Control: private, max-age=60');
readfile($path);
exit;
