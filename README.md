# 自助打印站 · PHP + ESP32-S3 IPP 打印盒

一个不用装任何 exe、不用装 Office 的自助打印系统：用户用手机或电脑上传文档 → **在本地浏览器里渲染成 A4 页面图并先预览页数金额** → 确认后才建单上传 → 扫码支付 → ESP32 打印盒领任务，用 IPP 发到局域网打印机出纸。

- 服务端：纯 PHP + MySQL，虚拟主机也能跑（不需要 `exec`、不需要 GD/Imagick）
- 排版：docx / xlsx / pptx / pdf 全部在浏览器端解析排版，html2canvas 拍成 1654×2339（A4 200dpi）整页图
- 出纸：ESP32-S3 + 打印机 WiFi IPP（`https://打印机IP:631/ipp/print`），不占用电脑、不需要 USB 直连

---

## 一、功能

| 模块 | 说明 |
|---|---|
| 上传与预览 | 支持 `doc/docx/ppt/pptx/xls/xlsx/pdf/png/jpg/jpeg`；本地渲染，**不确认不建单、不上传** |
| 计费 | 按页/按张计费，单面、双面、黑白、彩色四档价格，最低消费可配 |
| 下单 | 姓名 / 电话 / 宿舍必填，生成订单号与取件码 |
| 支付 | 易支付（支付宝、微信），异步回调自动置为「已支付 排队中」 |
| 打印通道 | ① ESP32 打印盒（推荐）② 服务器直接 IPP 直打 ③ 旧版 PCL / ESC-P-R 通道 |
| 打印盒 | 打印机在线才领任务、离线不抢单；板载 RGB 灯指示状态；串口日志回传后台 |
| 后台 | 今日概览、订单列表与手动重打、收费设置、打印盒在线状态与运行日志 |

订单状态：`0 待支付` → `1 已支付 排队中` → `2 打印中` → `3 已完成`；失败 `4`，退款 `5`。

### 界面一览

| 页面 | 入口 | 主要内容 |
|---|---|---|
| 下单 / 预览 | `index.php` | 设备状态胶囊、拖放上传、份数、颜色、单双面、姓名/电话/宿舍、收费标准标签、预估金额、「生成预览」 |
| 预览确认 | `index.php` 的 `#stepConfirm` | 每页缩略图、页数、排版口径、规格、金额、「确认并上传」、「重新上传」 |
| 收银台 | `pay.php?no=订单号` | 订单信息、应付金额、支付宝 / 微信按钮 |
| 订单进度 | `order.php?no=订单号` | 状态徽标、取件码、打印信息，每 3 秒自动刷新 |
| 订单查询 | `query.php` | 凭订单号或手机号找回订单 |
| 后台概览 | `admin/index.php` | 今日订单 / 收款 / 完成 / 排队、运行状态、ESP32 心跳日志、打印盒运行日志 |
| 后台订单 | `admin/orders.php` | 订单列表、详情、手动重打 |
| 后台设置 | `admin/settings.php`、`admin/printers.php` | 收费标准、易支付、出纸方式、打印盒与 IPP 地址 |

**下单页**：顶部是站点名与「订单查询 / 后台」入口，下面一个状态胶囊（`打印机在线` / `打印机离线` / `打印盒在线` / `打印盒离线`，每 10 秒轮询 `api/device_status.php`）。上传区支持点击或拖放，微信内置浏览器会提示「在浏览器中打开」。份数、颜色、单双面、姓名、电话、宿舍都是一行小尺寸排布，手机端自动两列；收费是四个彩色小标签（单面黑白 / 单面彩色 / 双面黑白 / 双面彩色），右下角实时显示预估金额。底部主按钮是「生成预览」。

**预览确认页**：只在浏览器本地渲染，**不建单、不上传**。左边逐页缩略图（选黑白时缩略图同步变灰），右边信息表：文件、页数、排版口径、规格、金额。其中「排版」一行会写明 `Word 版式（页数与 Word 一致）`、`浏览器排版（页数可能与 Word 差 1~2 页）` 或 `PDF`。点「确认并上传 ¥x.xx」才开始建单并逐页上传，成功后跳收银台；「重新上传」会清空预览回到第一步。

**收银台**：显示订单号、文件、页数×份数、规格、应付金额，下面是支付宝 / 微信按钮（易支付）；未配置支付时给出提示，已支付则显示状态并引导查看进度。

