<?php
/**
 * Word 排版权威转换：docx/doc → PDF
 * 为什么要转 PDF：只有 Office 级排版引擎，页数、页边距、空行和分页位置才和 Word 一致；
 * 浏览器（docx-preview）只能估算分页，页数会和 Word 差 1~3 页。
 *
 * 两条链路自动选择：
 *   1) Windows 服务器：本机 Word/WPS → COM 转 PDF（tools/word2pdf.ps1）
 *   2) Linux 服务器：LibreOffice headless（soffice）转 PDF
 * 两条都没有时返回 code=no_engine，前端才会退回浏览器排版。
 *
 * 打印链路仍然是纯 PHP IPP，此接口只负责“排版”。
 */
require_once dirname(__DIR__) . '/lib/print.php';
set_time_limit(120);

if (empty($_FILES['file'])) json_out(['ok' => false, 'msg' => '没有收到文件']);
$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK) json_out(['ok' => false, 'msg' => '上传失败 (' . $f['error'] . ')']);
if ($f['size'] > 10 * 1024 * 1024) json_out(['ok' => false, 'msg' => '文件过大（限 10MB）']);

$name = (string)$f['name'];
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!in_array($ext, ['docx', 'doc'], true)) json_out(['ok' => false, 'msg' => '只支持 Word 文件']);

// Linux 宝塔默认禁用 exec，直接给明确原因，别让 PHP 抛 Fatal error
if (!function_exists('exec') || !is_callable('exec')) {
    json_out(['ok' => false, 'code' => 'no_exec', 'msg' => '本服务器禁用了 exec，无法调用排版引擎（会改用浏览器排版）']);
}

$root = dirname(__DIR__);
$srcDir = $root . '/uploads/src';
$pdfDir = $root . '/uploads/pdf';
foreach ([$srcDir, $pdfDir] as $d) {
    if (!is_dir($d) && !@mkdir($d, 0777, true)) json_out(['ok' => false, 'msg' => '目录创建失败']);
}

$token = date('YmdHis') . bin2hex(random_bytes(4));
$src = $srcDir . '/' . $token . '.' . $ext;
// soffice 会把 src 的同名 .pdf 写进 --outdir，所以输出文件名天然是 token.pdf
$pdf = $pdfDir . '/' . $token . '.pdf';
if (!move_uploaded_file($f['tmp_name'], $src)) json_out(['ok' => false, 'msg' => '保存源文件失败']);

/**
 * 找排版引擎：返回 [命令前缀, 说明, code]
 * 找不到就返回 code=no_engine，让前端退回浏览器排版，而不是静默失败
 */
function pick_engine($root)
{
    // Windows：Word / WPS 的 COM 转 PDF
    if (DIRECTORY_SEPARATOR === '\\') {
        $script = $root . '/tools/word2pdf.ps1';
        if (!is_file($script)) return [null, '', 'no_engine'];
        // PHP 的 PATH 里通常没有 powershell.exe，必须用绝对路径，否则 exec 报“找不到命令”
        $ps = 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        if (!is_file($ps)) $ps = 'powershell.exe';
        return [[
            '"' . $ps . '" -NoProfile -NonInteractive -ExecutionPolicy Bypass -File '
            . escapeshellarg($script)
            . ' -In %IN% -Out %OUT% 2>&1',
            'word-com',
        ], '', ''];
    }

    // Linux：LibreOffice headless（装了中文汉字字体才能和 Word 排得一样）
    // 注意：宝塔常开 open_basedir（只允许站点目录和 /tmp），is_file('/usr/bin/soffice') 会被拦，
    // 所以优先用 `command -v` 让 shell 去找，探测不到再回退到固定路径
    $found = '';
    $o = [];
    $r = 0;
    @exec('command -v soffice 2>/dev/null', $o, $r);
    if (!empty($o[0])) $found = trim($o[0]);
    if ($found === '') {
        $cands = ['/usr/bin/soffice', '/usr/local/bin/soffice', '/opt/libreoffice/program/soffice'];
        $glob = @glob('/opt/libreoffice*/program/soffice');
        if (is_array($glob)) $cands = array_merge($cands, $glob);
        foreach ($cands as $c) {
            if (@is_file($c)) { $found = $c; break; }
        }
    }
    if ($found !== '') {
        // HOME 必须可写，否则 soffice 会因为配置目录不可写直接退出
        return [[
            'HOME=' . escapeshellarg(sys_get_temp_dir()) . ' timeout 90 ' . escapeshellarg($found)
            . ' --headless --norestore --nologo --nolockcheck'
            . ' --convert-to pdf:writer_pdf_Export --outdir %OUTDIR% %IN% 2>&1',
            'libreoffice',
        ], '', ''];
    }
    return [null, '', 'no_engine'];
}

list($eng, , $engCode) = pick_engine($root);
if ($eng === null) {
    @unlink($src);
    json_out(['ok' => false, 'code' => 'no_engine', 'msg' => '本服务器没有排版引擎（Windows 需装 Word/WPS，Linux 需装 LibreOffice）']);
}

$tpl = $eng[0];
$kind = $eng[1];
$cmd = str_replace(['%IN%', '%OUT%', '%OUTDIR%'], [escapeshellarg($src), escapeshellarg($pdf), escapeshellarg($pdfDir)], $tpl);

$out = [];
$code = 0;
@exec($cmd, $out, $code);

if (!is_file($pdf) || filesize($pdf) < 1024) {
    $detail = trim(implode(' ', array_slice($out, -3)));
    @file_put_contents($root . '/logs/conv.log',
        date('c') . ' engine=' . $kind . ' code=' . $code . ' cmd=' . substr($cmd, 0, 200)
        . ' out=' . substr(implode('|', $out), 0, 400) . "\n", FILE_APPEND);
    @unlink($src);
    json_out(['ok' => false, 'code' => 'convert_failed',
        'msg' => '排版转换失败（' . $kind . '）' . ($detail !== '' ? '：' . substr($detail, 0, 100) : '')]);
}

@unlink($src);
json_out(['ok' => true, 'pdf' => 'uploads/pdf/' . $token . '.pdf']);
