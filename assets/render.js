/**
 * 纯浏览器端文档渲染：把 Word / PPT / Excel / PDF / 图片 逐页画成 A4 画布
 * 服务端只接收图片，再生成打印机指令，全程不需要任何外部程序。
 */
(function () {
  'use strict';

  // 只留约 3.5mm 的安全边（爱普生不可打印区），不要把整页缩到 90%
  var A4W = 1654, A4H = 2339, MARGIN = 0.015;

  function a4() {
    var c = document.createElement('canvas');
    c.width = A4W; c.height = A4H;
    var g = c.getContext('2d');
    g.fillStyle = '#fff';
    g.fillRect(0, 0, A4W, A4H);
    return c;
  }

  /** 自动裁掉外围多余白边（保留少量白边），解决“打印出来四周空白太多” */
  function trimWhite(src, padFrac) {
    var w = src.width, h = src.height;
    if (!w || !h) return src;
    var d = src.getContext('2d').getImageData(0, 0, w, h).data;
    var minX = w, maxX = -1, minY = h, maxY = -1;
    for (var y = 0; y < h; y++) {
      var row = y * w * 4;
      for (var x = 0; x < w; x++) {
        var i = row + x * 4;
        if (d[i] < 245 || d[i + 1] < 245 || d[i + 2] < 245) {
          if (x < minX) minX = x;
          if (x > maxX) maxX = x;
          if (y < minY) minY = y;
          if (y > maxY) maxY = y;
        }
      }
    }
    if (maxX < 0) return src;                       // 全白页
    var pad = (padFrac === undefined ? 0.03 : padFrac);
    var padX = Math.round(w * pad), padY = Math.round(h * pad);
    minX = Math.max(0, minX - padX); maxX = Math.min(w - 1, maxX + padX);
    minY = Math.max(0, minY - padY); maxY = Math.min(h - 1, maxY + padY);
    var cw = maxX - minX + 1, ch = maxY - minY + 1;
    if (cw >= w - 4 && ch >= h - 4) return src;     // 本来就没有多余白边
    var c = document.createElement('canvas');
    c.width = cw; c.height = ch;
    c.getContext('2d').drawImage(src, minX, minY, cw, ch, 0, 0, cw, ch);
    return c;
  }

  function fitInto(dst, src) {
    var g = dst.getContext('2d');
    var trimmed = false;
    // 默认“内容铺满”：先裁掉文档四周大块白边，再等比铺满纸面，顶部对齐
    if (window.PRINT_FIT !== 'page') {
      var t = trimWhite(src, 0.03);
      if (t !== src) { src = t; trimmed = true; }
    }
    var maxW = A4W * (1 - 2 * MARGIN), maxH = A4H * (1 - 2 * MARGIN);
    var s = Math.min(maxW / src.width, maxH / src.height);
    var w = src.width * s, h = src.height * s;
    g.fillStyle = '#fff';
    g.fillRect(0, 0, A4W, A4H);
    g.imageSmoothingEnabled = true;
    if ('imageSmoothingQuality' in g) g.imageSmoothingQuality = 'high';
    var y = trimmed ? Math.round(A4H * MARGIN) : (A4H - h) / 2;
    g.drawImage(src, (A4W - w) / 2, y, w, h);
  }

  function sliceCanvas(big) {
    var out = [];
    var pageH = Math.floor(big.width * (A4H / A4W) * 0.98);

    // 先把长图缩成小图，用来找“整行空白”的位置，避免把一行字从中间切断
    var sw = 320, sh = Math.max(1, Math.round(big.height * sw / big.width));
    var small = document.createElement('canvas');
    small.width = sw; small.height = sh;
    small.getContext('2d').drawImage(big, 0, 0, sw, sh);
    var sd = small.getContext('2d').getImageData(0, 0, sw, sh).data;
    function blankRow(sy) {
      if (sy < 0 || sy >= sh) return false;
      for (var x = 0; x < sw; x++) {
        var i = (sy * sw + x) * 4;
        if (sd[i] < 235 || sd[i + 1] < 235 || sd[i + 2] < 235) return false;
      }
      return true;
    }
    var toBig = function (sy) { return Math.round(sy * big.height / sh); };

    var y = 0;
    while (y < big.height - 4) {
      var target = Math.min(big.height, y + pageH);
      var cut = target;
      if (target < big.height) {
        // 从目标位置往回找最近的空白行，最多回退 12% 页高
        var sy0 = Math.round(target * sh / big.height);
        var minSy = Math.round(y * sh / big.height) + 4;
        var back = Math.round(pageH * sh / big.height * 0.12) + 1;
        for (var sy = sy0; sy > sy0 - back && sy > minSy; sy--) {
          if (blankRow(sy)) { cut = toBig(sy); break; }
        }
      }
      var h = Math.max(1, Math.min(cut - y, big.height - y));
      var c = a4();
      var g = c.getContext('2d');
      g.fillStyle = '#fff';
      g.fillRect(0, 0, A4W, A4H);
      var s = (A4W * (1 - 2 * MARGIN)) / big.width;
      if (s > 1) s = 1;
      var drawW = big.width * s, drawH = h * s;
      g.drawImage(big, 0, y, big.width, h, (A4W - drawW) / 2, MARGIN * A4H, drawW, drawH);
      out.push(c);
      y += h;
    }
    return out;
  }

  function canvasFromImage(img) {
    var c = document.createElement('canvas');
    c.width = img.naturalWidth || img.width;
    c.height = img.naturalHeight || img.height;
    c.getContext('2d').drawImage(img, 0, 0);
    return c;
  }

  function loadImage(url) {
    return new Promise(function (res, rej) {
      var i = new Image();
      i.onload = function () { res(i); };
      i.onerror = rej;
      i.src = url;
    });
  }

  /* ---------- PDF ---------- */
  function renderPDF(file, onProgress) {
    return file.arrayBuffer().then(function (buf) {
      return pdfjsLib.getDocument({ data: buf }).promise;
    }).then(function (doc) {
      var pages = [];
      var chain = Promise.resolve();
      for (var i = 1; i <= doc.numPages; i++) {
        (function (n) {
          chain = chain.then(function () {
            return doc.getPage(n).then(function (page) {
              var base = page.getViewport({ scale: 1 });
              var s = Math.min(A4W * (1 - 2 * MARGIN) / base.width, A4H * (1 - 2 * MARGIN) / base.height);
              var renderScale = s * 2;
              if (base.width * renderScale > 4000) renderScale = 4000 / base.width;
              var vp = page.getViewport({ scale: renderScale });
              var c = document.createElement('canvas');
              c.width = Math.round(vp.width); c.height = Math.round(vp.height);
              var g = c.getContext('2d');
              g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height);
              return page.render({ canvasContext: g, viewport: vp, background: '#ffffff' }).promise.then(function () {
                var a = a4(); fitInto(a, c);
                pages.push(a);
                if (onProgress) onProgress(n, doc.numPages);
              });
            });
          });
        })(i);
      }
      return chain.then(function () { return pages; });
    });
  }

  /* ---------- Word ---------- */
  /** 空白段落（回车空行）要按行高占位，否则会被压扁、整篇页数变少；
      同时把浏览器过紧的单倍行距补到 Word 的中文单倍行距（约 1.3 倍字高） */
  function padEmptyParagraphs(root) {
    var ps = root.querySelectorAll('p');
    for (var i = 0; i < ps.length; i++) {
      var p = ps[i];
      var st = window.getComputedStyle(p);
      var fs = parseFloat(st.fontSize) || 16;
      var lh = parseFloat(st.lineHeight);
      if (!(lh > 4)) lh = fs * 1.2;
      if (lh < fs * 1.15) {
        lh = fs * 1.3;
        p.style.lineHeight = Math.round(lh) + 'px';
      }
      var txt = (p.textContent || '').replace(/\u00a0/g, '').replace(/\s/g, '');
      if (txt) continue;
      if (p.getBoundingClientRect().height < lh - 1) p.style.height = Math.round(lh) + 'px';
    }
  }

  /**
   * 连续长文档（docx-preview 不自动分页）按“页面内容区”分页：
   * 每一页都保留文档自己的上下左右页边距，内容按内容区宽度等比缩放。
   */
  function paginateDocx(sec, cv) {
    var st = window.getComputedStyle(sec);
    var pl = parseFloat(st.paddingLeft) || 0, pr = parseFloat(st.paddingRight) || 0;
    var pt = parseFloat(st.paddingTop) || 0, pb = parseFloat(st.paddingBottom) || 0;
    var cssW = sec.offsetWidth || 794;
    var cssPageH = cssW * (A4H / A4W);
    var k = cv.width / cssW;                       // 画布相对 CSS 的倍率
    var top = Math.round(pt * k);
    var contentW = cv.width - (pl + pr) * k;

    // 目标纸面：页边距按文档比例保留
    var mx = A4W * (pl / cssW);
    var my = A4H * (pt / cssPageH);
    var targetW = A4W - 2 * mx;
    var targetH = A4H - 2 * my;
    var s = targetW / contentW;
    var sliceH = Math.round(targetH / s);          // 每页可容纳的源图高度

    // 用缩略图找整行空白，避免把一行字切成两半
    var sw = 320, sh = Math.max(1, Math.round(cv.height * sw / cv.width));
    var small = document.createElement('canvas');
    small.width = sw; small.height = sh;
    small.getContext('2d').drawImage(cv, 0, 0, sw, sh);
    var sd = small.getContext('2d').getImageData(0, 0, sw, sh).data;
    function blankRow(sy) {
      if (sy < 0 || sy >= sh) return false;
      for (var x = 0; x < sw; x++) {
        var i = (sy * sw + x) * 4;
        if (sd[i] < 235 || sd[i + 1] < 235 || sd[i + 2] < 235) return false;
      }
      return true;
    }
    var toBig = function (sy) { return Math.round(sy * cv.height / sh); };

    // 表格每行结束线的画布 y 坐标，切页时用来避开行中间
    var secTop = sec.getBoundingClientRect().top;
    var rowEnds = [];
    Array.prototype.forEach.call(sec.querySelectorAll('tr'), function (tr) {
      rowEnds.push(Math.round((tr.getBoundingClientRect().bottom - secTop) * k));
    });

    var out = [];
    var y = top;
    while (y < cv.height - 4) {
      var target = Math.min(cv.height, y + sliceH);
      var cut = target;
      if (target < cv.height) {
        // 在理想切点上下各一段范围内找“整行全白”的空白带，切在带子中间，
        // 这样不会把一行字/一根表格线劈成两半
        var winRows = Math.round(sliceH * sh / cv.height * 0.18) + 2;
        var syMid = Math.round(target * sh / cv.height);
        var syA = Math.max(Math.round(y * sh / cv.height) + 4, syMid - winRows);
        var syB = Math.min(sh - 2, syMid);
        var bestCut = -1, bestGap = 0, runStart = -1;
        for (var sy = syA; sy <= syB; sy++) {
          var blank = blankRow(sy);
          if (blank && runStart < 0) runStart = sy;
          if (!blank && runStart >= 0) {
            var gap = sy - runStart;
            if (gap >= 2 && gap > bestGap) { bestGap = gap; bestCut = Math.round((runStart + sy - 1) / 2); }
            runStart = -1;
          }
          if (blank && sy === syB) {
            var gapEnd = sy - runStart + 1;
            if (gapEnd >= 2 && gapEnd > bestGap) { bestGap = gapEnd; bestCut = Math.round((runStart + sy) / 2); }
          }
        }
        if (bestCut >= 0) cut = toBig(bestCut);
        else {
          // 找不到整行空白时，退到最近的表格行结束线，别把表格行从中间劈开
          var near = -1, nearD = 1e9;
          for (var r = 0; r < rowEnds.length; r++) {
            var d = Math.abs(rowEnds[r] - target);
            if (d < nearD && d < sliceH * 0.18) { nearD = d; near = rowEnds[r]; }
          }
          if (near > y) cut = near;
        }
      }
      var h = Math.max(1, cut - y);
      // 切点可能比理想页高略高（为了避开半行字），这页就按自己的高度等比缩，避免底部被裁掉
      var sPage = Math.min(s, targetH / h);
      var wPage = contentW * sPage, hPage = h * sPage;
      var c = a4();
      var g = c.getContext('2d');
      g.fillStyle = '#fff';
      g.fillRect(0, 0, A4W, A4H);
      g.imageSmoothingEnabled = true;
      if ('imageSmoothingQuality' in g) g.imageSmoothingQuality = 'high';
      g.drawImage(cv, pl * k, y, contentW, h, mx + (targetW - wPage) / 2, my, wPage, hPage);
      // 切页后仍可能因源画布过大而出现整片黑，兜底重铺白底
      if (shotHealth(c).max < 40) {
        g.fillStyle = '#fff';
        g.fillRect(0, 0, A4W, A4H);
      }
      out.push(c);
      y += h;
    }
    return out;
  }

  /** 估算一页的“有墨比例”，用来判断空白页 */
  function inkRatio(cv) {
    var w = 160, h = Math.max(1, Math.round(cv.height * w / cv.width));
    var t = document.createElement('canvas');
    t.width = w; t.height = h;
    var g = t.getContext('2d');
    g.drawImage(cv, 0, 0, w, h);
    var d = g.getImageData(0, 0, w, h).data, ink = 0;
    for (var i = 0; i < d.length; i += 4) {
      if (d[i] < 200 || d[i + 1] < 200 || d[i + 2] < 200) ink++;
    }
    return ink / (w * h);
  }

  /** 抽样判断画布是不是“整张全黑”（超大画布显存分配失败时的典型症状） */
  function shotHealth(cv) {
    var g = cv.getContext('2d');
    var sum = 0, n = 0, mx = 0;
    var bands = [0.05, 0.35, 0.65, 0.95];
    for (var b = 0; b < bands.length; b++) {
      var y = Math.max(0, Math.min(cv.height - 1, Math.round(cv.height * bands[b])));
      var hh = Math.min(40, cv.height - y);
      var ww = Math.min(cv.width, 300);
      if (hh < 1 || ww < 1) continue;
      var d = g.getImageData(0, y, ww, hh).data;
      for (var i = 0; i < d.length; i += 16) {
        sum += (d[i] + d[i + 1] + d[i + 2]) / 3;
        if (d[i] > mx) mx = d[i];
        n++;
      }
    }
    return { avg: n ? sum / n : 0, max: mx };
  }

  // 从 document.xml 里读纸张尺寸和页边距（twip → CSS px，1 twip = 1/1440 英寸）
  // docx-preview 默认按 1 英寸边距排版，和 Word 里的 1.25 英寸不一样，页数就会差。
  function docxPageMetrics(buf) {
    if (!window.JSZip) return Promise.resolve(null);
    return JSZip.loadAsync(buf).then(function (zip) {
      var f = zip.file('word/document.xml');
      if (!f) return null;
      return f.async('string').then(function (xml) {
        var szTag = (xml.match(/<w:pgSz[^>]*\/?>/) || [])[0] || '';
        var marTag = (xml.match(/<w:pgMar[^>]*\/?>/) || [])[0] || '';
        function attr(tag, name) {
          var m = tag.match(new RegExp('w:' + name + '="(-?\\d+)"'));
          return m ? parseInt(m[1], 10) : null;
        }
        var w = attr(szTag, 'w'), h = attr(szTag, 'h');
        var top = attr(marTag, 'top'), right = attr(marTag, 'right');
        var bottom = attr(marTag, 'bottom'), left = attr(marTag, 'left');
        if (!w || !h) return null;
        return {
          w: w / 15, h: h / 15,
          top: (top || 0) / 15, right: (right || 0) / 15,
          bottom: (bottom || 0) / 15, left: (left || 0) / 15
        };
      });
    });
  }

  function renderDOCX(file, onProgress) {
    var box = document.createElement('div');
    box.style.cssText = 'position:fixed;left:-20000px;top:0;width:816px;background:#fff;color:#000';
    // 图片/表格一律不许超出版心，否则会被 html2canvas 从右边裁掉
    var fix = document.createElement('style');
    fix.textContent = 'section.docx img{max-width:100%!important;height:auto!important}' +
      'section.docx table{max-width:100%!important}' +
      'section.docx td,section.docx th{overflow-wrap:break-word;word-break:break-word}';
    box.appendChild(fix);
    document.body.appendChild(box);
    return file.arrayBuffer().then(function (buf) {
      return docxPageMetrics(buf).then(function (m) {
        if (m && m.w > 100) box.style.width = Math.round(m.w) + 'px';
        return docx.renderAsync(buf, box, null, {
          inWrapper: true, breakPages: true, ignoreLastRenderedPageBreak: true,
          experimental: true, useBase64URL: true
        }).then(function () { return m; });
      });
    }).then(function (m) {
      var secs = box.querySelectorAll('section.docx');
      var list = secs.length ? Array.prototype.slice.call(secs) : [box];
      // 用文档自己的页边距覆盖 docx-preview 的默认 1 英寸，页数才和 Word 对得上
      if (m && m.w > 100) {
        list.forEach(function (sec) {
          sec.style.boxSizing = 'border-box';
          sec.style.width = Math.round(m.w) + 'px';
          sec.style.maxWidth = 'none';
          sec.style.padding = Math.round(m.top) + 'px ' + Math.round(m.right) + 'px ' +
            Math.round(m.bottom) + 'px ' + Math.round(m.left) + 'px';
        });
      }
      var pages = [];
      var chain = Promise.resolve();

      // 整页文字很高时，scale 3 会生成两万像素高的画布，显存不够就会整张变黑；
      // 逐级降倍重试，直到抽样不再是纯黑。
      function shoot(sec, scales, i) {
        if (i >= scales.length) return Promise.resolve(null);
        return html2canvas(sec, { backgroundColor: '#ffffff', scale: scales[i], logging: false, useCORS: true })
          .then(function (cv) {
            var health = shotHealth(cv);
            if (health.max < 40) return shoot(sec, scales, i + 1);
            return cv;
          });
      }

      // Word 里的表格常比页面宽，docx-preview 会溢出容器，html2canvas 只拍容器宽度，
      // 右边就被裁掉。先把容器拉到实际内容宽度，再整幅拍下来（最后统一缩到 A4）。
      function widenToContent(sec) {
        var boxLeft = box.getBoundingClientRect().left;
        var maxRight = sec.getBoundingClientRect().left;
        var nodes = sec.querySelectorAll('*');
        for (var n = 0; n < nodes.length; n++) {
          var r = nodes[n].getBoundingClientRect();
          if (r.width > 0 && r.right > maxRight) maxRight = r.right;
        }
        var need = Math.ceil(maxRight - boxLeft) + 4;
        if (need > sec.clientWidth + 2) {
          sec.style.maxWidth = 'none';
          sec.style.width = need + 'px';
        }
      }

      // docx-preview 有时按图片原始像素宽度摆放，一超出版心就被截图裁掉右半截。
      // 这里把超宽图片按比例压回版心宽度，和 Word 的显示一致。
      function clampImages(sec) {
        var st = window.getComputedStyle(sec);
        var innerW = sec.clientWidth - (parseFloat(st.paddingLeft) || 0) - (parseFloat(st.paddingRight) || 0);
        var imgs = sec.querySelectorAll('img');
        for (var n = 0; n < imgs.length; n++) {
          var im = imgs[n];
          var rect = im.getBoundingClientRect();
          if (rect.width > innerW + 1 && innerW > 0) {
            im.style.width = innerW + 'px';
            im.style.maxWidth = innerW + 'px';
            im.style.height = 'auto';
          }
        }
        // 表格同理：docx 里写死的列宽加起来常超过版心，压到版心内让它自己换行
        var tables = sec.querySelectorAll('table');
        for (var t = 0; t < tables.length; t++) {
          var tb = tables[t];
          if (tb.getBoundingClientRect().width > innerW + 1 && innerW > 0) {
            tb.style.width = innerW + 'px';
            tb.style.maxWidth = innerW + 'px';
            tb.style.tableLayout = 'auto';
            var cells = tb.querySelectorAll('td, th');
            for (var c = 0; c < cells.length; c++) {
              cells[c].style.width = 'auto';
              cells[c].style.maxWidth = 'none';
            }
          }
        }
      }

      list.forEach(function (sec, idx) {
        chain = chain.then(function () {
          padEmptyParagraphs(sec);
          clampImages(sec);
          widenToContent(sec);
          return shoot(sec, [3, 2, 1.5, 1], 0).then(function (cv) {
            if (!cv) {
              // 全部倍率都失败：至少输出空白页，绝不把黑页交给打印机
              pages.push(a4());
              if (onProgress) onProgress(idx + 1, list.length);
              return;
            }
            var pageRatio = cv.height / cv.width;
            if (pageRatio > (A4H / A4W) * 1.25) {
              pages = pages.concat(paginateDocx(sec, cv));
            } else {
              var a = a4(); fitInto(a, cv); pages.push(a);
            }
            if (onProgress) onProgress(idx + 1, list.length);
          });
        });
      });
      return chain.then(function () {
        document.body.removeChild(box);
        // 去掉末尾几乎全白的空页（多出的空白页不该让用户付钱）
        while (pages.length > 1 && inkRatio(pages[pages.length - 1]) < 0.004) pages.pop();
        return pages;
      });
    }, function (e) {
      if (box.parentNode) document.body.removeChild(box);
      throw e;
    });
  }

  /* ---------- Excel ---------- */
  function renderXLSX(file, onProgress) {
    return file.arrayBuffer().then(function (buf) {
      var wb = XLSX.read(buf, { type: 'array' });
      var pages = [];
      var chain = Promise.resolve();
      wb.SheetNames.forEach(function (name, si) {
        chain = chain.then(function () {
          var ws = wb.Sheets[name];
          var box = document.createElement('div');
          box.style.cssText = 'position:fixed;left:-20000px;top:0;width:1400px;background:#fff;color:#000;padding:20px;font:15px/1.5 "Microsoft YaHei",sans-serif';
          box.innerHTML = '<div style="font-size:18px;font-weight:bold;margin-bottom:10px">' + escapeHtml(name) + '</div>' +
            XLSX.utils.sheet_to_html(ws, { editable: false });
          Array.prototype.forEach.call(box.querySelectorAll('td,th'), function (c) {
            c.style.border = '1px solid #999';
            c.style.padding = '4px 6px';
          });
          document.body.appendChild(box);
          return html2canvas(box, { backgroundColor: '#ffffff', scale: 1.6, logging: false })
            .then(function (cv) {
              pages = pages.concat(sliceCanvas(cv));
              document.body.removeChild(box);
              if (onProgress) onProgress(si + 1, wb.SheetNames.length);
            }, function (e) {
              document.body.removeChild(box);
              throw e;
            });
        });
      });
      return chain.then(function () { return pages; });
    });
  }

  /* ---------- PPT ---------- */
  function renderPPTX(file, onProgress) {
    return JSZip.loadAsync(file).then(function (zip) {
      var presFile = zip.file('ppt/presentation.xml');
      if (!presFile) throw new Error('不是有效的 PPTX 文件');
      return presFile.async('string').then(function (presXml) {
        var m = presXml.match(/<p:sldSz[^>]*cx="(\d+)"[^>]*cy="(\d+)"/);
        var ratio = m ? (parseInt(m[1], 10) / parseInt(m[2], 10)) : 16 / 9;
        var slides = [];
        zip.forEach(function (p, f) {
          var mm = p.match(/^ppt\/slides\/slide(\d+)\.xml$/);
          if (mm) slides.push({ n: parseInt(mm[1], 10), path: p });
        });
        slides.sort(function (a, b) { return a.n - b.n; });
        var pages = [];
        var chain = Promise.resolve();
        slides.forEach(function (s, i) {
          chain = chain.then(function () {
            return zip.file(s.path).async('string').then(function (xml) {
              var texts = [];
              var re = /<a:t>([\s\S]*?)<\/a:t>/g, r;
              while ((r = re.exec(xml))) {
                var t = unescapeXml(r[1]).trim();
                if (t) texts.push(t);
              }
              var w = 1400, h = Math.round(w / (ratio > 0 ? ratio : 1.33));
              var c = document.createElement('canvas');
              c.width = w; c.height = h;
              var g = c.getContext('2d');
              g.fillStyle = '#fff'; g.fillRect(0, 0, w, h);
              g.fillStyle = '#111';
              g.font = 'bold 34px "Microsoft YaHei",sans-serif';
              g.textBaseline = 'top';
              var y = 60;
              texts.forEach(function (t, ti) {
                g.font = ti === 0 ? 'bold 42px "Microsoft YaHei",sans-serif' : '26px "Microsoft YaHei",sans-serif';
                var maxW = w - 120;
                var line = '';
                for (var k = 0; k < t.length; k++) {
                  var ch = t.charAt(k);
                  if (g.measureText(line + ch).width > maxW) {
                    g.fillText(line, 60, y); y += 40; line = ch;
                    if (y > h - 80) { return; }
                  } else line += ch;
                }
                if (line) { g.fillText(line, 60, y); y += ti === 0 ? 70 : 40; }
                if (y > h - 60) return;
              });
              g.fillStyle = '#999';
              g.font = '18px sans-serif';
              g.textAlign = 'right';
              g.fillText(String(i + 1) + ' / ' + slides.length, w - 40, h - 36);
              var a = a4(); fitInto(a, c);
              pages.push(a);
              if (onProgress) onProgress(i + 1, slides.length);
            });
          });
        });
        return chain.then(function () { return pages; });
      });
    });
  }

  /* ---------- 图片 ---------- */
  function renderImage(file) {
    return new Promise(function (res, rej) {
      var url = URL.createObjectURL(file);
      loadImage(url).then(function (img) {
        var a = a4();
        fitInto(a, canvasFromImage(img));
        URL.revokeObjectURL(url);
        res([a]);
      }, function (e) { URL.revokeObjectURL(url); rej(e); });
    });
  }

  function escapeHtml(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
  function unescapeXml(s) {
    return s.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&apos;/g, "'");
  }

  /* ---------- 单色 1 位 PNG 打包（服务端零逐像素处理） ---------- */
  var crcTable = (function () {
    var t = [];
    for (var n = 0; n < 256; n++) {
      var c = n;
      for (var k = 0; k < 8; k++) c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
      t[n] = c >>> 0;
    }
    return t;
  })();

  function crc32(bytes) {
    var c = 0xFFFFFFFF;
    for (var i = 0; i < bytes.length; i++) c = crcTable[(c ^ bytes[i]) & 0xFF] ^ (c >>> 8);
    return (c ^ 0xFFFFFFFF) >>> 0;
  }

  function chunk(type, data) {
    var out = new Uint8Array(12 + data.length);
    var dv = new DataView(out.buffer);
    dv.setUint32(0, data.length);
    for (var i = 0; i < 4; i++) out[4 + i] = type.charCodeAt(i);
    out.set(data, 8);
    var crcInput = new Uint8Array(4 + data.length);
    crcInput.set(out.subarray(4, 8), 0);
    crcInput.set(data, 4);
    dv.setUint32(8 + data.length, crc32(crcInput));
    return out;
  }

  function storedDeflate(data) {
    var parts = [];
    parts.push(new Uint8Array([0x78, 0x01]));
    var pos = 0;
    while (pos < data.length) {
      var len = Math.min(65535, data.length - pos);
      var hdr = new Uint8Array(5);
      hdr[0] = (pos + len >= data.length) ? 1 : 0;
      hdr[1] = len & 0xFF; hdr[2] = (len >>> 8) & 0xFF;
      hdr[3] = (~len) & 0xFF; hdr[4] = ((~len) >>> 8) & 0xFF;
      parts.push(hdr);
      parts.push(data.subarray(pos, pos + len));
      pos += len;
    }
    var a = 1, b = 0;
    for (var i = 0; i < data.length; i++) { a = (a + data[i]) % 65521; b = (b + a) % 65521; }
    var tail = new Uint8Array(4);
    var dv = new DataView(tail.buffer);
    dv.setUint32(0, ((b << 16) | a) >>> 0);
    parts.push(tail);
    var total = 0;
    parts.forEach(function (p) { total += p.length; });
    var out = new Uint8Array(total), o = 0;
    parts.forEach(function (p) { out.set(p, o); o += p.length; });
    return out;
  }

  function deflateBytes(bytes) {
    if (!window.CompressionStream) return Promise.resolve(storedDeflate(bytes));
    try {
      var cs = new CompressionStream('deflate');
      var stream = new Blob([bytes]).stream().pipeThrough(cs);
      return new Response(stream).arrayBuffer().then(function (b) { return new Uint8Array(b); }, function () {
        return storedDeflate(bytes);
      });
    } catch (e) {
      return Promise.resolve(storedDeflate(bytes));
    }
  }

  function monoBlob(canvas) {
    var w = canvas.width, h = canvas.height;
    var ctx = canvas.getContext('2d');
    var img = ctx.getImageData(0, 0, w, h);
    var d = img.data;
    var rowBytes = Math.ceil(w / 8);
    var rows = new Uint8Array(rowBytes * h);
    var p = 0;
    for (var y = 0; y < h; y++) {
      var byte = 0, bit = 0, base = y * rowBytes;
      for (var x = 0; x < w; x++) {
        var i = p + x * 4;
        var gray = (d[i] * 77 + d[i + 1] * 150 + d[i + 2] * 29) >> 8;
        if (gray >= 168) byte |= (0x80 >> bit);
        bit++;
        if (bit === 8) { rows[base++] = byte; byte = 0; bit = 0; }
      }
      if (bit > 0) rows[base++] = byte;
      p += w * 4;
    }
    var raw = new Uint8Array(h * (rowBytes + 1));
    for (var yy = 0; yy < h; yy++) {
      raw[yy * (rowBytes + 1)] = 0;
      raw.set(rows.subarray(yy * rowBytes, (yy + 1) * rowBytes), yy * (rowBytes + 1) + 1);
    }
    return deflateBytes(raw).then(function (idat) {
      var ihdr = new Uint8Array(13);
      var dv = new DataView(ihdr.buffer);
      dv.setUint32(0, w); dv.setUint32(4, h);
      ihdr[8] = 1; ihdr[9] = 0; ihdr[10] = 0; ihdr[11] = 0; ihdr[12] = 0;
      var sig = new Uint8Array([137, 80, 78, 71, 13, 10, 26, 10]);
      var parts = [sig, chunk('IHDR', ihdr), chunk('IDAT', idat), chunk('IEND', new Uint8Array(0))];
      var total = 0;
      parts.forEach(function (x) { total += x.length; });
      var out = new Uint8Array(total), o = 0;
      parts.forEach(function (x) { out.set(x, o); o += x.length; });
      return new Blob([out], { type: 'image/png' });
    });
  }

  window.Renderer = {
    monoBlob: monoBlob,
    render: function (file, onProgress) {
      var ext = (file.name.split('.').pop() || '').toLowerCase();
      if (ext === 'pdf') return renderPDF(file, onProgress);
      if (ext === 'docx') return renderDOCX(file, onProgress);
      if (ext === 'pptx') return renderPPTX(file, onProgress);
      if (ext === 'xlsx' || ext === 'xls') return renderXLSX(file, onProgress);
      if (ext === 'png' || ext === 'jpg' || ext === 'jpeg') return renderImage(file);
      if (ext === 'doc' || ext === 'ppt') {
        return Promise.reject(new Error('旧版 .' + ext + ' 无法在浏览器中渲染，请另存为 .' + ext + 'x 或 PDF 后再上传'));
      }
      return Promise.reject(new Error('不支持的文件类型：' + ext));
    }
  };
})();