**订单进度**：大字取件码 + 状态徽标 + 订单明细，页面每 3 秒拉 `api/status.php` 自动刷新，打印失败时会把原因显示在「打印信息」一行。

**后台概览**：除今日数据，还有三块和打印盒相关——出纸方式与打印机地址、`ESP32 日志`（心跳原文，按 `printer=1/0` 显示「在线 · 会领任务 / 离线 · 不领任务」，上报超过 30 秒显示「上报已过期」）、`打印盒运行日志`（设备 IP、固件版本、当前状态、最近上报时间 + 串口日志原文）。


---

## 二、两条打印链路

**A. ESP32 打印盒（推荐）**

```
浏览器渲染 A4 页面图 ──POST──> 服务器 uploads/pages/<订单号>/p001.jpg...
                                        │
     用户支付 ──> 订单 status=1 ────────┤
                                        ▼
ESP32 每 3 秒 poll ──> 领到任务 ──> 逐页下载 JPEG ──> TLS 连 631 ──> IPP Print-Job ──> 出纸
```

**B. 服务器 IPP 直打**：`print_mode=ipp`，PHP 直接用 `lib/ipp.php` 提交给打印机。适合服务器和打印机同网段、且 PHP 有 openssl 的环境。

---

## 三、目录结构

```
.
├── index.php              下单/预览页（上传 → 本地渲染 → 预览确认）
├── pay.php                支付页
├── query.php              订单查询
├── order.php              订单详情
├── notify.php / return.php 易支付回调
├── install.php            一次性安装入口（装完请删除）
├── install.sql            建表 + 默认设置
├── config.php             数据库与目录配置（含密码，不要提交）
├── api/
│   ├── create.php         建单（只写订单，不上传文件）
│   ├── page.php           逐页上传页面图（校验 JPEG/PNG 文件头）
│   ├── convert.php        docx→PDF 可选引擎（Windows Word/WPS、Linux LibreOffice）
│   ├── status.php         订单状态查询
│   ├── device_status.php  打印设备在线状态
│   └── esp32.php          打印盒接口（poll/pages/file/done/error/ping/log）
├── lib/
│   ├── db.php  func.php   数据库、设置、计费、状态文案
│   ├── print.php          打印通道分发
│   ├── ipp.php            IPP 直打
│   ├── pcl.php escpr.php  PCL5/6 与 ESC/P-R 位图通道
│   └── png.php pdf.php    PNG 解码、PDF 处理
├── assets/
│   ├── render.js          docx/xlsx/pptx/pdf 渲染 + 分页 + 拍图
│   ├── app.js             上传、预览、建单、逐页上传
│   └── vendor/            jszip、pdf.js、docx-preview、xlsx、html2canvas
├── admin/                 后台（概览、订单、打印机、设置）
├── firmware_arduino/
│   └── esp32_ipp_box/     ESP32-S3 打印盒固件（WiFi IPP）
├── tools/
│   ├── word2pdf.ps1       Windows 下用 Word/WPS COM 转 PDF（可选）
│   └── linux_setup.sh     Linux 装 LibreOffice 与中文字体（可选）
├── cron/                  定时清理与队列补偿
├── uploads/               原件、页面图、中间文件
└── logs/                  运行日志（可整目录清空）
```

---

## 四、环境要求

**必需**

- PHP 7.4+（推荐 8.1/8.2），扩展：`pdo_mysql`、`zlib`
- MySQL 5.7+ / MariaDB 10+
- 站点根目录可写：`uploads/`、`logs/`

**可选（只影响「页数与 Word 是否一致」）**

- Windows 且装了 Word 或 WPS → `tools/word2pdf.ps1` 用 COM 转 PDF，页数与 Word 完全一致
- Linux 且允许 `exec`、能装 LibreOffice → `tools/linux_setup.sh`
- 二者都没有时自动降级为浏览器排版，页数可能与 Word 差 1~2 页，前端「排版」一行会明确标注

> 虚拟主机通常禁 `exec` + `open_basedir` 限制，装不了 LibreOffice，属于后一种情况。

**上传大小**：除 `config.php` 的 `MAX_UPLOAD_MB`，还要放开 nginx 的 `client_max_body_size` 与站点 `.user.ini`（仓库内 `.user.ini` 已写 64M）。

---

## 五、快速部署

