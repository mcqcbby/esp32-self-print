<?php
/**
 * 队列与打印执行（纯 PHP，无任何外部程序）
 */
require_once __DIR__ . '/pcl.php';
require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/ipp.php';
require_once __DIR__ . '/escpr.php';

/** 当前出纸方式：esp32（USB 打印盒） / ipp（局域网 AirPrint 直打） */
function print_mode()
{
    return (cfg('print_mode', 'esp32') === 'ipp') ? 'ipp' : 'esp32';
}

/**
 * ESP32 打印盒下发的打印语言：
 *   pcl5  —— PCL5/PCL5c 点阵流，兼容 PCL5 及绝大多数 PCL6 机型（PCL6 设备向下兼容 PCL5）
 *   escpr —— 爱普生 ESC/P-R JPEG 通道，给 L4168 这类只认 ESC/P-R 的喷墨机
 */
function printer_lang()
{
    $v = strtolower(trim((string)cfg('printer_lang', 'pcl5')));
    return ($v === 'escpr') ? 'escpr' : 'pcl5';
}

/** 打印机 IPP 地址，允许只填 IP */
function ipp_target()
{
    $u = trim((string)cfg('ipp_url', ''));
    if ($u === '') return '';
    if (!preg_match('~^https?://~i', $u)) $u = 'http://' . $u;
    if (!preg_match('~/(ipp|ipp/print|ipp/printer)~i', $u)) $u = rtrim($u, '/') . '/ipp/print';
    return $u;
}

/** 走 IPP 直打：把每一页 JPEG 逐页交给打印机（爱普生支持 image/jpeg，不支持 PDF） */
function ipp_print_order($order)
{
    $dir = pages_dir_of($order);
    $files = page_images($dir);
    if (!$files) return [false, '没有页面图片，请重新上传文件'];
    $target = ipp_target();
    if ($target === '') return [false, '未配置打印机 IPP 地址，请在后台设置中填写'];

    $copies = max(1, (int)$order['copies']);
    $okCount = 0;
    $err = '';
    foreach ($files as $f) {
        for ($c = 1; $c <= $copies; $c++) {
            list($ok, $msg) = ipp_print_jpeg($target, $f, 'order-' . $order['order_no'], (int)$order['duplex'], $order['paper'], (int)$order['color']);
            if ($ok) {
                $okCount++;
            } else {
                $err = $msg;
                break 2;
            }
        }
    }
    if ($okCount === 0) return [false, 'IPP 未送出任何页面：' . $err];
    $total = count($files) * $copies;
    if ($okCount < $total) return [false, "只送出 $okCount/$total 页：" . $err];
    return [true, '已提交打印机 ' . $okCount . ' 页（IPP image/jpeg）'];
}

/** 打印机 IP：从 ipp_url（如 192.168.1.105:631）里取 */
function raw_host()
{
    $u = trim((string)cfg('ipp_url', ''));
    if ($u === '') return '';
    if (!preg_match('~^https?://~i', $u)) $u = 'http://' . $u;
    $p = @parse_url($u);
    return ($p && !empty($p['host'])) ? $p['host'] : '';
}

/** 把打印流直接写进打印机 9100 RAW 端口（不需要 HTTPS，绕开 IPP 的加密要求） */
function raw_send($host, $port, $file)
{
    if ($host === '') return [false, '未配置打印机 IP，请在后台设置中填写'];
    if (!is_file($file)) return [false, '找不到打印数据'];
    $fp = @fsockopen('tcp://' . $host, $port, $e, $m, 5);
    if (!$fp) return [false, '连不上打印机 ' . $host . ':' . $port . '（' . ($m !== '' ? $m : '超时') . '）'];
    stream_set_timeout($fp, 60);
    $data = file_get_contents($file);
    $len = strlen($data);
    $w = 0;
    while ($w < $len) {
        $n = @fwrite($fp, substr($data, $w));
        if ($n === false || $n === 0) break;
        $w += $n;
    }
    @fflush($fp);
    @fclose($fp);
    if ($w < $len) return [false, "打印数据只送出 $w/$len 字节"];
    return [true, "已发送 $len 字节到 $host:9100"];
}

/** 9100 RAW 直打：按当前语言生成打印流后发送 */
function raw_print_order($order)
{
    list($ok, $file) = esp32_prepare($order);
    if (!$ok) return [false, $file];
    return raw_send(raw_host(), 9100, $file);
}

