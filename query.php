<?php
require_once __DIR__ . '/lib/func.php';
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$rows = [];
if ($q !== '') {
    if (preg_match('/^\d{14,20}$/', $q)) {
        $o = one('SELECT order_no FROM orders WHERE order_no=?', [$q]);
        if ($o) { header('Location: order.php?no=' . urlencode($o['order_no'])); exit; }
    }
    $rows = all('SELECT * FROM orders WHERE order_no LIKE ? OR buyer LIKE ? OR phone LIKE ? OR dorm LIKE ? ORDER BY id DESC LIMIT 30', ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%', '%' . $q . '%']);
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>订单查询 - <?= h(cfg('site_name', '自助打印')) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <div class="brand"><span class="logo"></span><?= h(cfg('site_name', '自助打印')) ?></div>
    <div class="links"><a href="index.php">返回首页</a></div>
  </div>

  <div class="card">
    <h1>订单查询</h1>
    <p class="sub">输入订单号、姓名、电话或宿舍号查询。</p>
    <form method="get" class="row">
      <div class="field" style="flex:1 1 100%">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="订单号 / 姓名 / 电话 / 宿舍号">
      </div>
      <button class="btn" style="margin-top:0;flex:0 0 120px" type="submit">查询</button>
    </form>

    <?php if ($rows): ?>
    <table class="list" style="margin-top:18px">
      <tr><th>订单号</th><th>文件</th><th>金额</th><th>状态</th><th></th></tr>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= h($r['order_no']) ?></td>
        <td class="small"><?= h($r['file_name']) ?></td>
        <td>¥<?= number_format((float)$r['amount'], 2) ?></td>
        <td><span class="badge s<?= (int)$r['status'] ?>"><?= h(status_text((int)$r['status'])) ?></span></td>
        <td><a href="order.php?no=<?= h($r['order_no']) ?>">查看</a></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php elseif ($q !== ''): ?>
      <p class="tip" style="margin-top:16px">没有找到相关订单。</p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
