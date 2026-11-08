<?php
/**
 * ESC/P-R（Epson 私有打印机语言）指令生成 —— 纯 PHP，无外部程序。
 *
 * 指令结构参考爱普生官方 Linux 驱动源码（epson-inkjet-printer-escpr 1.8.8，
 * lib/epson-escpr-api.c 中的 ESC/P-R 命令表与 Make*Cmd 函数）：
 *   - 命令头：ESC(0x1B) + 类别字符 + 4 字节小端参数长度 + 4 字节命令名 + 参数
 *   - 图像数据：ESC 'd' + 4 字节小端(2+本块长度) + "jsnd" + 2 字节大端(本块长度) + JPEG 字节
 *   - 一页流程：setq(打印质量) → APF → seti(图像模式) → sets(纸张) → setj(作业) → sttp(开始页)
 *              → setn(页号) → jsnd×N → endp(结束页) → 下一页 sttp/setn… → endj
 *
 * 说明：爱普生未公开 ESC/P-R 规格，本实现按官方驱动字节布局复刻，属于实验特性，
 * 需在真机上校准（尤其 setq 的介质类型/质量字段）。默认走 JPEG 通道：
 * 由打印机自己解码 JPEG，比在 PHP 里拼彩色点阵更稳、也更快。
 */

require_once __DIR__ . '/func.php';

define('ESCPR_ESC', "\x1B");
define('ESCPR_CHUNK', 4000);          // 单块 JPEG 数据上限，越小越稳
define('ESCPR_A4_W', 2976);           // A4 宽，1/360 英寸
define('ESCPR_A4_H', 4209);           // A4 高，1/360 英寸
define('ESCPR_PRINT_W', 2892);        // 可打印区宽
define('ESCPR_PRINT_H', 4125);        // 可打印区高

/** ESC/P-R 子命令：ESC + 类别 + 4B 小端长度 + 命令名 + 参数 */
function escpr_cmd($class, $name, $params = '')
{
    return ESCPR_ESC . $class . pack('V', strlen($params)) . $name . $params;
}

/** 进入 ESC/P-R（JPEG 通道）：官方字节 1B 28 52 07 00 00 'ESCPRJ' */
function escpr_mode_jpeg()
{
    return ESCPR_ESC . "(R\x07\x00\x00ESCPRJ";
}

/** setq：介质类型、打印质量、彩色/黑白、亮度/对比度/饱和度、色彩通道(2=JPEG)、调色板长度 */
function escpr_setq($mediaType, $quality, $colorMode)
{
    $p = chr($mediaType)      // 0 = 普通纸
        . chr($quality)       // 0 草稿 / 1 标准 / 2 高
        . chr($colorMode)     // 0 彩色 / 1 黑白
        . chr(0)              // 亮度
        . chr(0)              // 对比度
        . chr(0)              // 饱和度
        . chr(2)              // 色彩通道：2 = JPEG
        . pack('n', 0);       // 调色板长度
    return escpr_cmd('q', 'setq', $p);
}

/** seta：APF（自动进纸校正）、锐度、红眼 */
function escpr_seta($colorMode)
{
    return escpr_cmd('a', 'seta', chr($colorMode) . chr(0) . chr(0) . chr(0));
}

/** seti：图像处理模式（第 10 字节为装订位置：0 无 / 1 短边 / 2 长边） */
function escpr_seti($binding = 0)
{
    return escpr_cmd('q', 'seti', str_repeat("\x00", 9) . chr($binding));
}

/** sets：纸张尺寸 + 版式（0 固定边距 / 1 无边距） */
function escpr_sets($mediaSize = 0, $layout = 0)
{
    return escpr_cmd('j', 'sets', chr($mediaSize) . chr($layout) . chr(0) . chr(0) . chr(0));
}

