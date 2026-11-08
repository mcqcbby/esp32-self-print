<?php
/**
 * 纯 PHP PDF 生成：把每页图片嵌入 A4 PDF。
 * - JPEG 原样嵌入（DCTDecode，不重新编码，彩色无损）
 * - PNG 用 lib/png.php 解码后以 FlateDecode 嵌入（1 位单色、8 位灰度/RGB）
 * 不依赖 GD、不调用任何外部程序。
 */
require_once dirname(__DIR__) . '/lib/func.php';
require_once dirname(__DIR__) . '/lib/png.php';

/** 读取一页图片，返回 PDF 图像对象所需的信息 */
function pdf_page_asset($path)
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if ($ext === 'jpg' || $ext === 'jpeg') {
        $info = @getimagesize($path);
        if (!$info || $info[2] !== IMAGETYPE_JPEG) return null;
        $data = @file_get_contents($path);
        if ($data === false) return null;
        $ch = (isset($info['channels']) && (int)$info['channels'] === 1) ? 1 : 3;
        return [
            'w' => (int)$info[0],
            'h' => (int)$info[1],
            'dict' => ($ch === 1 ? '/ColorSpace /DeviceGray' : '/ColorSpace /DeviceRGB')
                . ' /BitsPerComponent 8 /Filter /DCTDecode',
            'stream' => $data,
        ];
    }

    if ($ext === 'png') {
        $img = png_to_rgba_data($path);
        if (!$img) return null;
        $ct = (int)$img['ct'];
        $bd = (int)$img['bd'];
        $data = $img['data'];

        if ($ct === 3) {                       // 调色板：展开成 RGB
            $pal = $img['pal'];
            $w = $img['w'];
            $h = $img['h'];
            $out = '';
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $i = $y * $w + $x;
                    if ($i >= strlen($data)) { $out .= "\xFF\xFF\xFF"; continue; }
                    $idx = ord($data[$i]) * 3;
                    $out .= ($idx + 2 < strlen($pal)) ? substr($pal, $idx, 3) : "\xFF\xFF\xFF";
                }
            }
            $ct = 2;
            $data = $out;
        } elseif ($ct === 6) {                 // RGBA：去掉 alpha
            $w = $img['w'];
            $h = $img['h'];
            $out = '';
            $stride = $w * 4;
            for ($y = 0; $y < $h; $y++) {
                $row = substr($data, $y * $stride, $stride);
                for ($x = 0; $x < $w; $x++) {
                    $out .= substr($row, $x * 4, 3);
                }
            }
            $ct = 2;
            $data = $out;
        }

        if ($ct === 0 && $bd === 1) {
            $cs = '/DeviceGray';
            $bits = 1;
        } elseif ($ct === 0) {
            $cs = '/DeviceGray';
            $bits = 8;
        } else {
            $cs = '/DeviceRGB';
            $bits = 8;
        }

        $packed = @gzcompress($data, 6);
        if ($packed === false) return null;

        return [
            'w' => (int)$img['w'],
            'h' => (int)$img['h'],
            'dict' => "/ColorSpace $cs /BitsPerComponent $bits /Filter /FlateDecode",
            'stream' => $packed,
        ];
    }

    return null;
}

/**
 * 把多页图片拼成一份 A4 PDF
 * @return array [bool $ok, string $pathOrMsg]
 */
function pdf_a4_from_images($files, $outPath)
{
    $assets = [];
    foreach ($files as $f) {
        $a = pdf_page_asset($f);
        if ($a === null) return [false, '页面图片无法解码：' . basename($f)];
        $assets[] = $a;
    }
    if (!$assets) return [false, '没有可用的页面图片'];

    $pw = 595.28;   // A4 宽（pt）
    $ph = 841.89;   // A4 高（pt）
    $n = count($assets);

    $kids = [];
    for ($i = 0; $i < $n; $i++) $kids[] = (3 + $i * 3) . ' 0 R';

    $buf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];

    $offsets[1] = strlen($buf);
    $buf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";

    $offsets[2] = strlen($buf);
    $buf .= "2 0 obj\n<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count $n >>\nendobj\n";

    for ($i = 0; $i < $n; $i++) {
        $a = $assets[$i];
        $scale = min($pw / $a['w'], $ph / $a['h']);
        $dw = $a['w'] * $scale;
        $dh = $a['h'] * $scale;
        $x = ($pw - $dw) / 2;
        $y = ($ph - $dh) / 2;

        $pnum = 3 + $i * 3;
        $cnum = $pnum + 1;
        $inum = $pnum + 2;

        $offsets[$pnum] = strlen($buf);
        $buf .= "$pnum 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 "
            . sprintf('%.2F %.2F', $pw, $ph) . "] /Resources << /XObject << /Im0 $inum 0 R >> >> /Contents $cnum 0 R >>\nendobj\n";

        $content = "q\n" . sprintf('%.4F 0 0 %.4F %.4F %.4F cm', $dw, $dh, $x, $y) . "\n/Im0 Do\nQ\n";
        $offsets[$cnum] = strlen($buf);
        $buf .= "$cnum 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream\nendobj\n";

        $offsets[$inum] = strlen($buf);
        $buf .= "$inum 0 obj\n<< /Type /XObject /Subtype /Image /Width " . $a['w']
            . " /Height " . $a['h'] . " " . $a['dict']
            . " /Length " . strlen($a['stream']) . " >>\nstream\n" . $a['stream'] . "\nendstream\nendobj\n";
    }

    $size = 3 + $n * 3;
    $xref = strlen($buf);
    $buf .= "xref\n0 $size\n";
    $buf .= "0000000000 65535 f \n";
    for ($i = 1; $i < $size; $i++) {
        $buf .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
    }
    $buf .= "trailer\n<< /Size $size /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";

    if (@file_put_contents($outPath, $buf) === false) return [false, '无法写入 PDF 文件'];
    return [true, $outPath];
}
