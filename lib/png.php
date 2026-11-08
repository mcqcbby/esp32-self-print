<?php
/**
 * 纯 PHP PNG 解码（8 位、非隔行）
 * 只依赖 PHP 内置 zlib，不需要 GD 扩展。
 * 返回 ['w'=>宽, 'h'=>高, 'ch'=>通道数(1/3/4), 'data'=>扫描线字节串]
 */

function png_to_rgba_data($path)
{
    $b = @file_get_contents($path);
    if ($b === false || strlen($b) < 8) return null;
    if (substr($b, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;

    $pos = 8;
    $idat = '';
    $pal = '';
    $w = $h = $bd = $ct = 0;
    $interlace = 0;
    $len = strlen($b);

    while ($pos + 8 <= $len) {
        $clen = unpack('N', substr($b, $pos, 4));
        $clen = $clen[1];
        $type = substr($b, $pos + 4, 4);
        $data = substr($b, $pos + 8, $clen);
        if ($type === 'IHDR') {
            $p = unpack('Nw/Nh', substr($data, 0, 8));
            $w = $p['w'];
            $h = $p['h'];
            $bd = ord($data[8]);
            $ct = ord($data[9]);
            $interlace = ord($data[12]);
        } elseif ($type === 'PLTE') {
            $pal = $data;
        } elseif ($type === 'IDAT') {
            $idat .= $data;
        } elseif ($type === 'IEND') {
            break;
        }
        $pos += 12 + $clen;
    }

    if ($w < 1 || $h < 1 || $interlace !== 0) return null;
    if (!in_array($ct, [0, 2, 3, 6], true)) return null;
    if ($bd !== 8 && !($bd === 1 && $ct === 0)) return null;

    $raw = @zlib_decode($idat);
    if ($raw === false || $raw === '') return null;

    $ch = ($ct === 2) ? 3 : (($ct === 6) ? 4 : 1);
    $stride = ($bd === 8) ? ($w * $ch) : (int)ceil($w * $bd / 8);
    $bpp = ($bd === 8) ? $ch : 1;
    $out = '';
    $prev = str_repeat("\x00", $stride);
    $p = 0;

    for ($y = 0; $y < $h; $y++) {
        if ($p >= strlen($raw)) break;
        $ft = ord($raw[$p]);
        $p++;
        $line = substr($raw, $p, $stride);
        $p += $stride;
        if (strlen($line) < $stride) $line .= str_repeat("\x00", $stride - strlen($line));

        if ($ft === 1) {
            for ($i = $bpp; $i < $stride; $i++) {
                $line[$i] = chr((ord($line[$i]) + ord($line[$i - $bpp])) & 0xFF);
            }
        } elseif ($ft === 2) {
            for ($i = 0; $i < $stride; $i++) {
                $line[$i] = chr((ord($line[$i]) + ord($prev[$i])) & 0xFF);
            }
        } elseif ($ft === 3) {
            for ($i = 0; $i < $stride; $i++) {
                $a = ($i >= $bpp) ? ord($line[$i - $bpp]) : 0;
                $line[$i] = chr((ord($line[$i]) + (int)(($a + ord($prev[$i])) / 2)) & 0xFF);
            }
        } elseif ($ft === 4) {
            for ($i = 0; $i < $stride; $i++) {
                $a = ($i >= $bpp) ? ord($line[$i - $bpp]) : 0;
                $bb = ord($prev[$i]);
                $c = ($i >= $bpp) ? ord($prev[$i - $bpp]) : 0;
                $pa = abs($bb - $c);
                $pb = abs($a - $c);
                $pc = abs($a + $bb - 2 * $c);
                if ($pa <= $pb && $pa <= $pc) $pr = $a;
                elseif ($pb <= $pc) $pr = $bb;
                else $pr = $c;
                $line[$i] = chr((ord($line[$i]) + $pr) & 0xFF);
            }
        }

        $out .= $line;
        $prev = $line;
    }

    return ['w' => $w, 'h' => $h, 'ch' => $ch, 'ct' => $ct, 'bd' => $bd, 'pal' => $pal, 'data' => $out];
}

/** 取某个像素的灰度 0-255 */
function png_gray_at($img, $x, $y)
{
    $ch = $img['ch'];
    $i = ($y * $img['w'] + $x) * $ch;
    $d = $img['data'];
    if ($i >= strlen($d)) return 255;
    if ($img['ct'] === 0) return ord($d[$i]);
    if ($img['ct'] === 3) {
        $idx = ord($d[$i]) * 3;
        if ($idx + 2 >= strlen($img['pal'])) return 255;
        return (ord($img['pal'][$idx]) * 77 + ord($img['pal'][$idx + 1]) * 150 + ord($img['pal'][$idx + 2]) * 29) >> 8;
    }
    return (ord($d[$i]) * 77 + ord($d[$i + 1]) * 150 + ord($d[$i + 2]) * 29) >> 8;
}

/** 取某个像素的 RGB 数组 */
function png_rgb_at($img, $x, $y)
{
    $ch = $img['ch'];
    $i = ($y * $img['w'] + $x) * $ch;
    $d = $img['data'];
    if ($i >= strlen($d)) return [255, 255, 255];
    if ($img['ct'] === 0) {
        $g = ord($d[$i]);
        return [$g, $g, $g];
    }
    if ($img['ct'] === 3) {
        $idx = ord($d[$i]) * 3;
        if ($idx + 2 >= strlen($img['pal'])) return [255, 255, 255];
        return [ord($img['pal'][$idx]), ord($img['pal'][$idx + 1]), ord($img['pal'][$idx + 2])];
    }
    return [ord($d[$i]), ord($d[$i + 1]), ord($d[$i + 2])];
}