/** setj：纸张宽高、页边距、可打印区、输入分辨率、打印方向（单位 1/360 英寸） */
function escpr_setj($w, $h, $top, $left, $areaW, $areaH, $res = 0, $dir = 0)
{
    $p = pack('N', $w) . pack('N', $h) . pack('n', $top) . pack('n', $left)
        . pack('N', $areaW) . pack('N', $areaH) . chr($res) . chr($dir);
    return escpr_cmd('j', 'setj', $p);
}

/** sttp：开始一页 */
function escpr_start_page()
{
    return escpr_cmd('p', 'sttp', '');
}

/** setn：页号（1 字节） */
function escpr_page_num($n)
{
    return escpr_cmd('p', 'setn', chr($n & 0xFF));
}

/** endp：结束一页，参数 1 表示后面还有页 */
function escpr_end_page($more)
{
    return escpr_cmd('p', 'endp', chr($more ? 1 : 0));
}

/** endj：结束作业 */
function escpr_end_job()
{
    return escpr_cmd('j', 'endj', '');
}

/** jsnd：发送一块 JPEG 数据 */
function escpr_jpeg_chunk($bytes)
{
    $len = strlen($bytes);
    return ESCPR_ESC . 'd' . pack('V', 2 + $len) . 'jsnd' . pack('n', $len) . $bytes;
}

/** 计算 1/360 英寸单位下的纸张尺寸（整数） */
function escpr_page_units($paper)
{
    $map = [
        'A4'     => [ESCPR_A4_W, ESCPR_A4_H, ESCPR_PRINT_W, ESCPR_PRINT_H, 0],
        'A5'     => [2100, 2976, 2020, 2892, 5],
        'Letter' => [3060, 3960, 2976, 3876, 1],
    ];
    $k = isset($map[$paper]) ? $map[$paper] : $map['A4'];
    return $k;
}

/**
 * 把多页 JPEG 拼成一份 ESC/P-R 打印流
 * @return array [bool $ok, string $pathOrMsg]
 */
function escpr_a4_from_images($files, $outPath, $color = false, $paper = 'A4', $copies = 1)
{
    if (!$files) return [false, '没有页面图片'];

    foreach ($files as $f) {
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if ($ext !== 'jpg' && $ext !== 'jpeg') {
            return [false, 'ESC/P-R 通道需要 JPEG 页面（当前为 ' . basename($f) . '），请重新上传'];
        }
        $data = @file_get_contents($f);
        if ($data === false || substr($data, 0, 2) !== "\xFF\xD8") {
            return [false, '页面不是有效 JPEG：' . basename($f)];
        }
    }

    list($pw, $ph, $aw, $ah, $mediaSize) = escpr_page_units($paper);

    $out = '';
    $out .= ESCPR_ESC . '@';                                   // 初始化
    $out .= escpr_mode_jpeg();                                 // 进入 ESC/P-R（JPEG）
    $out .= escpr_setq(0, 1, $color ? 0 : 1);                  // 普通纸 / 标准质量 / 彩色或黑白
    $out .= escpr_seta($color ? 0 : 1);
    if ($copies > 1) $out .= escpr_cmd('c', 'setc', chr($copies & 0xFF));
    $out .= escpr_seti(0);
    $out .= escpr_sets($mediaSize, 0);
    $out .= escpr_setj($pw, $ph, 0, 0, $aw, $ah, 0, 0);
    $out .= escpr_start_page();
    $out .= escpr_page_num(1);

    $n = count($files);
    foreach ($files as $i => $f) {
        $data = @file_get_contents($f);
        foreach (str_split($data, ESCPR_CHUNK) as $chunk) {
            $out .= escpr_jpeg_chunk($chunk);
        }
        $out .= escpr_end_page($i < $n - 1);
        if ($i < $n - 1) {
            $out .= escpr_start_page();
            $out .= escpr_page_num($i + 2);
        }
    }

    $out .= escpr_end_job();
    $out .= ESCPR_ESC . '@';                                   // 收尾复位

    if (@file_put_contents($outPath, $out) === false) return [false, '无法写入打印数据文件'];
    return [true, $outPath];
}
