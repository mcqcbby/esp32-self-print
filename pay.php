<?php
require_once __DIR__ . '/lib/epay.php';
header('Cache-Control: no-cache, must-revalidate');
$no = isset($_GET['no']) ? trim($_GET['no']) : '';
$order = $no === '' ? null : one('SELECT * FROM orders WHERE order_no=?', [$no]);
$type = isset($_GET['type']) ? trim($_GET['type']) : '';

if ($order && $type !== '' && (int)$order['status'] === 0) {
    echo epay_form($order, $type);
    exit;
}

$types = array_filter(explode(',', (string)cfg('pay_types', 'alipay,wxpay')));
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>收银台 - <?= h(cfg('site_name', '自助打印')) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body>
<div class="wrap narrow">
  <div class="topbar">
    <div class="brand"><span class="logo"></span><?= h(cfg('site_name', '自助打印')) ?></div>
    <div class="links"><a href="index.php">返回首页</a></div>
  </div>

  <div class="card">
  <?php if (!$order): ?>
    <h1>订单不存在</h1>
    <p class="sub">请返回首页重新下单，或检查订单号是否正确。</p>
    <a class="btn" href="index.php" style="text-align:center;text-decoration:none">返回首页</a>
  <?php elseif ((int)$order['status'] > 0): ?>
    <h1>订单已支付</h1>
    <p class="sub">该订单当前状态：<?= h(status_text($order['status'])) ?></p>
    <a class="btn" href="order.php?no=<?= h($order['order_no']) ?>" style="text-align:center;text-decoration:none">查看订单进度</a>
  <?php else: ?>
    <h1>确认支付</h1>
    <p class="sub">订单号 <?= h($order['order_no']) ?></p>
    <table class="kv">
      <tr><td>文件</td><td><?= h($order['file_name']) ?></td></tr>
      <tr><td>页数 × 份数</td><td><?= (int)$order['pages'] ?> 页 × <?= (int)$order['copies'] ?> 份</td></tr>
      <tr><td>规格</td><td><?= $order['color'] ? '彩色' : '黑白' ?> / <?= $order['duplex'] ? '双面' : '单面' ?> / <?= h($order['paper']) ?></td></tr>
      <tr><td>应付金额</td><td><b style="font-size:20px;color:#0284c7">¥ <?= number_format((float)$order['amount'], 2) ?></b></td></tr>
    </table>

    <?php if (!epay_ready()): ?>
      <p class="err">支付接口尚未配置，请先在后台填写易支付地址、PID 与 KEY。</p>
    <?php else: ?>
      <div class="paypick">
        <?php foreach ($types as $t): ?>
          <button type="button" onclick="location.href='pay.php?no=<?= h($order['order_no']) ?>&type=<?= h($t) ?>'"><?= h(epay_type_name($t)) ?></button>
        <?php endforeach; ?>
      </div>
      <p class="tip" style="margin-top:14px">支付完成后自动进入打印队列，凭取件码取件。</p>
    <?php endif; ?>
  <?php endif; ?>
  </div>
</div>
</body>
</html>
