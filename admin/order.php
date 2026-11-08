<?php
require_once __DIR__ . '/inc.php';
admin_guard();

$id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
$o = $id ? one('SELECT * FROM orders WHERE id=?', [$id]) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $o) {
    $act = isset($_POST['act']) ? $_POST['act'] : '';
    if ($act === 'paid' && (int)$o['status'] === 0) {
        q('UPDATE orders SET status=1, pay_time=?, pay_type=? WHERE id=?', [time(), 'manual', $id]);
    } elseif ($act === 'retry') {
        @unlink(PCL_DIR . '/' . $o['order_no'] . '.pcl');
        @unlink(PCL_DIR . '/' . $o['order_no'] . '.escpr');
        @unlink(PCL_DIR . '/' . $o['order_no'] . '.pdf');
        q('UPDATE orders SET status=1, print_msg=?, printer=? WHERE id=?', ['手动重打', '', $id]);
        @ignore_user_abort(true);
        @set_time_limit(300);
        run_print_job($id);   // 立即出纸，不再干等队列
    } elseif ($act === 'fail') {
        q('UPDATE orders SET status=4, print_msg=? WHERE id=?', ['管理员标记失败', $id]);
    }
    header('Location: order.php?id=' . $id);
    exit;
}

admin_head('订单详情', 'orders');
?>
<div class="card">
  <?php if (!$o): ?>
    <h1>订单不存在</h1>
    <p><a href="orders.php">返回订单列表</a></p>
  <?php else: ?>
  <h1>订单详情 <span class="badge s<?= (int)$o['status'] ?>"><?= h(status_text((int)$o['status'])) ?></span></h1>
  <p class="sub"><?= h($o['order_no']) ?><?php if ($o['print_msg'] !== ''): ?> · <?= h($o['print_msg']) ?><?php endif; ?></p>

  <table class="kv">
    <tr><td>姓名</td><td><?= h($o['buyer'] !== '' ? $o['buyer'] : '—') ?></td></tr>
    <tr><td>电话</td><td><?= h($o['phone'] !== '' ? $o['phone'] : '—') ?></td></tr>
    <tr><td>宿舍号</td><td><?= h($o['dorm'] !== '' ? $o['dorm'] : '—') ?></td></tr>
    <tr><td>取件码</td><td><?= h($o['pick_code']) ?></td></tr>
    <tr><td>文件</td><td><?= h($o['file_name']) ?></td></tr>
    <tr><td>规格</td><td><?= $o['color'] ? '彩色' : '黑白' ?> / <?= $o['duplex'] ? '双面' : '单面' ?> / <?= h($o['paper']) ?></td></tr>
    <tr><td>页数 × 份数</td><td><?= (int)$o['pages'] ?> 页 × <?= (int)$o['copies'] ?> 份</td></tr>
    <tr><td>金额</td><td>¥ <?= number_format((float)$o['amount'], 2) ?></td></tr>
    <tr><td>支付方式</td><td><?= h($o['pay_type'] !== '' ? $o['pay_type'] : '—') ?></td></tr>
    <tr><td>下单时间</td><td><?= $o['create_time'] ? date('Y-m-d H:i:s', (int)$o['create_time']) : '—' ?></td></tr>
    <tr><td>支付时间</td><td><?= $o['pay_time'] ? date('Y-m-d H:i:s', (int)$o['pay_time']) : '—' ?></td></tr>
    <tr><td>打印时间</td><td><?= $o['print_time'] ? date('Y-m-d H:i:s', (int)$o['print_time']) : '—' ?></td></tr>
    <tr><td>打印通道</td><td><?= h($o['target']) ?><?= $o['printer'] !== '' ? ' / ' . h($o['printer']) : '' ?></td></tr>
    <tr><td>客户端 IP</td><td><?= h($o['client_ip']) ?></td></tr>
  </table>

  <form method="post" style="margin-top:10px">
    <?php if ((int)$o['status'] === 0): ?><button class="btn ghost" name="act" value="paid">标记已支付（补单）</button><?php endif; ?>
    <button class="btn ghost" name="act" value="retry">重置为重打</button>
    <button class="btn gray" name="act" value="fail" onclick="return confirm('标记为打印失败？')">标记失败</button>
  </form>
  <p style="margin-top:14px"><a href="orders.php">← 返回订单列表</a></p>
  <?php endif; ?>
</div>
<?php admin_foot(); ?>
