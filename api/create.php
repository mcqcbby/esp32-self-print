<?php
/**
 * 创建订单（前端已把文件渲染成 N 页，这里先落单，随后逐页上传）
 */
require_once dirname(__DIR__) . '/lib/print.php';
set_time_limit(60);

$file_name = trim((string)(isset($_POST['file_name']) ? $_POST['file_name'] : 'document.pdf'));
$file_name = mb_substr_cut($file_name, 120);
$ext = strtolower((string)(isset($_POST['ext']) ? $_POST['ext'] : 'pdf'));
if (!in_array($ext, explode(',', ALLOW_EXT), true)) $ext = 'pdf';

$pages = (int)(isset($_POST['pages']) ? $_POST['pages'] : 0);
if ($pages < 1 || $pages > MAX_PAGES) json_out(['ok' => false, 'msg' => '页数超出范围（1-' . MAX_PAGES . '）']);

$copies = max(1, min(50, (int)(isset($_POST['copies']) ? $_POST['copies'] : 1)));
$color = ((int)(isset($_POST['color']) ? $_POST['color'] : 0) === 1) ? 1 : 0;
$duplex = ((int)(isset($_POST['duplex']) ? $_POST['duplex'] : 0) === 1) ? 1 : 0;
$paper = (isset($_POST['paper']) && $_POST['paper'] === 'A3') ? 'A3' : 'A4';
$buyer = mb_substr_cut(isset($_POST['buyer']) ? $_POST['buyer'] : '', 20);
$phone = preg_replace('/[^0-9]/', '', (string)(isset($_POST['phone']) ? $_POST['phone'] : ''));
$dorm = mb_substr_cut(isset($_POST['dorm']) ? $_POST['dorm'] : '', 20);
if ($buyer === '') json_out(['ok' => false, 'msg' => '请填写姓名']);
if (strlen($phone) < 6) json_out(['ok' => false, 'msg' => '请填写正确的电话']);
if ($dorm === '') json_out(['ok' => false, 'msg' => '请填写宿舍号']);

$no = order_no();
$dir = PAGES_DIR . '/' . $no;
if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
    json_out(['ok' => false, 'msg' => '无法创建目录，请检查 uploads 权限']);
}

$amount = price_of($pages, $copies, $color, $duplex);
q('INSERT INTO orders(order_no,file_name,file_path,ext,pages,copies,color,duplex,paper,amount,status,buyer,phone,dorm,pick_code,target,pages_dir,client_ip,create_time) VALUES(?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,?,?,?,?)', [
    $no, $file_name, '', $ext, $pages, $copies, $color, $duplex, $paper, $amount,
    $buyer, $phone, $dorm, pick_code(), cfg('print_target', 'esp32'), 'uploads/pages/' . $no, client_ip(), time(),
]);

$mode = print_mode();
if ($mode === 'esp32' && printer_lang() === 'escpr') $mode = 'escpr';

json_out([
    'ok' => true,
    'mode' => $mode,
    'order_no' => $no,
    'file_name' => $file_name,
    'pages' => $pages,
    'copies' => $copies,
    'color' => $color,
    'duplex' => $duplex,
    'paper' => $paper,
    'buyer' => $buyer,
    'phone' => $phone,
    'dorm' => $dorm,
    'amount' => number_format($amount, 2, '.', ''),
]);
