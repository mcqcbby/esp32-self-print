<?php
/**
 * 后台：打印预览（显示服务器已经渲染好、实际送去打印的页面图）
 */
require_once __DIR__ . '/inc.php';
admin_guard();

$id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
$o = $id ? one('SELECT * FROM orders WHERE id=?', [$id]) : null;

if (!$o) {
    admin_head('打印预览', 'orders');
    echo '<div class="card"><h1>订单不存在</h1><p><a href="orders.php">返回订单列表</a></p></div>';
    admin_foot();
    exit;
}

$dir = pages_dir_of($o);
$files = [];
if (is_dir($dir)) {
    $files = glob(rtrim($dir, '/') . '/p*.{jpg,jpeg,png}', GLOB_BRACE);
    if ($files) sort($files);
}
$dirName = basename($dir);
$src = order_src_path($o);

admin_head('打印预览', 'orders');
?>
<div class="card">
  <div class="row" style="align-items:center;margin-top:0">
    <div style="flex:1 1 auto;min-width:0">
      <h1 style="margin-bottom:4px">打印预览</h1>
      <p class="sub" style="margin:0"><?= h($o['order_no']) ?> · <?= h($o['file_name']) ?></p>
      <p class="sub" style="margin:4px 0 0">共 <?= count($files) ?> 页 · 以下就是打印机实际收到的页面图</p>
    </div>
    <div style="flex:0 0 auto">
      <?php if ($src !== ''): ?>
        <a class="btn ghost" href="src.php?id=<?= (int)$o['id'] ?>&dl=1">下载原件</a>
      <?php endif; ?>
      <a class="btn ghost" href="order.php?id=<?= (int)$o['id'] ?>">订单详情</a>
    </div>
  </div>

  <?php if (!$files): ?>
    <p class="tip" style="margin-top:16px">这个订单没有可预览的页面图，可能已被清理或订单未上传完成。</p>
  <?php else: ?>
    <div class="pagesview">
      <?php foreach ($files as $i => $f): ?>
        <div class="pageitem">
          <div class="pagebar">
            <span>第 <?= $i + 1 ?> 页</span>
            <a href="../uploads/pages/<?= h(rawurlencode($dirName)) ?>/<?= h(rawurlencode(basename($f))) ?>" target="_blank">查看原图</a>
          </div>
          <a href="../uploads/pages/<?= h(rawurlencode($dirName)) ?>/<?= h(rawurlencode(basename($f))) ?>" target="_blank">
            <img src="../uploads/pages/<?= h(rawurlencode($dirName)) ?>/<?= h(rawurlencode(basename($f))) ?>" alt="第 <?= $i + 1 ?> 页">
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php admin_foot(); ?>