```bash
# 1. 放到网站根目录
git clone <本仓库地址> /var/www/print
cd /var/www/print

# 2. 配置数据库（DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS）
cp config.example.php config.php

# 3. 建表
mysql -u<user> -p<pass> <库名> < install.sql
#   或浏览器打开 /install.php 自动建表并写入默认后台密码

# 4. 权限
chmod -R 777 uploads logs

# 5. 进后台：/admin/login.php  默认 admin / admin888（登录后立刻改）
```

**装完必须做的三件事**

1. 删除 `install.php`（暴露安装入口）
2. 删除 `_diag.php`（诊断脚本，公网可见）
3. 后台改 `agent_token`，与固件 `DEVICE_TOKEN` 保持一致

---

## 六、配置说明

`config.php` 关键项：

| 常量 | 默认 | 说明 |
|---|---|---|
| `DB_*` | — | 数据库连接 |
| `MAX_UPLOAD_MB` | 10 | 单文件大小上限 |
| `MAX_PAGES` | 200 | 单次最多页数 |
| `MAX_PAGE_MB` | 12 | 单页图片上限 |
| `ALLOW_EXT` | doc…jpeg | 允许上传的扩展名 |
| `RENDER_W/RENDER_H` | 1654×2339 | 浏览器出图尺寸（A4 200dpi） |
| `PCL_W/PCL_H` | 1654×2339 | 打印画布，必须与渲染尺寸一致 |

`settings` 表（后台可改）：

| key | 示例 | 说明 |
|---|---|---|
| `print_mode` | `esp32` | `esp32`=打印盒领取；`ipp`=服务器直打 |
| `print_target` | `esp32` | 下单时写入订单的通道 |
| `printer_lang` | `escpr` | 打印盒出图格式：`escpr`=JPEG，其他=PCL 位图 |
| `ipp_url` | `https://192.168.1.101:631/ipp/print` | 直打地址 |
| `price_bw / price_color` | 0.40 / 0.50 | 单面单价（元/页） |
| `price_bw_duplex / price_color_duplex` | 0.60 / 0.80 | 双面单价（元/张，按 `ceil(页数/2)`） |
| `min_amount` | 0.10 | 最低消费 |
| `agent_token` | 随机串 | 打印盒接口令牌 |
| `epay_url / epay_pid / epay_key` | — | 易支付参数 |
| `site_domain` | — | 回调域名 |

计费公式：

```
单面：页数 × 份数 × 单价
双面：ceil(页数 / 2) × 份数 × 单价
金额低于 min_amount 时按 min_amount 收
```

---

