(function () {
  'use strict';

  var chosen = null;
  var state = { color: 0, duplex: 0, pages: 0, layout: 'browser' };
  var drop = document.getElementById('drop');
  var fileInput = document.getElementById('file');
  var dropTitle = document.getElementById('dropTitle');
  var dropHint = document.getElementById('dropHint');
  var btnUpload = document.getElementById('btnUpload');
  var btnPay = document.getElementById('btnPay');
  var btnBack = document.getElementById('btnBack');
  var stepUpload = document.getElementById('stepUpload');
  var stepConfirm = document.getElementById('stepConfirm');
  if (/MicroMessenger/i.test(navigator.userAgent)) {
    var wxTip = document.getElementById('wxTip');
    if (wxTip) wxTip.style.display = 'block';
  }
  var confirmTable = document.getElementById('confirmTable');
  var thumbs = document.getElementById('thumbs');
  var estimate = document.getElementById('estimate');
  var toastEl = document.getElementById('toast');
  var progress = document.getElementById('progress');
  var barFill = document.getElementById('barFill');
  var progressText = document.getElementById('progressText');
  var currentOrder = null;
  var renderedPages = [];

  var pb = document.querySelector('.pricebox');
  var PRICE = {
    bw: parseFloat(pb.getAttribute('data-bw') || '0.4'),
    color: parseFloat(pb.getAttribute('data-color') || '0.5'),
    bwDuplex: parseFloat(pb.getAttribute('data-bw-duplex') || '0.6'),
    colorDuplex: parseFloat(pb.getAttribute('data-color-duplex') || '0.8'),
    min: parseFloat(pb.getAttribute('data-min') || '0.1')
  };

  if (window.pdfjsLib) {
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'assets/vendor/pdf.worker.min.js';
  }

  function toast(msg) {
    toastEl.textContent = msg;
    toastEl.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { toastEl.classList.remove('show'); }, 3000);
  }

  function showProgress(show, pct, text) {
    progress.style.display = show ? 'block' : 'none';
    if (pct != null) barFill.style.width = Math.max(2, Math.min(100, pct)) + '%';
    if (text != null) progressText.textContent = text;
  }

  function calc() {
    if (!state.pages) return null;
    var copies = Math.max(1, parseInt(document.getElementById('copies').value, 10) || 1);
    var amount;
    if (state.duplex) {
      // 双面按张计费（一张纸 2 页，不足 2 页按 1 张）
      var sheets = Math.ceil(state.pages / 2);
      amount = sheets * copies * (state.color ? PRICE.colorDuplex : PRICE.bwDuplex);
    } else {
      amount = state.pages * copies * (state.color ? PRICE.color : PRICE.bw);
    }
    if (amount < PRICE.min) amount = PRICE.min;
    return amount;
  }

  function refreshEstimate() {
    var a = calc();
    estimate.textContent = a == null ? '按页数计算' : '¥ ' + a.toFixed(2);
  }

  function allowed(f) {
    var ext = (f.name.split('.').pop() || '').toLowerCase();
    return ['doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'pdf', 'png', 'jpg', 'jpeg'].indexOf(ext) >= 0;
  }

  /** 用本机 Word 把 docx 转成 PDF：页数/页边距/空行与 Word 完全一致 */
  function convertPdf(file) {
    var fd = new FormData();
    fd.append('file', file, file.name);
    return post('api/convert.php', fd).then(function (d) {
      if (!d.ok) throw new Error(d.msg || 'Word 转换失败');
      return fetch(d.pdf, { cache: 'no-store' }).then(function (r) {
        if (!r.ok) throw new Error('转换后的 PDF 下载失败');
        return r.blob();
      });
    }).then(function (b) {
      var base = file.name.replace(/\.(docx?|doc)$/i, '');
      return new File([b], base + '.pdf', { type: 'application/pdf' });
    });
  }

  function setFile(f) {
    if (!f) return;
    if (!allowed(f)) { toast('不支持的文件类型，请使用 docx/pptx/xlsx/pdf/png/jpg'); return; }
    if (f.size > 10 * 1024 * 1024) { toast('文件超过 10MB'); return; }
    chosen = f;
    state.pages = 0;
    // 非 Word 文件不涉及排版差异，页数就是文件本身的页数
    state.layout = /\.(docx?|doc)$/i.test(f.name) ? 'browser' : 'pdf';
    refreshEstimate();
    dropTitle.textContent = f.name;
    dropHint.textContent = (f.size / 1024 / 1024).toFixed(2) + ' MB，点击下方按钮开始';
    if (/\.(docx?|doc)$/i.test(f.name)) {
      dropHint.textContent = '正在用 Word 排版（页数与版式和 Word 一致）…';
      convertPdf(f).then(function (pdf) {
        if (chosen !== f) return;
        chosen = pdf;
        state.layout = 'word';
        dropTitle.textContent = pdf.name;
            dropHint.textContent = '已按 Word 版式生成 ' + (pdf.size / 1024 / 1024).toFixed(2) + ' MB PDF，点击下方按钮开始';
      }).catch(function (e) {
        var m = e.message || '';
        state.layout = 'browser';
        // 服务器没装排版引擎 / 禁用了 exec：属正常降级，不当成报错吓用户
        if (/no_exec|no_script|no_engine|没有排版引擎|禁用|缺少转换脚本|undefined function|NO_COM|未安装/.test(m)) {
          dropHint.textContent = '本服务器没有排版引擎（Windows 装 Word/WPS，Linux 装 LibreOffice），改用浏览器排版（页数会与 Word 有差异）';
        } else if (/convert_failed|排版转换失败/.test(m)) {
          dropHint.textContent = '排版转换失败，改用浏览器排版：' + m;
        } else {
          dropHint.textContent = '改用浏览器排版：' + m;
        }
      });
    }
  }

  fileInput.addEventListener('change', function () {
    setFile(fileInput.files[0]);
    fileInput.value = '';
  });
  ['dragenter', 'dragover'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); });
  });
  drop.addEventListener('drop', function (e) {
    if (e.dataTransfer.files && e.dataTransfer.files.length) setFile(e.dataTransfer.files[0]);
  });

  function bindSeg(id, key) {
    var box = document.getElementById(id);
    box.addEventListener('click', function (e) {
      var b = e.target.closest('button');
      if (!b) return;
      Array.prototype.forEach.call(box.querySelectorAll('button'), function (x) { x.classList.remove('on'); });
      b.classList.add('on');
      state[key] = parseInt(b.getAttribute('data-v'), 10);
      refreshEstimate();
    });
  }
  bindSeg('segColor', 'color');
  bindSeg('segDuplex', 'duplex');
  document.getElementById('copies').addEventListener('input', refreshEstimate);

  /** 把彩色画面转成灰度（黑白模式用，IPP/ESC-PR 只能送 JPEG，不能送 1 位 PNG） */
  function grayCanvas(cv) {
    var c = document.createElement('canvas');
    c.width = cv.width; c.height = cv.height;
    var g = c.getContext('2d');
    g.drawImage(cv, 0, 0);
    var img = g.getImageData(0, 0, c.width, c.height);
    var a = img.data;
    for (var i = 0; i < a.length; i += 4) {
      var l = (a[i] * 0.299 + a[i + 1] * 0.587 + a[i + 2] * 0.114);
      l = l < 0 ? 0 : (l > 255 ? 255 : l);
      a[i] = a[i + 1] = a[i + 2] = l;
    }
    g.putImageData(img, 0, 0);
    return c;
  }

  function canvasToBlob(canvas, colorMode, mode) {
    if (mode === 'ipp' || mode === 'escpr') {
      // IPP 直打与 ESC/P-R（爱普生 JPEG 通道）只能送 JPEG；黑白模式在这里先转灰度再压成 JPEG
      var out = colorMode ? canvas : grayCanvas(canvas);
      return new Promise(function (res) {
        out.toBlob(function (b) { res(b); }, 'image/jpeg', 0.92);
      });
    }
    if (!colorMode && window.Renderer && Renderer.monoBlob) return Renderer.monoBlob(canvas);
    return new Promise(function (res) {
      canvas.toBlob(function (b) { res(b); }, 'image/png');
    });
  }

  function post(url, fd) {
    return fetch(url, { method: 'POST', body: fd }).then(function (r) {
      return r.text().then(function (t) {
        try {
          return JSON.parse(t);
        } catch (e) {
          // 服务器把报错 HTML 混进了响应：折算成一句人话，不再抛 JSON 语法错误
          var plain = t.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 120);
          throw new Error('服务器返回异常（HTTP ' + r.status + '）' + (plain ? '：' + plain : ''));
        }
      });
    });
  }

  var pending = null;   // 预览阶段暂存：还没建单，只有本地文件和信息
  var uploading = false;

  btnUpload.addEventListener('click', function () {
    if (!chosen) { toast('请先选择文件'); return; }
    var buyer = document.getElementById('buyer').value.trim();
    var phone = document.getElementById('phone').value.trim();
    var dorm = document.getElementById('dorm').value.trim();
    if (!buyer) { toast('请填写姓名'); document.getElementById('buyer').focus(); return; }
    if (!phone) { toast('请填写电话'); document.getElementById('phone').focus(); return; }
    if (!/^\d{6,15}$/.test(phone)) { toast('电话请填数字（6-15 位）'); document.getElementById('phone').focus(); return; }
    if (!dorm) { toast('请填写宿舍号'); document.getElementById('dorm').focus(); return; }
    var file = chosen;
    btnUpload.disabled = true;
    btnUpload.textContent = '正在排版…';
    showProgress(true, 3, '正在读取文件…');

    Renderer.render(file, function (i, total) {
      showProgress(true, 5 + (i / total) * 55, '正在排版第 ' + i + ' / ' + total + ' 页…');
    }).then(function (pages) {
      if (!pages.length) throw new Error('没有解析出任何页面');
      renderedPages = pages;
      state.pages = pages.length;
      refreshEstimate();
      // 只做本地预览，先不建单、不上传；点“确认并上传”才真正下单
      pending = {
        file: file,
        buyer: buyer,
        phone: phone,
        dorm: dorm,
        copies: Math.max(1, parseInt(document.getElementById('copies').value, 10) || 1),
        paper: document.getElementById('paper').value
      };
      showProgress(false);
      btnUpload.disabled = false;
      btnUpload.textContent = '生成预览';
      buildThumbs();
      renderPreview();
      stepUpload.style.display = 'none';
      stepConfirm.style.display = 'block';
      window.scrollTo(0, 0);
    }).catch(function (e) {
      btnUpload.disabled = false;
      btnUpload.textContent = '生成预览';
      showProgress(false);
      toast(e.message || String(e));
    });
  });

  /** 预览阶段真正确认：建单 → 逐页上传 → 去支付 */
  function startOrder() {
    if (!pending || uploading) return;
    uploading = true;
    btnPay.disabled = true;
    btnBack.disabled = true;

    var pages = renderedPages;
    var copies = pending.copies;
    var mode = 'esp32';
    var fd = new FormData();
    fd.append('file_name', pending.file.name);
    fd.append('ext', (pending.file.name.split('.').pop() || 'pdf').toLowerCase());
    fd.append('pages', pages.length);
    fd.append('copies', copies);
    fd.append('color', state.color);
    fd.append('duplex', state.duplex);
    fd.append('paper', pending.paper);
    fd.append('buyer', pending.buyer);
    fd.append('phone', pending.phone);
    fd.append('dorm', pending.dorm);

    showProgress(true, 62, '正在创建订单…');
    post('api/create.php', fd).then(function (d) {
      if (!d.ok) throw new Error(d.msg || '创建订单失败');
      var order = d;
      mode = order.mode || 'esp32';
      currentOrder = order;
      var ext = (mode === 'ipp' || mode === 'escpr') ? 'jpg' : 'png';
      var chain = Promise.resolve();
      var done = 0;
      pages.forEach(function (cv, i) {
        chain = chain.then(function () {
          return canvasToBlob(cv, state.color, mode).then(function (blob) {
            var f2 = new FormData();
            f2.append('order_no', order.order_no);
            f2.append('index', i + 1);
            f2.append('file', blob, 'p' + (i + 1) + '.' + ext);
            return post('api/page.php', f2).then(function (r) {
              if (!r.ok) throw new Error(r.msg || ('第 ' + (i + 1) + ' 页上传失败'));
              done++;
              order.amount = r.amount;
              showProgress(true, 62 + (done / pages.length) * 36, '正在上传第 ' + done + ' / ' + pages.length + ' 页…');
            });
          });
        });
      });
      return chain.then(function () { return order; });
    }).then(function (order) {
      showProgress(true, 100, '已完成，正在前往支付…');
      location.href = 'pay.php?no=' + encodeURIComponent(order.order_no);
    }).catch(function (e) {
      uploading = false;
      btnPay.disabled = false;
      btnBack.disabled = false;
      showProgress(false);
      toast(e.message || String(e));
    });
  }

  function buildThumbs() {
    thumbs.innerHTML = '';
    var max = Math.min(renderedPages.length, 12);
    for (var i = 0; i < max; i++) {
      var t = document.createElement('canvas');
      var s = 180 / renderedPages[i].width;
      t.width = 180; t.height = Math.round(renderedPages[i].height * s);
      var g = t.getContext('2d');
      g.drawImage(renderedPages[i], 0, 0, t.width, t.height);
      // 选了“黑白”就把预览也灰掉，避免预览彩色、实际出黑白的不一致
      if (!state.color) {
        var d = g.getImageData(0, 0, t.width, t.height);
        var a = d.data;
        for (var k = 0; k < a.length; k += 4) {
          var l = a[k] * 0.299 + a[k + 1] * 0.587 + a[k + 2] * 0.114;
          a[k] = a[k + 1] = a[k + 2] = l;
        }
        g.putImageData(d, 0, 0);
      }
      var img = new Image();
      img.src = t.toDataURL('image/jpeg', 0.6);
      thumbs.appendChild(img);
    }
  }

  function renderPreview() {
    var p = pending;
    var a = calc();
    var rows = [
      ['文件名', p.file.name],
      ['页数', state.pages + ' 页'],
      ['排版', state.layout === 'word' ? 'Word 版式（页数与 Word 一致）'
        : (state.layout === 'browser' ? '浏览器排版（页数可能与 Word 差 1~2 页，内容完整不缺页）' : '原始文件页数')],
      ['份数', p.copies + ' 份'],
      ['颜色', state.color ? '彩色' : '黑白'],
      ['单双面', state.duplex ? '双面' : '单面'],
      ['纸张', p.paper],
      ['姓名', p.buyer],
      ['电话', p.phone],
      ['宿舍号', p.dorm],
      ['应付金额', '¥ ' + (a == null ? '--' : a.toFixed(2))]
    ];
    confirmTable.innerHTML = rows.map(function (r) {
      return '<tr><td>' + r[0] + '</td><td>' + escapeHtml(String(r[1])) + '</td></tr>';
    }).join('');
    btnPay.textContent = '确认并上传' + (a == null ? '' : ' ¥' + a.toFixed(2));
  }

  var thumbsBuilt = false;

  function escapeHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  btnPay.addEventListener('click', function () {
    if (currentOrder) { location.href = 'pay.php?no=' + encodeURIComponent(currentOrder.order_no); return; }
    startOrder();
  });

  btnBack.addEventListener('click', function () {
    if (uploading) return;
    currentOrder = null;
    pending = null;
    chosen = null;
    state.pages = 0;
    renderedPages = [];
    fileInput.value = '';
    btnPay.disabled = false;
    btnBack.disabled = false;
    btnUpload.disabled = false;
    btnUpload.textContent = '生成预览';
    dropTitle.textContent = '点击选择文件，或拖到这里';
    dropHint.textContent = 'docx / pptx / xlsx / pdf / png / jpg';
    thumbs.innerHTML = '';
    confirmTable.innerHTML = '';
    estimate.textContent = '--';
    showProgress(false);
    stepConfirm.style.display = 'none';
    stepUpload.style.display = 'block';
  });

  // 打印设备在线状态，每 10 秒刷新一次
  var pill = document.getElementById('printStatus');
  var pillText = document.getElementById('printStatusText');
  function refreshPrintStatus() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'api/device_status.php?_=' + Date.now(), true);
    xhr.onload = function () {
      if (xhr.status !== 200) return;
      var d = {};
      try { d = JSON.parse(xhr.responseText); } catch (e) { return; }
      if (!pill || typeof d.online === 'undefined') return;
      pill.classList.toggle('on', !!d.online);
      pill.classList.toggle('off', !d.online);
      if (pillText) pillText.textContent = d.text || (d.online ? '打印盒在线' : '打印盒离线');
      pill.title = d.detail || '';
    };
    xhr.send();
  }
  refreshPrintStatus();
  setInterval(refreshPrintStatus, 10000);
})();
