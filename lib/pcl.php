<?php
/**
 * 纯 PHP 打印机指令生成器（PCL5 / PCL5c）
 * 不依赖 Ghostscript、SumatraPDF、Office、GD 扩展或任何外部程序。
 * 只用 PHP 内置 zlib 解码 PNG，再打包成 PJL + PCL5 点阵流。
 *
 * 输入：uploads/pages/{订单号}/p001.png ...
 * 输出：uploads/pcl/{订单号}.pcl
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/func.php';
require_once __DIR__ . '/png.php';

/** 列出订单的页面图片（按文件名排序；PCL 走 png，IPP 走 jpg） */
function page_images($pagesDir)
{
    if (!is_dir($pagesDir)) return [];
    $files = [];
    foreach (scandir($pagesDir) as $f) {
        if ($f === '.' || $f === '..') continue;
        if (preg_match('/\.(png|jpg|jpeg)$/i', $f)) $files[] = $pagesDir . '/' . $f;
    }
    sort($files, SORT_STRING);
    return $files;
}

/** 预计算最近邻映射表 */
function nearest_map($src, $dst)
{
    if ($src === $dst) return null;
    $m = [];
    for ($i = 0; $i < $dst; $i++) $m[$i] = (int)($i * $src / $dst);
    return $m;
}

/** 已是 1 位位图时直接搬运（浏览器端已完成阈値化，这里只做反相） */
function page_mono_fast($img, $tw, $th)
{
    $srcStride = (int)ceil($img['w'] / 8);
    $dstStride = (int)ceil($tw / 8);
    $data = $img['data'];
    $mask = str_repeat("\xff", $dstStride);
    $out = '';
    for ($y = 0; $y < $th; $y++) {
        $sy = ($y < $img['h']) ? $y : $img['h'] - 1;
        $row = substr($data, $sy * $srcStride, $dstStride);
        if (strlen($row) < $dstStride) $row .= str_repeat("\x00", $dstStride - strlen($row));
        $out .= "\x1b*b" . $dstStride . "W" . ($row ^ $mask);
    }
    return $out;
}

/** 单色位图：1 位/像素，1=打点(黑)，MSB 在前 */
function page_mono_bytes($img, $tw, $th)
{
    $d = $img['data'];
    $ch = $img['ch'];
    $sw = $img['w'];
    $ct = $img['ct'];
    $xs = nearest_map($sw, $tw);
    $ys = nearest_map($img['h'], $th);
    $rowBytes = (int)ceil($tw / 8);
    $out = '';

    for ($y = 0; $y < $th; $y++) {
        $sy = $ys ? $ys[$y] : $y;
        $rowBase = ($sy * $sw) * $ch;
        $bytes = [];
        $byte = 0;
        $bit = 0;
        for ($x = 0; $x < $tw; $x++) {
            $sx = $xs ? $xs[$x] : $x;
            $i = $rowBase + $sx * $ch;
            if ($ct === 0) {
                $g = ord($d[$i]);
            } elseif ($ct === 3) {
                $g = 255;
            } else {
                $g = (ord($d[$i]) * 77 + ord($d[$i + 1]) * 150 + ord($d[$i + 2]) * 29) >> 8;
            }
            if ($g < 168) $byte |= (0x80 >> $bit);
            $bit++;
            if ($bit === 8) {
                $bytes[] = chr($byte);
                $byte = 0;
                $bit = 0;
            }
        }
        if ($bit > 0) $bytes[] = chr($byte);
        $out .= "\x1b*b" . $rowBytes . "W" . implode('', $bytes);
    }
    return $out;
}

