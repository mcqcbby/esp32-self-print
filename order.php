<?php
require_once __DIR__ . '/lib/func.php';
$no = isset($_GET['no']) ? trim($_GET['no']) : '';
$order = $no === '' ? null : one('SELECT * FROM orders WHERE order_no=?', [$no]);
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>订单详情 - <?= h(cfg('site_name', '自助打印')) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap narrow">
  <div class="topbar">
    <div class="brand"><span class="logo"></span><?= h(cfg('site_name', '自助打印')) ?></div>
    <div class="links"><a href="index.php">再打一份</a></div>
  </div>

  <div class="card">
  <?php if (!$order): ?>
    <h1>订单不存在</h1>
    <p class="sub">请确认订单号是否正确。</p>
  <?php else: ?>
    <h1>订单状态 <span class="badge s<?= (int)$order['status'] ?>" id="badge"><?= h(status_text($order['status'])) ?></span></h1>
    <p class="sub">订单号 <?= h($order['order_no']) ?></p>

    <div class="code">
      <div class="h">取件码</div>
      <div class="c"><?= h($order['pick_code']) ?></div>
    </div>

    <table class="kv">
      <tr><td>文件</td><td><?= h($order['file_name']) ?></td></tr>
      <tr><td>页数 × 份数</td><td><?= (int)$order['pages'] ?> 页 × <?= (int)$order['copies'] ?> 份</td></tr>
      <tr><td>规格</td><td><?= $order['color'] ? '彩色' : '黑白' ?> / <?= $order['duplex'] ? '双面' : '单面' ?> / <?= h($order['paper']) ?></td></tr>
      <tr><td>取件人</td><td><?= h($order['buyer']) ?> / <?= h($order['phone']) ?> / 宿舍 <?= h($order['dorm']) ?></td></tr>
      <tr><td>金额</td><td>¥ <?= number_format((float)$order['amount'], 2) ?></td></tr>
      <tr><td>下单时间</td><td><?= date('Y-m-d H:i:s', (int)$order['create_time']) ?></td></tr>
      <tr id="msgRow" style="<?= $order['print_msg'] === '' ? 'display:none' : '' ?>"><td>打印信息</td><td id="msg"><?= h($order['print_msg']) ?></td></tr>
    </table>

    <?php if ((int)$order['status'] === 0): ?>
      <a class="btn" href="pay.php?no=<?= h($order['order_no']) ?>" style="text-align:center;text-decoration:none">去支付 ¥<?= number_format((float)$order['amount'], 2) ?></a>
    <?php endif; ?>
    <p class="tip" style="margin-top:14px">凭取件码到打印机处取件，页面会自动刷新状态。</p>
  <?php endif; ?>
  </div>
</div>
<script>
(function () {
  var no = <?= json_encode($order ? $order['order_no'] : '') ?>;
  if (!no) return;
  function tick() {
    fetch('api/status.php?no=' + encodeURIComponent(no))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) return;
        var b = document.getElementById('badge');
        b.className = 'badge s' + d.status;
        b.textContent = d.status_text;
        if (d.print_msg) {
          document.getElementById('msgRow').style.display = '';
          document.getElementById('msg').textContent = d.print_msg;
        }
      })
      .catch(function () {});
  }
  setInterval(tick, 3000);
  tick();
})();
</script>
</body>
</html>
