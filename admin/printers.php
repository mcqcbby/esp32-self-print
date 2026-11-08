<?php
require_once __DIR__ . '/inc.php';
admin_guard();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = isset($_POST['act']) ? $_POST['act'] : '';
    if ($act === 'add') {
        $name = trim((string)(isset($_POST['name']) ? $_POST['name'] : ''));
        $device = trim((string)(isset($_POST['device']) ? $_POST['device'] : ''));
        if ($name !== '') q('INSERT INTO printers(name,type,device,enabled,online) VALUES(?,?,?,1,0)', [$name, 'esp32', $device]);
    } elseif ($act === 'del') {
        q('DELETE FROM printers WHERE id=?', [(int)$_POST['id']]);
    } elseif ($act === 'toggle') {
        q('UPDATE printers SET enabled=? WHERE id=?', [(int)$_POST['enabled'], (int)$_POST['id']]);
    }
    header('Location: printers.php');
    exit;
}

$printers = all('SELECT * FROM printers ORDER BY id ASC');

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
admin_head('打印机', 'printers');
?>
<div class="card">
  <h1>打印机</h1>
  <table class="kv" style="margin-bottom:18px">
    <tr><td>出纸方式</td><td><?= $mode === 'ipp' ? 'IPP 直打' : 'ESP32 打印盒' ?></td></tr>
    <tr><td>打印机地址</td><td><?= $mode === 'ipp' ? h($ippUrl !== '' ? $ippUrl : '（未填写）') : 'USB 接 ESP32 打印盒（不走 IPP）' ?></td></tr>
    <tr><td>连接状态</td><td><?= $mode === 'ipp' ? ($ippOk ? '<span class="ok">已连接</span>' : '<span class="err">连不上</span>') : '—' ?></td></tr>
  </table>
  <table class="list">
    <tr><th>名称</th><th>备注</th><th>状态</th><th>操作</th></tr>
    <?php foreach ($printers as $p): ?>
    <tr>
      <td><?= h($p['name']) ?></td>
      <td class="small"><?= h($p['device']) ?></td>
      <td class="small"><?= $p['enabled'] ? '<span class="ok">启用</span>' : '停用' ?></td>
      <td class="small">
        <form method="post" style="display:inline">
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <input type="hidden" name="enabled" value="<?= $p['enabled'] ? 0 : 1 ?>">
          <button class="badge s1" style="border:0;cursor:pointer" name="act" value="toggle"><?= $p['enabled'] ? '停用' : '启用' ?></button>
          <button class="badge s4" style="border:0;cursor:pointer" name="act" value="del" onclick="return confirm('删除该打印机？')">删除</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$printers): ?><tr><td colspan="4" class="small">暂无记录</td></tr><?php endif; ?>
  </table>

  <h2 style="margin-top:24px">添加</h2>
  <form method="post">
    <input type="hidden" name="act" value="add">
    <div class="row">
      <div class="field"><label>名称</label><input type="text" name="name" placeholder="前台打印机"></div>
      <div class="field" style="flex:2 1 240px"><label>备注</label><input type="text" name="device" placeholder="EPSON L4168"></div>
    </div>
    <button class="btn" type="submit">添加</button>
  </form>
</div>
<?php
$esp = null;
foreach ($printers as $p) {
    if ($p['type'] === 'esp32') { $esp = $p; break; }
}
$espOnline = $esp && $esp['last_seen'] > time() - 60;
?>
<div class="card" style="margin-top:16px">
  <h2>ESP32 打印盒</h2>
  <table class="kv">
    <tr><td>连接状态</td><td><?= $esp ? ($espOnline ? '<span class="ok">在线</span>' : '<span class="err">离线</span>') : '<span class="err">未添加打印盒</span>' ?></td></tr>
    <tr><td>最后上报</td><td><?= $esp && $esp['last_seen'] ? h(date('Y-m-d H:i:s', (int)$esp['last_seen'])) : '—' ?></td></tr>
    <tr><td>设备令牌</td><td><?= h(cfg('agent_token')) ?></td></tr>
    <tr><td>轮询任务</td><td><?= h(site_url()) ?>/api/esp32.php?action=poll&amp;token=<?= h(cfg('agent_token')) ?></td></tr>
    <tr><td>预处理</td><td><?= h(site_url()) ?>/api/esp32.php?action=prepare&amp;id=订单ID&amp;token=<?= h(cfg('agent_token')) ?></td></tr>
    <tr><td>下载打印数据</td><td><?= h(site_url()) ?>/api/esp32.php?action=file&amp;id=订单ID&amp;token=<?= h(cfg('agent_token')) ?></td></tr>
    <tr><td>上报完成</td><td><?= h(site_url()) ?>/api/esp32.php?action=done&amp;id=订单ID&amp;token=<?= h(cfg('agent_token')) ?></td></tr>
  </table>
</div>
<?php admin_foot(); ?>
