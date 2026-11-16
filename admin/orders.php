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
    header('Location: orders.php?page=' . max(1, (int)(isset($_POST['page']) ? $_POST['page'] : 1)) . '&st=' . (int)(isset($_POST['st']) ? $_POST['st'] : -1) . '&kw=' . urlencode(isset($_POST['kw']) ? $_POST['kw'] : ''));
    exit;
}

$st = isset($_GET['st']) ? (int)$_GET['st'] : -1;
$kw = isset($_GET['kw']) ? trim($_GET['kw']) : '';
$where = [];
$args = [];
if ($st >= 0) { $where[] = 'status=?'; $args[] = $st; }
if ($kw !== '') { $where[] = '(order_no LIKE ? OR file_name LIKE ? OR buyer LIKE ? OR phone LIKE ? OR dorm LIKE ?)'; $args[] = '%' . $kw . '%'; $args[] = '%' . $kw . '%'; $args[] = '%' . $kw . '%'; $args[] = '%' . $kw . '%'; $args[] = '%' . $kw . '%'; }
$sql = 'SELECT * FROM orders' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC';
$total = (int)(one('SELECT COUNT(*) AS c FROM orders' . ($where ? ' WHERE ' . implode(' AND ', $where) : ''), $args)['c']);
$per = 20;
$pagesTotal = max(1, (int)ceil($total / $per));
$page = min($pagesTotal, max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1)));
$offset = ($page - 1) * $per;
$rows = all($sql . ' LIMIT ' . $offset . ', ' . $per);

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

  <div class="tablewrap">
  <table class="list orders">
    <tr><th>订单</th><th>文件</th><th class="buyer-col">取件人</th><th class="pages-col">页数</th><th>金额</th><th>状态 / 操作</th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td>
        <a class="ono" href="order.php?id=<?= (int)$r['id'] ?>"><?= h($r['order_no']) ?></a>
        <span class="mini">取件 <?= h($r['pick_code']) ?> · <?= date('m-d H:i', (int)$r['create_time']) ?></span>
      </td>
      <td>
        <span class="fname" title="<?= h($r['file_name']) ?>"><?= h($r['file_name']) ?></span>
        <span class="mini"><?= $r['color'] ? '彩色' : '黑白' ?> / <?= $r['duplex'] ? '双面' : '单面' ?> / <?= h($r['target']) ?></span>
        <span class="mini only-m"><?= h($r['buyer'] !== '' ? $r['buyer'] : '—') ?><br><?= h($r['phone'] !== '' ? $r['phone'] : '—') ?> · <?= h($r['dorm'] !== '' ? $r['dorm'] : '—') ?></span>
      </td>
      <td class="buyer-col">
        <span class="bname"><?= h($r['buyer'] !== '' ? $r['buyer'] : '—') ?></span>
        <span class="mini"><?= h($r['phone'] !== '' ? $r['phone'] : '—') ?> · <?= h($r['dorm'] !== '' ? $r['dorm'] : '—') ?></span>
      </td>
      <td class="pages-col"><span class="num"><?= (int)$r['pages'] ?>×<?= (int)$r['copies'] ?></span></td>
      <td><span class="money">¥<?= number_format((float)$r['amount'], 2) ?></span>
        <span class="mini only-m"><?= (int)$r['pages'] ?>×<?= (int)$r['copies'] ?> 页</span>
      </td>
      <td>
        <span class="badge s<?= (int)$r['status'] ?>"><?= h(status_text((int)$r['status'])) ?></span>
        <?php if ($r['print_msg'] !== ''): ?><span class="msg"><?= h($r['print_msg']) ?></span><?php endif; ?>
        <form method="post" class="rowact">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="page" value="<?= $page ?>">
          <input type="hidden" name="st" value="<?= $st ?>">
          <input type="hidden" name="kw" value="<?= h($kw) ?>">
          <?php if ((int)$r['status'] === 0): ?>
            <button class="badge s1" name="act" value="paid">补单</button>
          <?php endif; ?>
          <?php if ((int)$r['status'] === 4): ?>
            <button class="badge s2" name="act" value="retry">重打</button>
          <?php endif; ?>
          <button class="badge s4" name="act" value="delete" onclick="return confirm('删除该订单及文件？')">删除</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="mini" style="padding:16px 0">暂无订单</td></tr><?php endif; ?>
  </table>
  </div>

  <div class="pager">
    <?php if ($page > 1): ?><a href="?st=<?= $st ?>&kw=<?= urlencode($kw) ?>&page=<?= $page - 1 ?>">上一页</a><?php else: ?><span class="off">上一页</span><?php endif; ?>
    <span class="pinfo">第 <?= $page ?> / <?= $pagesTotal ?> 页 · 共 <?= $total ?> 单</span>
    <?php if ($page < $pagesTotal): ?><a href="?st=<?= $st ?>&kw=<?= urlencode($kw) ?>&page=<?= $page + 1 ?>">下一页</a><?php else: ?><span class="off">下一页</span><?php endif; ?>
  </div>
</div>
<?php admin_foot(); ?>
