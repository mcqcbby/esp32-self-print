<?php
/**
 * 队列与打印执行（纯 PHP，无任何外部程序）
 * 出纸只有一条通道：ESP32 打印盒来领取任务，再由打印盒用 IPP 发给打印机。
 */
require_once __DIR__ . '/pcl.php';
require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/escpr.php';

/**
 * ESP32 打印盒下发的打印语言：
 *   pcl5  —— PCL5/PCL5c 点阵流，兼容 PCL5 及绝大多数 PCL6 机型
 *   escpr —— 爱普生 ESC/P-R JPEG 通道，给 L4168 这类只认 ESC/P-R 的喷墨机
 */
function printer_lang()
{
    $v = strtolower(trim((string)cfg('printer_lang', 'pcl5')));
    return ($v === 'escpr') ? 'escpr' : 'pcl5';
}

/** 执行一次打印：ESP32 通道只排队，等打印盒自己来领取 */
function run_print_job($id)
{
    q('UPDATE orders SET status=1, target=?, printer=?, print_msg=? WHERE id=?', [
        'esp32', 'esp32', '已排队，等待 ESP32 打印盒领取', $id,
    ]);
    return [true, '已排队，等待 ESP32 打印盒领取'];
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