/** 彩色 RGB 行数据（PCL5c 直接 RGB） */
function page_color_rows($img, $tw, $th)
{
    $d = $img['data'];
    $ch = $img['ch'];
    $sw = $img['w'];
    $ct = $img['ct'];
    $xs = nearest_map($sw, $tw);
    $ys = nearest_map($img['h'], $th);
    $out = '';
    $rowBytes = $tw * 3;

    for ($y = 0; $y < $th; $y++) {
        $sy = $ys ? $ys[$y] : $y;
        $rowBase = ($sy * $sw) * $ch;
        $row = '';
        for ($x = 0; $x < $tw; $x++) {
            $sx = $xs ? $xs[$x] : $x;
            $i = $rowBase + $sx * $ch;
            if ($ct === 0) {
                $v = ord($d[$i]);
                $row .= chr($v) . chr($v) . chr($v);
            } else {
                $row .= chr(ord($d[$i])) . chr(ord($d[$i + 1])) . chr(ord($d[$i + 2]));
            }
        }
        $out .= "\x1b*b" . $rowBytes . "W" . $row;
    }
    return $out;
}

/**
 * 生成订单打印文件
 * @return array [bool $ok, string $pathOrMsg]
 */
function build_pcl($order)
{
    $no = $order['order_no'];
    $pagesDir = pages_dir_of($order);
    $imgs = page_images($pagesDir);
    if (!$imgs) return [false, '没有找到页面图片，请重新上传文件'];

    $path = PCL_DIR . '/' . $no . '.pcl';
    $fp = fopen($path, 'wb');
    if (!$fp) return [false, '无法写入打印文件'];

    $name = preg_replace('/[^\x20-\x7e]/', '', $no);
    fwrite($fp, "\x1b%-12345X@PJL JOB NAME=\"$name\"\r\n");
    fwrite($fp, "@PJL SET DUPLEX=" . ((int)$order['duplex'] ? 'ON' : 'OFF') . "\r\n");
    fwrite($fp, "@PJL SET COPIES=" . max(1, (int)$order['copies']) . "\r\n");
    fwrite($fp, "@PJL ENTER LANGUAGE=PCL\r\n");

    $color = (int)$order['color'] && (int)cfg('pcl_color', '0');
    $built = 0;

    foreach ($imgs as $imgPath) {
        if (!preg_match('/\.png$/i', $imgPath)) continue;   // PCL 只吃浏览器生成的 1 位 PNG
        $img = png_to_rgba_data($imgPath);
        if (!$img) continue;
        if ($color) {
            $cw = (int)(PCL_W * 0.75);   // 彩色降采样到 150dpi，控制数据量
            $chh = (int)(PCL_H * 0.75);
            fwrite($fp, "\x1bE\x1b&l26A\x1b&l0O\x1b&l0E\x1b*t150R\x1b*r" . $cw . "S\x1b*r" . $chh . "T\x1b*r1A");
            fwrite($fp, "\x1b*v6W" . "\x00\x02\x08\x08\x08\x08");  // 直接 RGB，8 位/分量
            fwrite($fp, page_color_rows($img, $cw, $chh));
        } else {
            fwrite($fp, "\x1bE\x1b&l26A\x1b&l0O\x1b&l0E\x1b*t" . PCL_DPI . "R\x1b*r" . PCL_W . "S\x1b*r" . PCL_H . "T\x1b*r0A");
            if ($img['bd'] === 1 && $img['w'] === PCL_W && $img['h'] === PCL_H) {
                fwrite($fp, page_mono_fast($img, PCL_W, PCL_H));
            } else {
                fwrite($fp, page_mono_bytes($img, PCL_W, PCL_H));
            }
        }
        fwrite($fp, "\x1b*rC\x0c");
        $built++;
    }

    fwrite($fp, "\x1b%-12345X@PJL EOJ\r\n");
    fclose($fp);

    if ($built < 1 || filesize($path) < 1024) {
        @unlink($path);
        return [false, '打印文件生成失败（页面图片无法解码）'];
    }
    return [true, $path];
}

/** 订单页面图片目录（相对或绝对都兼容） */
function pages_dir_of($order)
{
    $d = isset($order['pages_dir']) ? trim((string)$order['pages_dir']) : '';
    if ($d === '') $d = 'uploads/pages/' . $order['order_no'];
    if (preg_match('/^[A-Za-z]:[\\\\\/]/', $d)) return $d;
    return APP_ROOT . '/' . ltrim(str_replace('\\', '/', $d), '/');
}