/** 执行一次打印：领任务 → 出纸 → 写回结果 */
function run_print_job($id)
{
    // ESP32 通道：不走本机 IPP/9100，订单留队列等打印盒来领取
    if (print_mode() !== 'ipp') {
        q('UPDATE orders SET status=1, target=?, printer=?, print_msg=? WHERE id=?', [
            'esp32', 'esp32', '已排队，等待 ESP32 打印盒领取', $id,
        ]);
        return [true, '已排队，等待 ESP32 打印盒领取'];
    }
    if (ipp_target() === '') {
        return [false, '未配置打印机 IPP 地址，订单保持待打印'];
    }
    $row = one('SELECT * FROM orders WHERE id=?', [$id]);
    if (!$row) return [false, '订单不存在'];
    if ((int)$row['status'] !== 1) return [false, '订单当前状态不是待打印'];

    $n = q('UPDATE orders SET status=2, print_time=?, printer=? WHERE id=? AND status=1', [
        time(), print_mode(), $id,
    ])->rowCount();
    if ($n < 1) return [false, '任务已被取走'];

    list($ok, $msg) = ipp_print_order($row);
    if (!$ok) {
        // IPP 只接受 HTTPS（426）或打印机休眠时，自动回退 9100 RAW 直写
        list($ok2, $msg2) = raw_print_order($row);
        if ($ok2) {
            $ok = true;
            $msg = '已走 9100 RAW（IPP 不通：' . $msg . '）';
        } else {
            $msg = 'IPP：' . $msg . '；9100：' . $msg2;
        }
    }
    finish_job($id, $ok, $msg);
    return [$ok, $msg];
}


function abs_path($rel)
{
    if ($rel === '') return '';
    if (preg_match('/^[A-Za-z]:[\\\\\/]/', $rel)) return $rel;
    return APP_ROOT . '/' . ltrim(str_replace('\\', '/', $rel), '/');
}

function mb_substr_cut($s, $len)
{
    $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
    if (function_exists('mb_substr')) return mb_substr($s, 0, $len, 'UTF-8');
    return substr($s, 0, $len);
}

function finish_job($id, $ok, $msg = '')
{
    $msg = mb_substr_cut($msg, 240);
    if ($ok) {
        q('UPDATE orders SET status=3, print_msg=? WHERE id=?', [$msg === '' ? '打印成功' : $msg, $id]);
    } else {
        q('UPDATE orders SET status=4, print_msg=? WHERE id=?', [$msg === '' ? '打印失败' : $msg, $id]);
    }
}

/** ESP32 领任务（原子） */
function esp32_claim()
{
    $row = one('SELECT * FROM orders WHERE status=1 ORDER BY id ASC LIMIT 1');
    if (!$row) return null;
    $n = q("UPDATE orders SET status=2, print_time=?, printer='esp32' WHERE id=? AND status=1", [time(), $row['id']])->rowCount();
    if ($n < 1) return null;
    $row['status'] = 2;
    return $row;
}

/** 预处理：把页面图片编译成打印流（PCL5 或 ESC/P-R） */
function esp32_prepare($order)
{
    if (printer_lang() === 'escpr') {
        $dst = PCL_DIR . '/' . $order['order_no'] . '.escpr';
        if (is_file($dst) && filesize($dst) > 64) return [true, $dst];
        $files = page_images(pages_dir_of($order));
        return escpr_a4_from_images($files, $dst, (bool)$order['color'], $order['paper'], (int)$order['copies']);
    }
    $pcl = PCL_DIR . '/' . $order['order_no'] . '.pcl';
    if (is_file($pcl) && filesize($pcl) > 1024) return [true, $pcl];
    return build_pcl($order);
}

/** 订单对应的打印数据文件（按当前语言取，找不到就现场生成） */
function job_print_file($order)
{
    $want = printer_lang() === 'escpr'
        ? PCL_DIR . '/' . $order['order_no'] . '.escpr'
        : PCL_DIR . '/' . $order['order_no'] . '.pcl';
    if (is_file($want)) return [true, $want];
    return esp32_prepare($order);
}

function order_by_no($no)
{
    return one('SELECT * FROM orders WHERE order_no=?', [$no]);
}

/** 清理订单的页面图片与打印文件 */
function purge_order_files($order)
{
    $dir = pages_dir_of($order);
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if ($f !== '.' && $f !== '..') @unlink($dir . '/' . $f);
        }
        @rmdir($dir);
    }
    @unlink(PCL_DIR . '/' . $order['order_no'] . '.pcl');
    @unlink(PCL_DIR . '/' . $order['order_no'] . '.escpr');
    @unlink(PCL_DIR . '/' . $order['order_no'] . '.pdf');
    if (!empty($order['file_path'])) @unlink(abs_path($order['file_path']));
}
