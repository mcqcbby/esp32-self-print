<?php
// 排查用：上传到网站根目录，浏览器打开 /_diag.php，把整页截图发我
header('Content-Type: text/plain; charset=utf-8');

$root = __DIR__;
echo "== 文件版本 ==\n";
$marks = [
    'assets/render.js'  => ['shotHealth', 'clampImages', 'inkRatio'],
    'assets/app.js'     => ['没有排版引擎', 'buildThumbs'],
    'api/convert.php'   => ['pick_engine', 'no_engine'],
    'lib/func.php'      => ['ob_end_clean'],
    'tools/linux_setup.sh' => ['soffice'],
];
foreach ($marks as $f => $need) {
    $p = $root . '/' . $f;
    if (!is_file($p)) { echo "$f : 不存在\n"; continue; }
    $s = (string)@file_get_contents($p);
    $hit = [];
    foreach ($need as $k) { $hit[] = $k . '=' . (strpos($s, $k) !== false ? 1 : 0); }
    echo "$f : " . round(filesize($p) / 1024, 1) . "KB  " . implode(' ', $hit) . "\n";
}

echo "\n== 环境 ==\n";
echo 'php=' . PHP_VERSION . ' sapi=' . PHP_SAPI . "\n";
echo 'exec=' . (function_exists('exec') ? 1 : 0) . ' disable=' . (ini_get('disable_functions') !== '' ? ini_get('disable_functions') : '(空)') . "\n";

echo "\n== 排版引擎 ==\n";
if (function_exists('exec')) {
    $o = []; $r = 0;
    @exec('command -v soffice 2>/dev/null', $o, $r);
    echo 'command -v soffice => ' . (implode(' ', $o) ?: '(未安装)') . "\n";
    $c = $o ? trim($o[0]) : '';
    if ($c !== '') {
        $o1 = []; @exec('HOME=/tmp ' . escapeshellarg($c) . ' --version 2>&1 | head -n1', $o1, $r);
        echo 'soffice --version => ' . (implode(' ', $o1) ?: '(无输出)') . "\n";
    }
    $o2 = []; @exec('fc-match SimSun 2>&1', $o2, $r);
    echo 'fc-match SimSun => ' . (implode(' ', $o2) ?: '(无输出)') . "\n";
    $o3 = []; @exec('fc-match SimHei 2>&1', $o3, $r);
    echo 'fc-match SimHei => ' . (implode(' ', $o3) ?: '(无输出)') . "\n";
} else {
    echo "exec 被禁用，无法探测 soffice / 字体\n";
}
echo 'open_basedir=' . (ini_get('open_basedir') !== '' ? ini_get('open_basedir') : '(空)') . "\n";

echo "\n== 目录权限 ==\n";
foreach (['uploads/src', 'uploads/pdf', 'uploads/pages', 'logs'] as $d) {
    $p = $root . '/' . $d;
    echo $d . ' : 存在=' . (int)is_dir($p) . ' 可写=' . (int)is_writable($p) . "\n";
}

echo "\n== 最近转换日志 ==\n";
$log = $root . '/logs/conv.log';
if (is_file($log)) {
    $tail = array_slice(file($log, FILE_IGNORE_NEW_LINES), -3);
    echo implode("\n", $tail) . "\n";
} else {
    echo "(没有 logs/conv.log，说明新版 convert.php 还没跑过或没上传)\n";
}