## 七、接口一览

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/create.php` | POST | 建单：`file_name/ext/pages/copies/color/duplex/paper/buyer/phone/dorm` |
| `/api/page.php` | POST | 上传单页：`order_no/index/file`，按模式校验 JPEG 或 PNG 文件头 |
| `/api/status.php` | GET | `?no=订单号` → 状态文本、打印备注 |
| `/api/device_status.php` | GET | 打印盒/打印机是否在线，前端胶囊用 |
| `/api/convert.php` | POST | docx→PDF（有引擎时才成功，失败返回 `no_engine` / `convert_failed`） |

### 打印盒接口 `/api/esp32.php`

所有请求都要带 `&token=<agent_token>`。

| action | 参数 | 返回 / 说明 |
|---|---|---|
| `poll` | `pr=1` | 领任务，返回 `job{id,order_no,file_name,pages,copies,duplex,color}`；`print_mode=ipp` 或打印机离线时返回 `job:null` |
| `pages` | `id` | 返回 `order_no / dir / pages / color / duplex / files[]`（真实文件名，别死拼 `p001.jpg`） |
| `file` | `id` | 下载整份文件（旧通道用） |
| `done` / `error` | `id`、`msg` | 回写订单状态与打印备注 |
| `ping` | `pr`、`state`、`ip`、`fw`、`msg` | 心跳，写 `logs/esp32_state.json` 与 `logs/esp32_state.log` |
| `log` | `msg` | 设备串口日志落 `logs/esp32.log`，超 400KB 只留末 200KB |

**打印机离线闸门**：服务端读 `logs/esp32_state.log` 最后一行，若含 `printer=0` 且时间在 30 秒内，`poll` 直接返回 `{"job":null,"note":"printer offline"}`，不会把订单领走再判失败。

---

## 八、ESP32-S3 打印盒

### 硬件

- ESP32-S3 N16R8 开发板（16MB Flash / 8MB PSRAM）
- 打印机：支持 IPPS（AirPrint）的机型，例如 EPSON L4168
- 只需要 USB 供电，不需要接打印机数据线

### Arduino IDE 设置

| 项目 | 值 |
|---|---|
| 开发板 | ESP32S3 Dev Module |
| Flash Size | 16MB |
| **PSRAM** | **OPI PSRAM**（不设为 0，下载大图必报「内存不够」） |
| Partition Scheme | 16M Flash (3MB APP/9.9MB FATFS) |
| USB Mode | USB-OTG (TinyUSB) |
| USB CDC On Boot | Disabled |
| 串口监视器 | 115200 |

### 需要改的常量（`esp32_ipp_box.ino` 顶部）

```cpp
const char *WIFI_SSID     = "你的WiFi";
const char *WIFI_PASSWORD = "你的密码";
const char *SERVER_BASE   = "https://你的域名";   // http 也行，下载会自动跟随 301/302
const char *DEVICE_TOKEN  = "后台 agent_token 的值";
const char *PRINTER_HOST  = "192.168.1.101";      // 打印机 IP
const int   LED_PIN       = 48;                   // 板载 RGB；不亮改 38
const int   LED_BRIGHT    = 18;                   // 亮度 0~255，越小越暗
```

### 固件行为

1. 每轮先 TCP 探打印机 `631` 端口：连不上就**只发心跳、不发 poll**，不领任务
2. 打印机在线才 `poll&pr=1` 领任务；领到后逐页下载 JPEG（优先用服务器返回的真实文件名，失败回退 `p%03d.jpg`）
3. 内存按真实 `Content-Length` 申请，无长度时 512KB 起翻倍扩容，最多 4MB；优先 PSRAM
4. 用 `WiFiClientSecure`（`setInsecure()`）连 `https://打印机IP:631/ipp/print`，发 IPP Print-Job，`document-format=image/jpeg`
5. IPP 属性：`sides`（单面 / 双面长边）、`media=iso_a4_210x297mm`、`print-color-mode=monochrome|color`
6. 打印完 `action=done`，失败 `action=error` 并把原因（`dl_fail` / `tls_fail` / `no_resp` / `ipp_xxxx`）写进订单备注
7. 运行日志攒在缓冲区，空闲时批量 `action=log` 推到后台

### 灯效

| 颜色 | 含义 |
|---|---|
| 绿灯常亮 | 打印机在线，待命领任务 |
| 红灯常亮 | 打印机离线（631 不通），不领任务 |
| 蓝灯 | 正在打印 |

### 常见问题

| 现象 | 原因 / 处理 |
|---|---|
| 串口 `PSRAM=0` | Arduino IDE 没开 `OPI PSRAM` |
| `[下载] 内存不够` | 同上；或页图超过 4MB（检查渲染尺寸） |
| `[下载] 服务器返回 HTTP 301` | 固件用明文请求 `https` 站点；固件已跟随跳转，仍报则把 `SERVER_BASE` 改成实际协议 |
| 一直红灯 | 打印机休眠 / IP 变了 / 开了 AP 隔离；先 `ping` 打印机，再确认 `631` 通 |
| 灯不亮 | `LED_PIN` 改 `38` 试试 |
| 领到任务但没出纸 | 看 `[IPP] HTTP ... IPP 0x....`：`0x0400` 一类多为属性不支持，可试改 `document-format=application/pdf` |

---

## 九、已知限制

- 没有排版引擎（Word/WPS/LibreOffice）的环境下，页数按浏览器排版口径，可能与 Word 差 1~2 页；确认页会显示「排版」一行，口径透明
- 浏览器渲染依赖 Canvas，超大文档（>100 页）明显占内存，`MAX_PAGES` 默认 200，可按机器性能下调
- 打印盒按单台设备设计（`device_id` 单值），多台需扩展任务分配
- 打印机只支持 IPPS 时，必须用 `https://IP:631`，明文 IPP 会被拒（`Not Allowed`）

---

## 十、安全与提交注意

- `config.php` 含数据库密码，**不要提交**（仓库 `.gitignore` 已排除）
- `uploads/`、`logs/` 是运行产物，不提交
- 上线前删 `install.php`、`_diag.php`，改后台密码与 `agent_token`
- 打印盒接口只校验 `token`，建议放内网或加 IP 白名单

---

## 十一、许可

转载、商用请保留作者信息。
