#!/usr/bin/env bash
# 给 Linux 自助打印站装排版引擎：LibreOffice + 中文宋体/黑体
# 作用：docx 先由 soffice 转 PDF，页数/分页/页边距就与 Word 一致（浏览器排版做不到）
# 用法（root 执行）：bash tools/linux_setup.sh
set -u

echo "== 1. 检查系统与已有引擎 =="
if command -v soffice >/dev/null 2>&1; then
  echo "已装 LibreOffice: $(soffice --version 2>/dev/null | head -n1)"
else
  echo "未装 LibreOffice，开始安装（约 500MB，几分钟）"
  if command -v apt-get >/dev/null 2>&1; then
    apt-get update -y
    DEBIAN_FRONTEND=noninteractive apt-get install -y libreoffice-writer libreoffice-core fonts-liberation
  elif command -v dnf >/dev/null 2>&1; then
    dnf install -y libreoffice-writer
  elif command -v yum >/dev/null 2>&1; then
    yum install -y libreoffice-writer
  else
    echo "!! 认不出包管理器，请手动装 libreoffice-writer"; exit 1
  fi
fi

echo "== 2. 安装中文字体（宋体/黑体/楷体/Times） =="
FONT_DIR=/usr/share/fonts/truetype/winfonts
mkdir -p "$FONT_DIR"
# 把 Windows 的 C:\Windows\Fonts 下的 simsun.ttc simhei.ttf simkai.ttf times.ttf
# 用宝塔文件管理上传到网站根目录的 fonts/ 下，再执行本脚本即可
SRC_DIR="$(cd "$(dirname "$0")/.." && pwd)/fonts"
if [ -d "$SRC_DIR" ]; then
  cp -f "$SRC_DIR"/*.tt[fc] "$SRC_DIR"/*.ttf "$FONT_DIR"/ 2>/dev/null
  echo "已导入: $(ls "$FONT_DIR" | tr '\n' ' ')"
else
  echo "!! 未找到 $SRC_DIR ，跳过。没有宋体/黑体时 LibreOffice 会用替代字体，页数仍会与 Word 不同"
fi
fc-cache -f >/dev/null 2>&1 || true
command -v fc-match >/dev/null 2>&1 && echo "宋体匹配: $(fc-match SimSun) / 黑体匹配: $(fc-match SimHei)"

echo "== 3. 转换自检 =="
TMP=$(mktemp -d)
if [ -f "$SRC_DIR/test.docx" ]; then
  HOME=/tmp soffice --headless --norestore --nologo --convert-to pdf --outdir "$TMP" "$SRC_DIR/test.docx" >/dev/null 2>&1
  if [ -s "$TMP/test.pdf" ]; then
    echo "转换成功: $TMP/test.pdf $(du -h "$TMP/test.pdf" | cut -f1)"
  else
    echo "!! 转换失败，请把 test.docx 换成一个正常 docx 再试"
  fi
else
  echo "（可选）放一个 fonts/test.docx 可自动验证转换"
fi
rm -rf "$TMP"

echo "== 4. 还要在宝塔里做的一步 =="
echo "PHP 设置 -> 禁用函数：把 exec 删掉并重载 PHP，否则 api/convert.php 会返回 no_exec"
echo "完成后上传 api/convert.php，前台应出现“已按 Word 版式生成 x MB PDF”，页数即与 Word 一致"
