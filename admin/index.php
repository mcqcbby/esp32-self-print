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

// 打印机在线判断：读打印盒最近一次上报（json），15 秒内有效
$stFile = LOG_DIR . '/esp32_state.json';
$st = [];
if (is_file($stFile)) {
    $tmp = json_decode((string)@file_get_contents($stFile), true);
    if (is_array($tmp)) $st = $tmp;
}
$stTs = isset($st['ts']) ? (int)$st['ts'] : 0;
$stAge = $stTs > 0 ? time() - $stTs : 999999;
$printerOn = $stAge <= 15 && !empty($st['printer_online']);
$printerKnown = $stAge <= 15;
$faultText = ($stAge <= 15 && !empty($st['fault'])) ? printer_fault_text($st['fault']) : '';
$resText = function_exists('fmt_bytes_short') && !empty($st['cpu']) && $stAge <= 15
    ? 'CPU ' . (int)$st['cpu'] . 'MHz · 负载 ' . (int)($st['load'] ?? 0) . '% · 内存 ' . fmt_bytes_short($st['heap'] ?? 0)
      . ' · PSRAM ' . fmt_bytes_short($st['psram'] ?? 0)
      . ' · 信号 ' . (int)($st['rssi'] ?? 0) . 'dBm'
      . ' · 运行 ' . fmt_duration_short($st['up'] ?? 0)
    : '—';
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
    <tr><td>出纸方式</td><td>ESP32 打印盒 · <?= printer_lang() === 'escpr' ? 'ESC/P-R' : 'PCL5/6' ?></td></tr>
    <tr><td>待打印</td><td><?= (int)$pending['c'] ?> 单<?= (int)$pending['c'] > 0 ? ' <a href="orders.php?st=1">去处理</a>' : '' ?></td></tr>
    <?php
    $esp = one('SELECT * FROM printers WHERE type=? ORDER BY id ASC LIMIT 1', ['esp32']);
    $espOnline = $esp && $esp['last_seen'] > time() - 15;
    ?>
    <tr><td>ESP32 打印盒</td><td id="espBoxCell"><?= $esp
        ? ($espOnline ? '<span class="ok">在线</span>' : '<span class="err">离线</span>') . ' · 最后上报 ' . h($esp['last_seen'] ? date('Y-m-d H:i:s', (int)$esp['last_seen']) : '—')
        : '<span class="err">未添加打印盒</span>' ?></td></tr>
    <tr><td>打印机状态</td><td id="printerCell"><?php if (!$printerKnown): ?>
        <span class="err">离线</span>
      <?php elseif ($printerOn): ?>
        <span class="ok">在线</span>
      <?php else: ?>
        <span class="err">离线</span>
      <?php endif; ?>
    </td></tr>
    <tr><td>打印机故障</td><td id="printerFaultCell"><?= $faultText !== '' ? '<span class="err">' . h($faultText) . '</span>' : '<span class="ok">无</span>' ?></td></tr>
    <tr><td>设备资源</td><td id="resCell"><?= h($resText) ?></td></tr>
  </table>
</div>

<?php /* ESP32 日志卡暂时隐藏，代码保留 */ ?>
<div class="card" style="margin-top:16px;display:none">
  <h2>ESP32 日志</h2>
  <p class="tip">
    打印机：
    <?php if (!$printerKnown): ?>
      <span class="err"><?= $espLast === '' ? '还没收到上报' : '上报已过期' ?></span>
    <?php elseif ($printerOn): ?>
      <span class="ok">在线 · 会领任务</span>
    <?php else: ?>
      <span class="err">离线 · 不领任务</span>
    <?php endif; ?>
    · 打印盒每 3 秒上报一次，只保留最近几条
  </p>
  <pre style="margin:10px 0 0;padding:10px;background:#0f172a;color:#cbd5e1;border-radius:8px;font-size:12px;line-height:1.6;max-height:320px;overflow:auto;white-space:pre-wrap"><?= h($espLines ? implode("\n", $espLines) : '还没有上报，等打印盒联网') ?></pre>
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
  </p>
  <pre style="margin:10px 0 0;padding:10px;background:#0f172a;color:#cbd5e1;border-radius:8px;font-size:12px;line-height:1.6;max-height:320px;overflow:auto;white-space:pre-wrap"><?= h($fwLines ? implode("\n", $fwLines) : '设备还没把串口日志推上来') ?></pre>
  <p class="tip" style="margin-top:10px">这里放设备真实运行日志：任务、下载、IPP 结果。日志超过 400KB 会自动只留最后 200KB。</p>
</div>
<script>
(function () {
  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function fmt(ts) {
    if (!ts) return '—';
    var d = new Date(ts * 1000);
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
  }
  function bsize(n) {
    n = +n || 0;
    if (n <= 0) return '—';
    if (n >= 1048576) return (n / 1048576).toFixed(1) + 'MB';
    if (n >= 1024) return Math.round(n / 1024) + 'KB';
    return n + 'B';
  }
  function dur(s) {
    s = +s || 0;
    if (s <= 0) return '—';
    if (s < 60) return s + 's';
    if (s < 3600) return Math.floor(s / 60) + 'm';
    return Math.floor(s / 3600) + 'h' + Math.floor((s % 3600) / 60) + 'm';
  }
  function refresh() {
    fetch('../api/device_status.php?_=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var box = document.getElementById('espBoxCell');
        if (box) {
          box.innerHTML = d.box_online
            ? '<span class="ok">在线</span> · 最后上报 ' + fmt(d.box_last_seen)
            : '<span class="err">离线</span> · 最后上报 ' + fmt(d.box_last_seen);
        }
        var pr = document.getElementById('printerCell');
        if (pr) {
          pr.innerHTML = d.printer_online === true ? '<span class="ok">在线</span>' : '<span class="err">离线</span>';
        }
        var ft = document.getElementById('printerFaultCell');
        if (ft) {
          ft.innerHTML = d.fault_text ? '<span class="err">' + d.fault_text + '</span>' : '<span class="ok">无</span>';
        }
        var res = document.getElementById('resCell');
        if (res) {
          res.innerHTML = (d.cpu && d.res_ok)
            ? 'CPU ' + d.cpu + 'MHz · 负载 ' + (d.load || 0) + '% · 内存 ' + bsize(d.heap) + ' · PSRAM ' + bsize(d.psram) + ' · 信号 ' + d.rssi + 'dBm · 运行 ' + dur(d.uptime)
            : '—';
        }
      })
      .catch(function () {});
  }
  refresh();
  setInterval(refresh, 3000);
})();
</script>
<?php admin_foot(); ?>
