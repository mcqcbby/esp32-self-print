<?php
require_once __DIR__ . '/lib/func.php';
header('Cache-Control: no-cache, must-revalidate');
$site = cfg('site_name', '自助打印');
$bw = cfg('price_bw', '0.40');
$color_price = cfg('price_color', '0.50');
$bw2 = cfg('price_bw_duplex', '0.60');
$color2 = cfg('price_color_duplex', '0.80');
$dev = print_box_status();
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($site) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <div class="brand"><span class="logo"></span><?= h($site) ?></div>
    <div class="links"><a href="query.php">订单查询</a><a href="admin/login.php">后台</a></div>
  </div>

  <div class="card" id="stepUpload">
    <h1>上传文件，自助打印</h1>
    <p class="sub">支持 Word / PPT / Excel / PDF / 图片，自动识别页数并计价。</p>
    <div class="pill <?= $dev['online'] ? 'on' : 'off' ?>" id="printStatus" data-online="<?= $dev['online'] ? 1 : 0 ?>">
      <i></i><span id="printStatusText"><?= h($dev['text']) ?></span>
    </div>

    <div class="drop" id="drop">
      <div class="big" id="dropTitle">点击选择文件</div>
      <div class="small" id="dropHint">docx / pptx / xlsx / pdf / png / jpg，单文件最大 <?= MAX_UPLOAD_MB ?>MB</div>
      <input type="file" id="file" accept="*/*">
    </div>
    <div class="small" id="wxTip" style="display:none;margin-top:10px;color:#b45309">微信里选不到文档？点右上角「…」选择在浏览器打开，再上传。</div>

    <div class="row">
      <div class="field">
        <label>份数</label>
        <input type="number" id="copies" value="1" min="1" max="50">
      </div>
      <div class="field segfield">
        <label>颜色</label>
        <div class="seg" id="segColor">
          <button type="button" class="on" data-v="0">黑白</button>
          <button type="button" data-v="1">彩色</button>
        </div>
      </div>
      <div class="field segfield">
        <label>单双面</label>
        <div class="seg" id="segDuplex">
          <button type="button" class="on" data-v="0">单面</button>
          <button type="button" data-v="1">双面</button>
        </div>
      </div>
    </div>

    <div class="row">
      <div class="field">
        <label>姓名</label>
        <input type="text" id="buyer" maxlength="20" placeholder="你的姓名">
      </div>
      <div class="field">
        <label>电话</label>
        <input type="tel" id="phone" maxlength="20" inputmode="numeric" placeholder="手机号">
      </div>
      <div class="field">
        <label>宿舍号</label>
        <input type="text" id="dorm" maxlength="20" placeholder="如 3-402">
      </div>
    </div>

    <div class="row">
      <div class="field" style="flex:0 0 180px">
        <label>纸张</label>
        <select id="paper"><option value="A4">A4</option></select>
      </div>
    </div>

    <div class="pricebox" data-bw="<?= h($bw) ?>" data-color="<?= h($color_price) ?>" data-bw-duplex="<?= h($bw2) ?>" data-color-duplex="<?= h($color2) ?>" data-min="<?= h(cfg('min_amount', '0.10')) ?>">
      <div class="pricewrap">
        <div class="ptitle">收费标准</div>
        <div class="pchips">
          <span class="chip"><i class="dot bw"></i>单面 黑白 <b>¥<?= h($bw) ?></b>/页</span>
          <span class="chip"><i class="dot cl"></i>单面 彩色 <b>¥<?= h($color_price) ?></b>/页</span>
          <span class="chip dup"><i class="dot bw"></i>双面 黑白 <b>¥<?= h($bw2) ?></b>/张</span>
          <span class="chip dup"><i class="dot cl"></i>双面 彩色 <b>¥<?= h($color2) ?></b>/张</span>
        </div>
        <div class="pnote">双面每张 2 页</div>
      </div>
      <div class="amountwrap">
        <div class="label">预估金额</div>
        <div class="amount" id="estimate">--</div>
      </div>
    </div>

    <button class="btn" id="btnUpload">生成预览</button>
  </div>

  <div class="card" id="stepConfirm" style="display:none">
    <h1 id="confirmTitle">预览并确认</h1>
    <p class="sub" id="confirmSub">确认页数与金额，点“确认并上传”才会创建订单。</p>
    <div class="thumbs" id="thumbs"></div>
    <table class="kv" id="confirmTable"></table>
    <button class="btn" id="btnPay">确认并上传</button>
    <button class="btn gray" id="btnBack" style="margin-top:10px">重新上传</button>
  </div>

  <div class="progress" id="progress" style="display:none">
    <div class="bar"><i id="barFill"></i></div>
    <div class="ptext" id="progressText"></div>
  </div>
</div>
<div class="toast" id="toast"></div>

<script src="assets/vendor/jszip.min.js"></script>
<script src="assets/vendor/pdf.min.js"></script>
<script src="assets/vendor/docx-preview.min.js"></script>
<script src="assets/vendor/xlsx.full.min.js"></script>
<script src="assets/vendor/html2canvas.min.js"></script>
<script>window.PRINT_FIT = '<?= h(cfg('print_fit', 'page')) ?>';</script>
<script src="assets/render.js?v=<?= @filemtime(__DIR__ . '/assets/render.js') ?>"></script>
<script src="assets/app.js?v=<?= @filemtime(__DIR__ . '/assets/app.js') ?>"></script>
</body>
</html>
