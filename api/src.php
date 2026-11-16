<?php
/**
 * 保存用户上传的原始文件：建单后由前端补传一次，后台可预览/下载
 */
require_once dirname(__DIR__) . '/lib/print.php';

/** 失败时写日志，方便后台排查为什么原件没存上 */
function src_fail($msg) {
    @file_put_contents(APP_ROOT . '/logs/src.log', date('c') . ' ' . $msg . "\n", FILE_APPEND);
    json_out(['ok' => false, 'msg' => $msg]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    src_fail('只支持 POST');
}

$no = trim((string)(isset($_POST['order_no']) ? $_POST['order_no'] : ''));
if (!preg_match('/^[0-9A-Za-z]{6,40}$/', $no)) {
    src_fail('订单号不正确');
}

$o = one('SELECT * FROM orders WHERE order_no=?', [$no]);
if (!$o) {
    src_fail('订单不存在');
}

if (!isset($_FILES['file']) || !is_array($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    src_fail('没有收到文件');
}

$f = $_FILES['file'];
if ($f['size'] <= 0 || $f['size'] > MAX_UPLOAD_MB * 1024 * 1024) {
    src_fail('文件大小超出限制');
}

$name = (string)(isset($_POST['file_name']) ? $_POST['file_name'] : '');
if ($name === '') $name = basename(str_replace('\\', '/', (string)$f['name']));
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
// 微信等环境可能拿不到后缀，退回订单自己的扩展名
if (!in_array($ext, explode(',', ALLOW_EXT), true)) {
    $alt = strtolower((string)$o['ext']);
    if (in_array($alt, explode(',', ALLOW_EXT), true)) $ext = $alt;
}
$ext = src_sniff_ext($f['tmp_name'], $ext);
if (!in_array($ext, explode(',', ALLOW_EXT), true)) {
    src_fail('不支持的文件类型');
}

if (!is_dir(RAW_DIR) && !@mkdir(RAW_DIR, 0777, true)) {
    src_fail('无法创建目录');
}

$rel = 'uploads/raw/' . $no . '.' . $ext;
$dst = APP_ROOT . '/' . $rel;

if (!@move_uploaded_file($f['tmp_name'], $dst)) {
    src_fail('保存失败，uploads/raw 不可写');
}

// 覆盖掉旧原件
if (!empty($o['file_path']) && $o['file_path'] !== $rel) {
    $old = APP_ROOT . '/' . ltrim($o['file_path'], '/');
    if (is_file($old)) @unlink($old);
}

q('UPDATE orders SET file_path=? WHERE id=?', [$rel, (int)$o['id']]);

json_out(['ok' => true, 'file_path' => $rel, 'size' => (int)$f['size']]);
