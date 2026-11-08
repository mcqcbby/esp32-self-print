<?php
require_once __DIR__ . '/inc.php';
admin_guard();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
    $act = isset($_POST['act']) ? $_POST['act'] : '';
    $o = $id ? one('SELECT * FROM orders WHERE id=?', [$id]) : null;
    if ($o) {
        if ($act === 'paid' && (int)$o['status'] === 0) {
            q('UPDATE orders SET status=1, pay_time=?, pay_type=? WHERE id=?', [time(), 'manual', $id]);
            @ignore_user_abort(true);
            @set_time_limit(300);
            run_print_job($id);
        } elseif ($act === 'retry') {
            @unlink(PCL_DIR . '/' . $o['order_no'] . '.pcl');
            @unlink(PCL_DIR . '/' . $o['order_no'] . '.escpr');
            @unlink(PCL_DIR . '/' . $o['order_no'] . '.pdf');
            q('UPDATE orders SET status=1, print_msg=?, printer=? WHERE id=?', ['手动重打', '', $id]);
            @ignore_user_abort(true);
            @set_time_limit(300);
            run_print_job($id);
        } elseif ($act === 'fail') {
            q('UPDATE orders SET status=4, print_msg=? WHERE id=?', ['管理员标记失败', $id]);
        } elseif ($act === 'delete') {
            purge_order_files($o);
            q('DELETE FROM orders WHERE id=?', [$id]);
        }
    }
    header('Location: orders.php');
    exit;
}

$st = isset($_GET['st']) ? (int)$_GET['st'] : -1;
$kw = isset($_GET['kw']) ? trim($_GET['kw']) : '';
$where = [];
$args = [];
if ($st >= 0) { $where[] = 'status=?'; $args[] = $st; }
if ($kw !== '') { $where[] = '(order_no LIKE ? OR file_name LIKE ? OR buyer LIKE ? OR phone LIKE ? OR dorm LIKE ?)'; $args[] = '%' . $kw . '%'; $args[] = '%' . $kw . '%'; $args[] = '%' . $kw . '%'; $args[] = '%' . $kw . '%'; $args[] = '%' . $kw . '%'; }
$sql = 'SELECT * FROM orders' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 100';
$rows = all($sql, $args);

admin_head('订单管理', 'orders');
?>
<div class="card">
  <h1>订单管理</h1>
  <form method="get" class="row">
    <div class="field">
      <label>状态</label>
      <select name="st">
        <option value="-1" <?= $st < 0 ? 'selected' : '' ?>>全部</option>
        <?php foreach ([0, 1, 2, 3, 4, 5] as $s): ?>
          <option value="<?= $s ?>" <?= $st === $s ? 'selected' : '' ?>><?= h(status_text($s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="flex:2 1 260px">
      <label>关键字</label>
      <input type="text" name="kw" value="<?= h($kw) ?>" placeholder="订单号 / 文件名 / 姓名 / 电话 / 宿舍号">
    </div>
    <div class="field" style="flex:0 0 90px;align-self:flex-end">
      <button class="btn" style="margin-top:0" type="submit">筛选</button>
    </div>
  </form>

  <table class="list" style="margin-top:18px">
    <tr><th>订单</th><th>文件 / 规格</th><th>姓名 / 电话 / 宿舍</th><th>页数</th><th>金额</th><th>状态</th><th>时间</th><th>操作</th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><a href="order.php?id=<?= (int)$r['id'] ?>"><?= h($r['order_no']) ?></a><br><span class="small">取件 <?= h($r['pick_code']) ?></span></td>
      <td class="small"><?= h($r['file_name']) ?><br><?= $r['color'] ? '彩色' : '黑白' ?> / <?= $r['duplex'] ? '双面' : '单面' ?> / <?= h($r['target']) ?></td>
      <td class="small"><?= h($r['buyer'] !== '' ? $r['buyer'] : '—') ?><br><?= h($r['phone'] !== '' ? $r['phone'] : '—') ?><br><?= h($r['dorm'] !== '' ? $r['dorm'] : '—') ?></td>
      <td><?= (int)$r['pages'] ?>×<?= (int)$r['copies'] ?></td>
      <td>¥<?= number_format((float)$r['amount'], 2) ?></td>
      <td><span class="badge s<?= (int)$r['status'] ?>"><?= h(status_text((int)$r['status'])) ?></span>
        <?php if ($r['print_msg'] !== ''): ?><br><span class="small"><?= h($r['print_msg']) ?></span><?php endif; ?>
      </td>
      <td class="small"><?= date('m-d H:i', (int)$r['create_time']) ?></td>
      <td class="small">
        <form method="post" style="display:inline">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <?php if ((int)$r['status'] === 0): ?>
            <button class="badge s1" style="border:0;cursor:pointer" name="act" value="paid">补单</button>
          <?php endif; ?>
          <?php if ((int)$r['status'] === 4): ?>
            <button class="badge s2" style="border:0;cursor:pointer" name="act" value="retry">重打</button>
          <?php endif; ?>
          <button class="badge s4" style="border:0;cursor:pointer" name="act" value="delete" onclick="return confirm('删除该订单及文件？')">删除</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="small">暂无订单</td></tr><?php endif; ?>
  </table>
</div>
<?php admin_foot(); ?>
