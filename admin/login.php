<?php
session_start();
require_once dirname(__DIR__) . '/lib/func.php';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = isset($_POST['username']) ? trim($_POST['username']) : '';
    $p = isset($_POST['password']) ? (string)$_POST['password'] : '';
    $hash = (string)cfg('admin_pass');
    if ($hash === '') $hash = password_hash('admin888', PASSWORD_DEFAULT);
    if ($u === 'admin' && password_verify($p, $hash)) {
        $_SESSION['admin'] = 1;
        header('Location: index.php');
        exit;
    }
    $err = '账号或密码错误';
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>后台登录</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="wrap narrow">
  <div class="topbar"><div class="brand"><span class="logo"></span><?= h(cfg('site_name', '自助打印')) ?> 后台</div></div>
  <div class="card">
    <h1>管理员登录</h1>
    <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
    <form method="post">
      <div class="field"><label>账号</label><input type="text" name="username" value="admin"></div>
      <div class="field" style="margin-top:12px"><label>密码</label><input type="password" name="password"></div>
      <button class="btn" type="submit">登录</button>
    </form>
  </div>
</div>
</body>
</html>
