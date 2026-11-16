# 自助打印站 · PHP + ESP32-S3 打印盒

> 不用装 exe、不用装 Office、不用电脑常开：手机上传文档 → 浏览器本地排版预览 → 支付 → 局域网 ESP32 打印盒用 IPP 出纸。

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql&logoColor=white)
![ESP32-S3](https://img.shields.io/badge/ESP32--S3-N16R8-E7352C?logo=espressif&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-green)

---

## 目录

- [特性](#特性)
- [打印链路](#打印链路)
- [目录结构](#目录结构)
- [环境要求](#环境要求)
- [快速部署](#快速部署)
- [配置说明](#配置说明)
- [后台功能](#后台功能)
- [接口一览](#接口一览)
- [ESP32 打印盒](#esp32-打印盒)
- [常见问题](#常见问题)
- [已知限制](#已知限制)
- [安全](#安全)
- [许可](#许可)

---

## 特性

| 模块 | 说明 |
|---|---|
| 上传与预览 | 支持 `doc/docx/ppt/pptx/xls/xlsx/pdf/png/jpg/jpeg`；浏览器本地渲染，**不确认不建单、不上传** |
| 排版 | docx-preview、SheetJS、pdf.js 解析，html2canvas 拍成 1654×2339（A4 200dpi）整页图 |
| 计费 | 单面 / 双面 × 黑白 / 彩色四档单价，支持最低消费 |
| 支付 | 易支付（支付宝、微信），异步回调验签、比价后置为已支付 |
| 打印通道 | 只有 ESP32 打印盒一条通道，服务器不直连打印机 |
| 打印语言 | ESC/P-R（IPP + JPEG）与 PCL5（9100 原始端口）自动跟随后台设置 |
| 状态实时 | 打印盒每 5 秒上报，在线判据 15 秒，后台每 3 秒自动刷新 |
| 故障上报 | 卡纸、缺纸、墨尽、盖板未关等由 job-state-reasons 解析成中文并显示 |
| 资源监控 | 后台显示 CPU 频率与负载、空闲内存、PSRAM、WiFi 信号、运行时长 |
| 后台 | 今日概览、订单表格（每页 20 单）、打印预览页面图、手动重打、收费与打印设置 |

订单状态：`0 待支付` → `1 已支付 排队中` → `2 打印中` → `3 已完成`；失败 `4`，退款 `5`。

---

## 打印链路

```
浏览器渲染 A4 页面图 ──POST──> uploads/pages/<订单号>/p001.jpg ...
                                        │
      用户支付 ──> 订单 status=1 ───────┘
                                        ▼
ESP32 每 3 秒 poll ──> 原子领取任务 ──> 逐页下载 ──> IPPS 631 或 9100 ──> 出纸
```

1. 浏览器按 A4 分页并截图，用户确认前不产生任何服务端文件。
2. 确认后建单、上传原件与页面图，跳转收银台。
3. 支付回调验签通过，订单进入排队。
4. 打印盒仅在打印机在线时领任务；离线只发心跳，订单留在队列。
5. 每页发送后查询 IPP `job-state`，完成才算成功；卡纸、缺纸等立即回传原因。

---

## 目录结构

```
.
├── index.php                下单 / 预览页
├── pay.php                  收银台
├── order.php                订单进度
├── query.php                订单查询
├── notify.php / return.php  易支付回调
├── install.php / install.sql 安装入口与建表
├── config.php               数据库与目录配置
├── api/
│   ├── create.php           建单计费
│   ├── page.php             页面图上传（校验 JPEG/PNG 文件头）
│   ├── status.php           订单状态
│   ├── device_status.php    打印盒 / 打印机在线状态
│   ├── convert.php          可选 docx→PDF 引擎
│   └── esp32.php            打印盒接口（poll/pages/file/done/error/ping/log）
├── lib/
│   ├── db.php / func.php    数据库、设置、计费、状态文案
│   ├── print.php            排队与领任务
│   ├── pcl.php / escpr.php  PCL5 与 ESC/P-R 通道
│   └── png.php / pdf.php    PNG 解码、PDF 处理
├── assets/
│   ├── render.js            渲染 + 分页 + 截图
│   ├── app.js               上传、预览、建单、逐页上传
│   └── vendor/              jszip、pdf.js、docx-preview、xlsx、html2canvas
├── admin/                   后台（概览、订单、预览、打印机、设置）
├── firmware_arduino/
│   └── esp32_ipp_box/       ESP32-S3 打印盒固件（ipp-1.2）
├── tools/                   Word→PDF、Linux 字体安装等可选脚本
├── uploads/                 原件、页面图、PCL 缓存
└── logs/                    设备状态与运行日志
```

---

## 环境要求

**必需**

- PHP 7.4+（推荐 8.1 / 8.2），扩展 `pdo_mysql`、`zlib`
- MySQL 5.7+ / MariaDB 10+
- `uploads/`、`logs/` 可写

**可选（只影响页数是否与 Word 完全一致）**

- Windows 且装了 Word / WPS：`tools/word2pdf.ps1` 用 COM 转 PDF
- Linux 且允许 `exec`：`tools/linux_setup.sh` 装 LibreOffice 与中文字体
- 两者都没有时自动降级为浏览器排版，页数可能差 1~2 页，确认页会标注口径

> 虚拟主机普遍禁 `exec` 且 `open_basedir` 受限，属于后一种情况；服务端不需要 GD / Imagick。

**上传大小**：除 `config.php` 的 `MAX_UPLOAD_MB`，还需放开 Nginx `client_max_body_size` 与站点 `.user.ini`。

---

## 快速部署

```bash
# 1. 放到网站根目录
git clone <仓库地址> /var/www/print
cd /var/www/print

# 2. 配置数据库
cp config.example.php config.php
# 编辑 DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS

# 3. 建表
mysql -u<user> -p<pass> <库名> < install.sql
# 或浏览器打开 /install.php 自动建表

# 4. 权限
chmod -R 777 uploads logs

# 5. 后台
# /admin/login.php  默认 admin / admin888，登录后立刻修改
```

**上线前必须做**

1. 删除 `install.php`
2. 删除 `_diag.php`
3. 修改后台密码与 `agent_token`，并让固件 `DEVICE_TOKEN` 与之相同

---

## 配置说明

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
| `print_target` | `esp32` | 固定 ESP32 通道 |
| `printer_lang` | `escpr` | `escpr` = JPEG/IPP；其他 = PCL5/9100 |
| `pcl_color` | `0` | PCL 是否彩色 |
| `price_bw / price_color` | 0.40 / 0.50 | 单面单价（元/页） |
| `price_bw_duplex / price_color_duplex` | 0.60 / 0.80 | 双面单价（元/张） |
| `min_amount` | 0.10 | 最低消费 |
| `agent_token` | 随机串 | 打印盒接口令牌 |
| `epay_url / epay_pid / epay_key` | — | 易支付参数 |
| `site_domain` | — | 回调域名 |

计费公式：

```
单面：页数 × 份数 × 单价
双面：ceil(页数 / 2) × 份数 × 单价
低于 min_amount 时按 min_amount 收
```

---

## 后台功能

| 页面 | 内容 |
|---|---|
| 概览 | 今日订单 / 收款 / 完成 / 排队；PHP 版本、页面图目录、出纸方式、待打印数 |
| 在线状态 | 打印盒与打印机均为 **15 秒**判据，页面每 **3 秒**局部刷新 |
| 打印机故障 | 卡纸、缺纸、纸盒未装、墨尽、盖板未关、出纸托盘已满等，有故障红字显示 |
| 设备资源 | `CPU 240MHz · 负载 7% · 内存 115KB · PSRAM 8.0MB · 信号 -58dBm · 运行 1h0m`，超 15 秒无上报显示 `—` |
| 打印盒日志 | 设备 IP、固件版本、状态、最近上报时间与串口日志原文 |
| 订单管理 | 固定 6 列表格，每页 20 单；手机折叠为 4 列；状态与关键字筛选、手动重打、删除 |
| 打印预览 | 展示服务器已渲染的页面图（打印机实际收到的内容），可下载原件、查看原图 |

---

## 接口一览

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/create.php` | POST | 建单：`file_name/ext/pages/copies/color/duplex/paper/buyer/phone/dorm` |
| `/api/page.php` | POST | 上传单页：`order_no/index/file`，按语言校验 JPEG 或 PNG 文件头 |
| `/api/status.php` | GET | `?no=订单号` → 状态文本与打印备注 |
| `/api/device_status.php` | GET | 打印盒 / 打印机是否在线 |
| `/api/convert.php` | POST | docx→PDF（无引擎时返回 `no_engine`） |

### 打印盒接口 `/api/esp32.php`

所有请求需带 `&token=<agent_token>`。

| action | 参数 | 说明 |
|---|---|---|
| `poll` | `pr=1` | 领任务；返回 `lang` 与 `job`，打印机离线时 `job=null` |
| `pages` | `id` | 返回 `order_no/dir/pages/color/duplex/files[]` |
| `file` | `id` | 下载整份文件（PCL 通道） |
| `done` / `error` | `id`、`msg` | 回写订单状态与打印备注 |
| `ping` | `pr/state/fault/cpu/load/heap/psram/rssi/up/ip/fw/msg` | 心跳，写状态文件与最后上报时间 |
| `log` | `msg` | 设备日志落盘，超 400KB 只留末 200KB |

**离线闸门**：服务端读 `logs/esp32_state.json`，15 秒内 `printer_online=false` 时 `poll` 返回空任务，不会把订单领走再判失败。

---

## ESP32 打印盒

### 硬件

- ESP32-S3 N16R8（16MB Flash / 8MB PSRAM）
- 支持 IPPS（AirPrint）的打印机，例如 EPSON L4168
- 只需 USB 供电，不接打印机数据线

### Arduino IDE 设置

| 项目 | 值 |
|---|---|
| 开发板 | ESP32S3 Dev Module |
| Flash Size | 16MB |
| **PSRAM** | **OPI PSRAM**（不设会报「内存不够」） |
| Partition Scheme | 16M Flash (3MB APP/9.9MB FATFS) |
| USB Mode | USB-OTG (TinyUSB) |
| USB CDC On Boot | Disabled |
| 串口监视器 | 115200 |

### 需要修改的常量

```cpp
const char *WIFI_SSID     = "你的WiFi";
const char *WIFI_PASSWORD = "你的密码";
const char *SERVER_BASE   = "https://你的域名";   // http 也可，自动跟随跳转
const char *DEVICE_TOKEN  = "后台 agent_token";
const char *PRINTER_HOST  = "192.168.1.101";      // 打印机 IP
const int   LED_PIN       = 48;                   // 不亮可试 38
const int   LED_BRIGHT    = 15;                   // 0~255
```

### 固件行为

1. 每轮探测打印机 `631` 与 `9100`，都连不上只发心跳、不发 `poll`
2. 打印机在线才领任务；逐页下载，优先使用服务端返回的真实文件名
3. 页面优先 `ps_malloc` 到 PSRAM，不够才用内部堆；单页上限 4MB，发完立即释放
4. IPPS 连 `631` 发 IPP Print-Job；每页查询 `job-state`，9 为完成，7/8 判失败，90 秒超时
5. 打印开始、每页开始、等待作业时每 5 秒补心跳并推送日志，避免打印中被判离线
6. 打印语言由 `poll` 返回：`escpr` 走 JPEG/IPP，`pcl5` 走 9100 流式转发
7. 失败原因写入订单 `print_msg`，后台直接显示中文

### 灯效

| 颜色 | 含义 |
|---|---|
| 绿灯常亮 | 打印机在线，待命 |
| 红灯常亮 | 打印机离线 / WiFi 断开，不领任务 |
| 蓝灯 | 正在打印 |

---

## 常见问题

| 现象 | 原因 / 处理 |
|---|---|
| 后台打印盒离线 | 断电、断网、固件卡死，或超过 15 秒未上报；查最后上报时间 |
| 打印机在线但不出纸 | 看「打印机故障」与设备日志 fault 码 |
| 提示「打印机缺纸 / 卡纸」 | 处理后后台「手动重打」 |
| 预览没有页面图 | 检查 `uploads/pages/<订单号>/` 是否存在 |
| 串口 `PSRAM=0` | Arduino IDE 未开 OPI PSRAM |
| `[下载] 内存不够` | PSRAM 未开，或页图超过 4MB |
| 下载报 301 | 站点协议与 `SERVER_BASE` 不一致，固件已支持跟随 |
| 切 PCL 后失败 | 旧订单只有 JPG，或打印机未开 9100 |
| 页数与 Word 差 1~2 页 | 无排版引擎，按浏览器口径，确认页已标注 |
| 后台长期显示旧状态 | 确认已上传新版 `admin/index.php`、`lib/func.php`、`api/esp32.php` |

---

## 已知限制

- 无 Word/WPS/LibreOffice 时页数按浏览器排版，可能与 Word 差 1~2 页
- 超大文档（>100 页）渲染占内存，`MAX_PAGES` 可下调
- 打印盒按单台设备设计，多台需扩展任务分配
- EPSON L4168 只支持 ESC/P-R / JPEG，不支持 PCL
- 仅支持 IPPS 的机型必须用 `https://IP:631`
- CPU 负载为估算值，用于观察趋势

---

## 安全

- `config.php` 含数据库密码
- `uploads/`、`logs/` 为运行产物
- 上线前删除 `install.php`、`_diag.php`，修改后台密码与 `agent_token`
- 打印盒接口只校验 token，建议放内网或加 IP 白名单

---

## 许可

MIT License，转载与商用请保留作者信息。
