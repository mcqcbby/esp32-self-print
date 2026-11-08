<?php
require_once __DIR__ . '/inc.php';
admin_guard();

$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $keys = ['site_name', 'site_domain', 'price_bw', 'price_color', 'price_bw_duplex', 'price_color_duplex', 'min_amount', 'epay_url', 'epay_pid', 'epay_key', 'pay_types', 'agent_token', 'pcl_color', 'print_mode', 'ipp_url', 'printer_lang', 'print_fit'];
    foreach ($keys as $k) {
        if (isset($_POST[$k])) cfg_set($k, trim((string)$_POST[$k]));
    }
    $m = isset($_POST['print_mode']) ? (string)$_POST['print_mode'] : 'ipp';
    cfg_set('print_mode', $m === 'esp32' ? 'esp32' : 'ipp');
    cfg_set('print_target', $m === 'esp32' ? 'esp32' : 'ipp');
    if (!empty($_POST['new_pass'])) {
        cfg_set('admin_pass', password_hash((string)$_POST['new_pass'], PASSWORD_DEFAULT));
    }
    header('Location: settings.php?saved=1');
    exit;
}
$saved = isset($_GET['saved']);
admin_head('系统设置', 'settings');
?>
<div class="card">
  <h1>系统设置<?= $saved ? ' <span class="ok">已保存</span>' : '' ?></h1>
  <form method="post">
    <h2 style="margin-top:20px">基础</h2>
    <div class="row">
      <div class="field"><label>站点名称</label><input type="text" name="site_name" value="<?= h(cfg('site_name')) ?>"></div>
      <div class="field" style="flex:2 1 300px"><label>公网访问地址</label>
        <input type="text" name="site_domain" value="<?= h(cfg('site_domain', '')) ?>" placeholder="https://你的域名">
      </div>
    </div>

    <h2 style="margin-top:22px">出纸方式</h2>
    <div class="row">
      <div class="field"><label>打印通道</label>
        <select name="print_mode">
          <option value="ipp" <?= cfg('print_mode', 'ipp') === 'ipp' ? 'selected' : '' ?>>局域网 IPP 直打</option>
          <option value="esp32" <?= cfg('print_mode', 'ipp') === 'esp32' ? 'selected' : '' ?>>ESP32 USB 打印盒</option>
        </select>
      </div>
      <div class="field"><label>打印语言（ESP32 通道）</label>
        <select name="printer_lang">
          <option value="pcl5" <?= cfg('printer_lang', 'escpr') === 'pcl5' ? 'selected' : '' ?>>PCL5 / PCL6</option>
          <option value="escpr" <?= cfg('printer_lang', 'escpr') === 'escpr' ? 'selected' : '' ?>>ESC/P-R</option>
        </select>
      </div>
    </div>
    <div class="row" style="margin-top:10px">
      <div class="field" style="flex:2 1 260px"><label>打印机 IPP 地址</label>
        <input type="text" name="ipp_url" value="<?= h(cfg('ipp_url', '')) ?>" placeholder="https://192.168.1.105:631/ipp/print">
      </div>
    </div>

    <h2 style="margin-top:22px">价格</h2>
    <div class="row">
      <div class="field"><label>单面 黑白 元/页</label><input type="text" name="price_bw" value="<?= h(cfg('price_bw', '0.40')) ?>"></div>
      <div class="field"><label>单面 彩色 元/页</label><input type="text" name="price_color" value="<?= h(cfg('price_color', '0.50')) ?>"></div>
      <div class="field"><label>双面 黑白 元/张</label><input type="text" name="price_bw_duplex" value="<?= h(cfg('price_bw_duplex', '0.60')) ?>"></div>
      <div class="field"><label>双面 彩色 元/张</label><input type="text" name="price_color_duplex" value="<?= h(cfg('price_color_duplex', '0.80')) ?>"></div>
      <div class="field"><label>最低消费 元</label><input type="text" name="min_amount" value="<?= h(cfg('min_amount', '0.10')) ?>"></div>
    </div>

    <h2 style="margin-top:22px">易支付</h2>
    <div class="row">
      <div class="field" style="flex:2 1 260px"><label>接口地址</label><input type="text" name="epay_url" value="<?= h(cfg('epay_url')) ?>"></div>
      <div class="field"><label>PID</label><input type="text" name="epay_pid" value="<?= h(cfg('epay_pid')) ?>"></div>
      <div class="field"><label>KEY</label><input type="text" name="epay_key" value="<?= h(cfg('epay_key')) ?>"></div>
    </div>
    <div class="row">
      <div class="field"><label>支付方式</label><input type="text" name="pay_types" value="<?= h(cfg('pay_types')) ?>" placeholder="alipay,wxpay,qqpay"></div>
      <div class="field"><label>打印版式</label>
        <select name="print_fit">
          <option value="page" <?= cfg('print_fit', 'page') === 'page' ? 'selected' : '' ?>>原版式</option>
          <option value="fill" <?= cfg('print_fit', 'page') === 'fill' ? 'selected' : '' ?>>铺满</option>
        </select>
      </div>
      <div class="field"><label>彩色输出</label>
        <select name="pcl_color">
          <option value="0" <?= cfg('pcl_color', '0') === '0' ? 'selected' : '' ?>>灰度</option>
          <option value="1" <?= cfg('pcl_color', '0') === '1' ? 'selected' : '' ?>>彩色</option>
        </select>
      </div>
    </div>

    <h2 style="margin-top:22px">后台</h2>
    <div class="row">
      <div class="field"><label>修改密码（留空不改）</label><input type="password" name="new_pass" placeholder="新密码"></div>
      <div class="field" style="flex:2 1 240px"><label>设备令牌</label><input type="text" name="agent_token" value="<?= h(cfg('agent_token')) ?>"></div>
    </div>

    <button class="btn" type="submit">保存设置</button>
  </form>
</div>
<?php admin_foot(); ?>
