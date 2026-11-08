<?php
require_once __DIR__ . '/inc.php';
admin_head('概览', 'index');

$today = strtotime('today');
$t = one('SELECT COUNT(*) c, SUM(CASE WHEN status>=1 AND status<5 THEN amount ELSE 0 END) m FROM orders WHERE create_time>=?', [$today]);
$paid = one('SELECT COUNT(*) c, SUM(amount) m FROM orders WHERE pay_time>=?', [$today]);
$done = one('SELECT COUNT(*) c FROM orders WHERE status=3 AND print_time>=?', [$today]);
$queue = one('SELECT COUNT(*) c FROM orders WHERE status IN (1,2)');
$total = one('SELECT COUNT(*) c, SUM(amount) m FROM orders WHERE status IN (1,2,3)');
$pending = one('SELECT COUNT(*) c FROM orders WHERE status=1');

$mode = print_mode();
$ippUrl = ipp_target();
$ippOk = false;
if ($mode === 'ipp' && $ippUrl !== '') {
    $u = parse_url($ippUrl);
    $port = isset($u['port']) ? (int)$u['port'] : 631;
    for ($i = 0; $i < 3; $i++) {
        $fp = @fsockopen($u['host'], $port, $en, $es, 2);
        if ($fp) { $ippOk = true; @fclose($fp); break; }
        usleep(200000);
    }
}
?>
<div class="card">
  <h1>今日概览</h1>
  <div class="row">
    <div class="field"><label>今日订单</label><div class="amount" style="font-size:22px"><?= (int)$t['c'] ?></div></div>
    <div class="field"><label>今日收款</label><div class="amount" style="font-size:22px">¥<?= number_format((float)$paid['m'], 2) ?></div></div>
    <div class="field"><label>今日完成</label><div class="amount" style="font-size:22px"><?= (int)$done['c'] ?></div></div>
    <div class="field"><label>当前排队</label><div class="amount" style="font-size:22px"><?= (int)$queue['c'] ?></div></div>
  </div>
  <p class="tip" style="margin-top:16px">累计完成订单 <?= (int)$total['c'] ?> 笔，累计金额 ¥<?= number_format((float)$total['m'], 2) ?>。</p>
</div>

<div class="card" style="margin-top:16px">
  <h2>运行状态</h2>
  <table class="kv">
    <tr><td>PHP 版本</td><td><?= h(PHP_VERSION) ?></td></tr>
    <tr><td>页面图片目录</td><td><?= (is_dir(PAGES_DIR) && is_writable(PAGES_DIR)) ? '<span class="ok">可写</span>' : '<span class="err">不可写</span>' ?></td></tr>
    <tr><td>出纸方式</td><td><?= $mode === 'ipp'
        ? 'IPP 直打 · ' . ($ippOk ? '<span class="ok">已连接</span>' : '<span class="err">连不上</span>')
        : 'ESP32 打印盒 · ' . (printer_lang() === 'escpr' ? 'ESC/P-R' : 'PCL5/6') ?></td></tr>
    <tr><td>打印机地址</td><td><?= $mode === 'ipp'
        ? h($ippUrl !== '' ? $ippUrl : '（未填写）')
        : 'USB 接 ESP32 打印盒（不走 IPP）' ?></td></tr>
    <tr><td>待打印</td><td><?= (int)$pending['c'] ?> 单<?= (int)$pending['c'] > 0 ? '（<a href="orders.php?st=1">去处理</a>）' : '' ?></td></tr>
    <?php
    $esp = one('SELECT * FROM printers WHERE type=? ORDER BY id ASC LIMIT 1', ['esp32']);
    $espOnline = $esp && $esp['last_seen'] > time() - 60;
    ?>
    <tr><td>ESP32 打印盒</td><td><?= $esp
        ? ($espOnline ? '<span class="ok">在线</span>' : '<span class="err">离线</span>') . ' · 最后上报 ' . h($esp['last_seen'] ? date('Y-m-d H:i:s', (int)$esp['last_seen']) : '—')
        : '<span class="err">未添加打印盒</span>' ?></td></tr>
  </table>
</div>

<?php
$logFile = LOG_DIR . '/esp32_state.log';
$espLines = [];
if (is_file($logFile)) {
    $espLines = array_slice(@file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -40);
}
$espLast = $espLines ? end($espLines) : '';
$espAge = 999999;
if ($espLast !== '' && preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)/', $espLast, $mm)) {
    $espAge = time() - strtotime($mm[1]);
}
$printerOn = $espLast !== '' && strpos($espLast, 'printer=1') !== false;
$printerKnown = $espLast !== '' && strpos($espLast, 'printer=') !== false && $espAge <= 30;
?>
<div class="card" style="margin-top:16px">
  <h2>ESP32 日志</h2>
  <p class="tip">
    打印机：
    <?php if (!$printerKnown): ?>
      <span class="err"><?= $espLast === '' ? '还没收到上报' : '上报已过期（打印盒没在跑）' ?></span>
    <?php elseif ($printerOn): ?>
      <span class="ok">在线 · 会领任务</span>
    <?php else: ?>
      <span class="err">离线 · 不领任务</span>
    <?php endif; ?>
    · 打印盒每 3 秒上报一次，只保留最近几条
  </p>
  <pre style="margin:10px 0 0;padding:10px;background:#0f172a;color:#cbd5e1;border-radius:8px;font-size:12px;line-height:1.6;max-height:320px;overflow:auto;white-space:pre-wrap"><?= h($espLines ? implode("\n", $espLines) : '（还没有上报，等打印盒联网）') ?></pre>
  <p class="tip" style="margin-top:10px">刷新本页看最新；打印盒离线时这里会一直重复 <code>printer=0</code>。</p>
</div>

<?php
$fwLog = LOG_DIR . '/esp32.log';
$fwLines = [];
if (is_file($fwLog)) {
    $fwLines = array_slice(@file($fwLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -60);
}
$stFile = LOG_DIR . '/esp32_state.json';
$st = [];
if (is_file($stFile)) {
    $tmp = json_decode((string)@file_get_contents($stFile), true);
    if (is_array($tmp)) $st = $tmp;
}
?>
<div class="card" style="margin-top:16px">
  <h2>打印盒运行日志</h2>
  <p class="tip">
    设备 IP <?= h(isset($st['ip']) && $st['ip'] !== '' ? $st['ip'] : '—') ?>
    · 固件 <?= h(isset($st['fw']) && $st['fw'] !== '' ? $st['fw'] : '—') ?>
    · 状态 <?= h(isset($st['state']) && $st['state'] !== '' ? $st['state'] : '—') ?>
    · 最近上报 <?= h(isset($st['ts']) ? date('H:i:s', (int)$st['ts']) : '—') ?>
    （打印机离线时不领任务）
  </p>
  <pre style="margin:10px 0 0;padding:10px;background:#0f172a;color:#cbd5e1;border-radius:8px;font-size:12px;line-height:1.6;max-height:320px;overflow:auto;white-space:pre-wrap"><?= h($fwLines ? implode("\n", $fwLines) : '（设备还没把串口日志推上来）') ?></pre>
  <p class="tip" style="margin-top:10px">这里放设备真实运行日志：任务、下载、IPP 结果。日志超过 400KB 会自动只留最后 200KB。</p>
</div>
<?php admin_foot(); ?>
