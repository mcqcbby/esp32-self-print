<?php
require_once __DIR__ . '/lib/db.php';
$msg = '';
$done = false;
try {
    $sql = file_get_contents(__DIR__ . '/install.sql');
    $sql = str_replace("\r", '', $sql);
    foreach (explode(";\n", $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || substr($stmt, 0, 2) === '--') continue;
        if (stripos($stmt, 'SET NAMES') === 0) continue;
        db()->exec($stmt . ';');
    }
    if (cfg('admin_pass') === '') {
        cfg_set('admin_pass', password_hash('admin888', PASSWORD_DEFAULT));
    }
    $done = true;
    $msg = '数据库初始化完成。';
} catch (Exception $e) {
    $msg = '初始化失败: ' . $e->getMessage();
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>安装 - 自助打印</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>自助打印系统 安装</h1>
    <p class="<?= $done ? 'ok' : 'err' ?>"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></p>
    <?php if ($done): ?>
    <table class="kv">
      <tr><td>后台地址</td><td><a href="admin/login.php">/admin/login.php</a></td></tr>
      <tr><td>默认账号</td><td>admin</td></tr>
      <tr><td>默认密码</td><td>admin888（登录后请立刻修改）</td></tr>
      <tr><td>打印机配置</td><td>后台 → 打印机设置，填写 Windows 打印机名称</td></tr>
      <tr><td>易支付配置</td><td>后台 → 支付设置，填写接口地址 / PID / KEY</td></tr>
    </table>
    <p class="tip">安装完成后建议删除 install.php。</p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
